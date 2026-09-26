<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Signing;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ReportPath;

/**
 * The page's Sign button (GET/POST /{path}/sign): a confirmation step
 * naming the revision being signed and any required field still empty,
 * then the same Service\Signing call as POST /api/v1/pages/{path}/sign.
 * D37: whoever may write the page signs it as themselves. Reports only —
 * other pages are never signed (Support\ReportPath). Anything the caller
 * cannot write is a 404 (invariant 9).
 */
final class SignController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly Signing $signing,
    ) {
    }

    /** GET /{path}/sign */
    public function form(Request $request, string $path, ?User $principal): Response
    {
        $record = $this->signable($path, $principal);
        if (Signing::isSigned($record)) {
            return Response::redirect($request->basePath . '/' . $record->path);
        }

        return $this->render($request, $record, $principal, stale: false, parafa: '', status: 200);
    }

    /**
     * POST /{path}/sign { base_rev, parafa? }. base_rev is the revision the
     * signer looked at: if the page moved on since, nothing is signed and
     * the form comes back naming the new revision — a signature always
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

        $this->signing->sign($record, $principal, $parafa, $request);

        return Response::redirect($request->basePath . '/' . $record->path);
    }

    private function signable(string $path, ?User $principal): PageRecord
    {
        if ($principal === null || !$principal->canWrite($path) || !ReportPath::isReport($path)
            || $this->index->findByPath($path, $principal) === null) {
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
