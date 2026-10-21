<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\NotRadiologistException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Audit\AuditLog;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Signing;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ConclusionSummary;
use Reporion\Support\MetaText;
use Reporion\Support\Rads;
use Reporion\Support\ReportPath;
use Reporion\Support\WritingChecks;

/**
 * The page's Sign button (GET/POST /{path}/sign): a confirmation step
 * naming the revision being signed and any required field still empty,
 * then the same Service\Signing call as POST /api/v1/pages/{path}/sign.
 * D37: whoever may write the page signs it as themselves. Reports only
 * other pages are never signed (Support\ReportPath). Anything the caller
 * cannot write is a 404 (invariant 9).
 */
final class SignController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly Signing $signing,
        // Phase 34a: the pre-sign check: the assistant's part needs the reserved `presign` prompt
        private readonly ?Actions $aiActions = null,
        private readonly ?AuditLog $audit = null,
    ) {
    }

    /** GET /{path}/sign */
    public function form(Request $request, string $path, ?User $principal): Response
    {
        $record = $this->signable($path, $principal);
        if (Signing::isSigned($record)) {
            return Response::redirect($request->basePath . '/' . $record->path);
        }

        return $this->render($request, $record, $principal, stale: false, parafa: mb_substr($principal->stamp, 0, 32), status: 200);
    }

    /**
     * POST /{path}/sign { base_rev, parafa? }. base_rev is the revision the
     * signer looked at: if the page moved on since, nothing is signed and
     * the form comes back naming the new revision: a signature always
     * covers exactly what was reviewed.
     */
    public function sign(Request $request, string $path, ?User $principal): Response
    {
        $record = $this->signable($path, $principal);
        \assert($principal !== null);

        parse_str($request->body, $fields);
        $parafa = \is_string($fields['parafa'] ?? null) ? mb_substr(trim($fields['parafa']), 0, 32) : '';
        $baseRev = \is_string($fields['base_rev'] ?? null) && ctype_digit($fields['base_rev']) ? (int) $fields['base_rev'] : 0;

        if ($baseRev !== $record->rev) {
            return $this->render($request, $record, $principal, stale: true, parafa: $parafa, status: 409);
        }
        if (Signing::isSigned($record)) {
            return Response::redirect($request->basePath . '/' . $record->path);
        }
        if ($this->signing->missing($record) !== []) {
            return $this->render($request, $record, $principal, stale: false, parafa: $parafa, status: 422);
        }

        $signed = $this->signing->sign($record, $principal, $parafa, $request);
        // What the pre-sign check showed the signer (phase 34a): counts, never the words
        $ai = \is_string($fields['presign_ai'] ?? null) && ctype_digit($fields['presign_ai']) ? (int) $fields['presign_ai'] : null;
        $this->audit?->record('page.presign', $principal->username, $request, $signed->pid, $signed->path, $signed->rev, extra: array_filter([
            'rules' => \count(WritingChecks::check($record->body, $record->frontmatter)),
            'ai' => $ai,
        ], static fn (?int $n): bool => $n !== null));

        return Response::redirect($request->basePath . '/' . $record->path);
    }

    private function signable(string $path, ?User $principal): PageRecord
    {
        if ($principal === null || !ReportPath::isReport($path) || $this->index->findByPath($path, $principal) === null) {
            throw new PageNotFoundException();
        }
        // Seen, but not theirs to sign: an owner who is not a radiologist (D37 amended, D47): a 403
        if (!$principal->isRadiologist()) {
            throw new NotRadiologistException();
        }
        if (!$principal->canWrite($path)) {
            throw new PageNotFoundException();
        }

        return $this->storage->read($path);
    }

    private function render(Request $request, PageRecord $record, User $principal, bool $stale, string $parafa, int $status): Response
    {
        $indexed = $this->index->findByPath($record->path, $principal);
        if ($indexed === null) {
            throw new PageNotFoundException();
        }
        $missing = $this->signing->missing($record);

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/page-sign.php',
            [
                'path' => $record->path,
                'rev' => $record->rev,
                'missing' => $missing,
                // Phase 34a, D51: warnings, never a block
                'checks' => WritingChecks::check($record->body, $record->frontmatter),
                'presign' => $this->aiActions?->special($record->path, 'presign') !== null,
                // The summary and tags panel: what the report has, the assistant's reach, the rules' fallback
                'summary' => MetaText::text($record->frontmatter['summary'] ?? null),
                'tags' => array_values(array_filter(array_map(static fn (mixed $t): string => MetaText::text($t), (array) ($record->frontmatter['tags'] ?? [])), static fn (string $t): bool => $t !== '')),
                'aiSummary' => $this->aiActions?->special($record->path, 'summary') !== null,
                'aiTags' => $this->aiActions?->special($record->path, 'tags') !== null,
                'ruleSummary' => ConclusionSummary::extract($record->body) ?? '',
                'ruleTags' => Rads::tags($record->body),
                'stale' => $stale,
                'parafa' => $parafa,
                'signerName' => $principal->signatureName(),
                'signerTitle' => $principal->title,
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($record->path))
              + ChromeVars::pageHeaderFromRow($indexed, $principal, 'sign'),
            t('sign.title'),
        ), $status);
    }
}
