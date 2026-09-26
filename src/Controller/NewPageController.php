<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Duplicates;
use Reporion\Service\NewReport;
use Reporion\Storage\StorageInterface;
use Reporion\Support\DocumentFormat;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * GET/POST /new — the create half of the write UI (`Controller\EditorController`
 * is the edit half). Same raw-document textarea as the editor, for the same
 * reason: no per-field form to silently drop whatever it doesn't show.
 * Prefilled with a minimal scaffold, not empty — `Support\DocumentFormat::parse()`
 * (mirroring `Storage\FlatFile`'s own regex) requires at least one line
 * inside the frontmatter fences, so a genuinely empty block would fail to
 * parse the moment someone submitted it unchanged.
 *
 * Two ways to name the page. Under `reports:` (and by default) it is the
 * mockup's segmented `reports:{modality}:{site}:{yymmdd}-{name}` builder —
 * four plain inputs the server assembles, so creation works without
 * JavaScript. Anywhere else (`?ns=` outside `reports:`, or `?mode=path`) it
 * is one text field for the whole colon path, because the builder's fixed
 * shape would silently re-root the page under `reports:`.
 */
final class NewPageController
{
    private const SCAFFOLD = "---\ntitle: \nvisibility: private\n---\n\n";

    /** The builder's inputs, in path order. */
    private const SEGMENTS = ['modality', 'site', 'date', 'name'];

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
        private readonly ?NewReport $newReport = null,
    ) {
    }

    public function form(Request $request, ?User $principal): Response
    {
        if ($principal === null || !$principal->hasAnyWriteAccess()) {
            throw new PageNotFoundException();
        }

        // ?from= starts from a copy of a page the caller can read — body and
        // exam fields, not patient fields (Service\Duplicates)
        $from = \is_string($request->query['from'] ?? null) ? trim($request->query['from'], ': ') : '';
        if ($from !== '') {
            if ($this->index->findByPath($from, $principal) === null) {
                throw new PageNotFoundException();
            }
            [$frontmatter, $body] = Duplicates::document($this->storage->read($from));
            $ns = ChromeVars::namespaceOf($from);

            return $this->render(
                $request,
                $principal,
                error: null,
                path: $ns . ':',
                document: DocumentFormat::encode($frontmatter, $body),
                segments: self::segmentsFromNamespace($ns),
                duplicateOf: $from,
            );
        }

        // ?path= prefills an exact page path (the 404 page's "Create this page")
        $exact = \is_string($request->query['path'] ?? null) ? trim($request->query['path'], ': ') : '';
        if ($exact !== '') {
            return $this->render($request, $principal, error: null, path: $exact, document: self::SCAFFOLD, segments: null);
        }

        $ns = \is_string($request->query['ns'] ?? null) ? trim($request->query['ns'], ': ') : '';
        if ($this->guided($principal, $ns, $request)) {
            return $this->renderGuided($request, $principal, $this->newReport->draft(self::prefill($ns, $this->newReport->options($principal)), $principal), fresh: true);
        }
        $segments = ($request->query['mode'] ?? null) === 'path' ? null : self::segmentsFromNamespace($ns);
        $path = $ns === '' ? '' : $ns . ':';

        return $this->render($request, $principal, error: null, path: $path, document: self::SCAFFOLD, segments: $segments);
    }

    public function create(Request $request, ?User $principal): Response
    {
        // Same coarse-then-specific ordering as PagesApiController::create()
        // and for the same reason: the target namespace lives in a form
        // field, not a route parameter, so there is nothing to run
        // canWrite() against until $path is known to be non-empty.
        if ($principal === null || !$principal->hasAnyWriteAccess()) {
            throw new PageNotFoundException();
        }

        parse_str($request->body, $fields);
        if (($fields['guided'] ?? null) === '1' && $this->newReport !== null) {
            return $this->createGuided($request, $principal, $fields);
        }
        $document = \is_string($fields['document'] ?? null) ? $fields['document'] : self::SCAFFOLD;
        $segments = null;
        if (($fields['builder'] ?? null) === '1') {
            $segments = [];
            foreach (self::SEGMENTS as $name) {
                $segments[$name] = \is_string($fields[$name] ?? null) ? trim($fields[$name]) : '';
            }
            if (\in_array('', [$segments['modality'], $segments['site'], $segments['date'], $segments['name']], true)) {
                return $this->render($request, $principal, error: t('new.err_segments'), path: '', document: $document, segments: $segments);
            }
            $path = 'reports:' . $segments['modality'] . ':' . $segments['site'] . ':' . $segments['date'] . '-' . $segments['name'];
        } else {
            $path = \is_string($fields['path'] ?? null) ? trim($fields['path']) : '';
        }

        if ($path === '') {
            return $this->render($request, $principal, error: t('new.err_path_required'), path: $path, document: $document, segments: $segments);
        }
        if (!$principal->canWrite($path)) {
            throw new PageNotFoundException();
        }

        try {
            [$frontmatter, $body] = DocumentFormat::parse($document);
        } catch (RuntimeException | ParseException $e) {
            return $this->render($request, $principal, error: t('editor.err_parse', [$e->getMessage()]), path: $path, document: $document, segments: $segments);
        }

        try {
            $record = $this->storage->create($path, $frontmatter, $body, $principal->username);
        } catch (InvalidArgumentException) {
            return $this->render($request, $principal, error: t('new.err_invalid_path'), path: $path, document: $document, segments: $segments);
        }
        $this->audit->record('page.create', $principal->username, $request, $record->pid, $record->path, $record->rev);

        // Redirect to the path Storage actually allocated, never the
        // submitted one: create() appends -2/-3 on a collision
        // (docs/FORMATS.md §1) and returns the real path — redirecting to
        // $path here would silently 404 the moment a collision happened.
        return Response::redirect($request->basePath . '/' . $record->path . '/edit');
    }

    /**
     * The guided form's submit: "preview" recomputes and shows the path,
     * the next accession and what the CNP says; "create" also allocates the
     * accession and creates the page — after an explicit confirm when the
     * patient already has a report on that date (docs/FORMATS.md §1).
     *
     * @param array<string, mixed> $fields
     */
    private function createGuided(Request $request, User $principal, array $fields): Response
    {
        \assert($this->newReport !== null);
        $draft = $this->newReport->draft($fields, $principal);
        if (($fields['action'] ?? '') !== 'create') {
            return $this->renderGuided($request, $principal, $draft);
        }
        if ($draft['path'] !== null && !$principal->canWrite($draft['path'])) {
            $draft['errors']['site'] = t('newr.err.no_access');
        }
        if ($draft['errors'] !== []) {
            return $this->renderGuided($request, $principal, $draft, status: 422);
        }
        if ($draft['sameDay'] !== [] && ($fields['confirm_same_day'] ?? '') !== '1') {
            return $this->renderGuided($request, $principal, $draft, needsConfirm: true);
        }

        $record = $this->newReport->create($draft, $principal->username);
        $this->audit->record('page.create', $principal->username, $request, $record->pid, $record->path, $record->rev);

        // The path Storage allocated — -2/-3 on a collision (FORMATS §1)
        return Response::redirect($request->basePath . '/' . $record->path . '/edit');
    }

    /**
     * @param array<string, mixed> $draft NewReport::draft()
     */
    private function renderGuided(Request $request, User $principal, array $draft, int $status = 200, bool $fresh = false, bool $needsConfirm = false): Response
    {
        \assert($this->newReport !== null);

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/new-report.php',
            [
                'draft' => $draft,
                // A first visit shows no "required" complaints yet
                'errors' => $fresh ? [] : $draft['errors'],
                'options' => $this->newReport->options($principal),
                'needsConfirm' => $needsConfirm,
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, 'reports'),
            t('newr.title'),
        ), $status);
    }

    /**
     * The guided form is for reports: the owner, or an editor somewhere
     * under reports:, creating without ?mode=path, anywhere under reports:.
     */
    private function guided(User $principal, string $ns, Request $request): bool
    {
        if ($this->newReport === null || ($request->query['mode'] ?? null) === 'path' || ($ns !== '' && $ns !== 'reports' && !str_starts_with($ns, 'reports:'))) {
            return false;
        }
        if ($principal->isOwner) {
            return true;
        }
        foreach ($principal->grants as $grant) {
            if ($grant->role === GrantRole::Editor && ($grant->namespace === 'reports' || str_starts_with($grant->namespace, 'reports:'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Today's date, and modality and site from ?ns=reports:mri:mioveni.
     *
     * @param array{modalities: array<string, string>} $options
     *
     * @return array<string, string>
     */
    private static function prefill(string $ns, array $options): array
    {
        $parts = explode(':', $ns);

        return [
            'date' => date('Y-m-d'),
            'modality' => (string) (array_search($parts[1] ?? '', $options['modalities'], true) ?: ''),
            'site' => $parts[2] ?? '',
        ];
    }

    /**
     * @param array<string, string>|null $segments the builder's inputs, or null for the plain path field
     */
    private function render(Request $request, ?User $principal, ?string $error, string $path, string $document, ?array $segments, ?string $duplicateOf = null): Response
    {
        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/new.php',
            [
                'error' => $error,
                'path' => $path,
                'document' => $document,
                'segments' => $segments,
                'duplicateOf' => $duplicateOf,
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, ''),
            t('new.title'),
        ));
    }

    /**
     * Builder prefill for a `?ns=` under `reports:` (at most modality and
     * site deep); null — the plain path field — for any other namespace.
     *
     * @return array<string, string>|null
     */
    private static function segmentsFromNamespace(string $ns): ?array
    {
        $parts = $ns === '' ? [] : explode(':', $ns);
        if ($parts !== [] && array_shift($parts) !== 'reports') {
            return null;
        }
        if (\count($parts) > 2) {
            return null;
        }

        return ['modality' => $parts[0] ?? '', 'site' => $parts[1] ?? '', 'date' => '', 'name' => ''];
    }
}
