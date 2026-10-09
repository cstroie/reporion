<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageExistsException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Breadcrumb;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Plugin\Hooks;
use Reporion\Service\Duplicates;
use Reporion\Service\FrontmatterGuess;
use Reporion\Service\NewReport;
use Reporion\Storage\FlatFile;
use Reporion\Storage\StorageInterface;
use Reporion\Support\DocumentFormat;
use Reporion\Support\ReportPath;
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
 * JavaScript. Anywhere else (`/{ns}/new` outside `reports:`, or `?mode=path`) it
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
        private readonly ?Hooks $hooks = null,
    ) {
    }

    /**
     * GET /new, and GET /{ns}/new — the namespace in the URL, as /{path}/edit
     * has its page (2026-10-01). GET /{report path}/new is a new exam for
     * that report's patient.
     */
    public function form(Request $request, ?User $principal, ?string $ns = null): Response
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

        // /{report path}/new: a new exam for the patient of that report (phase 9;
        // the path in the URL since 2026-10-09, as /{path}/edit and /{path}/timeline
        // have it — invariant 8 keeps it out of external URLs, not the app's own).
        // A report path is never a namespace: one the caller cannot read is a 404,
        // not a new-page form in a namespace that does not exist
        if ($ns !== null && ReportPath::isReport(trim($ns, ': '))) {
            $after = trim($ns, ': ');
            if ($this->index->findByPath($after, $principal) === null || !$this->guided($principal, 'reports', $request)) {
                throw new PageNotFoundException();
            }
            \assert($this->newReport !== null);

            return $this->renderGuided($request, $principal, $this->newReport->draft($this->newReport->prefill($this->storage->read($after)), $principal), fresh: true);
        }

        // ?prefill={source}&ref={ref}: the guided form filled by a plugin
        // (hook report.prefill) — e.g. an exam picked from a HIS worklist.
        // A reference, never the patient's name, travels in the URL (D1)
        $source = \is_string($request->query['prefill'] ?? null) ? $request->query['prefill'] : '';
        if ($source !== '' && $this->hooks !== null && $this->guided($principal, 'reports', $request)) {
            $ref = \is_string($request->query['ref'] ?? null) ? $request->query['ref'] : '';
            $fields = $this->hooks->first('report.prefill', $source, $ref, $principal);
            if (!\is_array($fields)) {
                throw new PageNotFoundException();
            }
            \assert($this->newReport !== null);

            return $this->renderGuided($request, $principal, $this->newReport->draft($fields, $principal), fresh: true);
        }

        $ns = trim($ns ?? '', ': ');
        if ($this->guided($principal, $ns, $request)) {
            return $this->renderGuided($request, $principal, $this->newReport->draft(self::prefill($ns, $this->newReport->options($principal)), $principal), fresh: true, ns: $ns === '' ? 'reports' : $ns);
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

        // The form's own prefill — the empty scaffold, or a ?from= copy the
        // user has not seen yet — is not what they wrote: the editor opens on
        // the new path and their first Save is revision 1 (decided 2026-09-27)
        $from = \is_string($fields['from'] ?? null) ? trim($fields['from']) : '';
        if ($document === self::SCAFFOLD || $from !== '') {
            if (!FlatFile::isValidPath($path)) {
                return $this->render($request, $principal, error: t('new.err_invalid_path'), path: $path, document: $document, segments: $segments);
            }
            if ($this->index->findByPath($path, $principal) !== null) {
                return $this->render($request, $principal, error: t('new.err_exists'), path: $path, document: $document, segments: $segments, existingPath: $path);
            }

            return Response::redirect($request->basePath . '/' . $path . '/edit' . ($from !== '' ? '?from=' . rawurlencode($from) : ''));
        }

        try {
            [$frontmatter, $body] = DocumentFormat::parseOrBare($document);
        } catch (RuntimeException | ParseException $e) {
            return $this->render($request, $principal, error: t('editor.err_parse', [$e->getMessage()]), path: $path, document: $document, segments: $segments);
        }
        // No frontmatter typed: what the page itself says (decided 2026-09-27)
        $frontmatter ??= FrontmatterGuess::forNewPage($path, $body, $this->newReport?->modalityNamespaces() ?? []);

        try {
            // Exclusive: a page there is said so, with a link to it — never a quiet `{path}-2`
            $record = $this->storage->create($path, $frontmatter, $body, $principal->username, exclusive: true);
        } catch (PageExistsException) {
            return $this->render($request, $principal, error: t('new.err_exists'), path: $path, document: $document, segments: $segments, existingPath: $path);
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
     * The guided form's submit: anything but "create" recomputes and shows the path,
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
    private function renderGuided(Request $request, User $principal, array $draft, int $status = 200, bool $fresh = false, bool $needsConfirm = false, string $ns = 'reports'): Response
    {
        \assert($this->newReport !== null);

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/new-report.php',
            [
                'draft' => $draft,
                // The tab title names the patient once the form has one (a follow-up: at once)
                'pageSubject' => (string) ($draft['values']['name'] ?? ''),
                // A first visit shows no "required" complaints yet
                'errors' => $fresh ? [] : $draft['errors'],
                'options' => $this->newReport->options($principal),
                'needsConfirm' => $needsConfirm,
                'crumbs' => self::crumbs($request->basePath, $ns . ':', null, t('newr.title')),
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
        if ($this->newReport === null || ($request->query['mode'] ?? null) === 'path' || ($ns !== '' && !ReportPath::isReportNamespace($ns))) {
            return false;
        }
        return NewReport::canCreateReports($principal);
    }

    /**
     * Today's date, and modality and site from /reports:mri:mioveni/new.
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
    private function render(Request $request, ?User $principal, ?string $error, string $path, string $document, ?array $segments, ?string $duplicateOf = null, ?string $existingPath = null): Response
    {
        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/new.php',
            [
                'error' => $error,
                'path' => $path,
                'document' => $document,
                'segments' => $segments,
                'duplicateOf' => $duplicateOf,
                'existingPath' => $existingPath,
                'duplicateIsReport' => $duplicateOf !== null && ReportPath::isReport($duplicateOf),
                'crumbs' => self::crumbs($request->basePath, $path, $duplicateOf, t('new.title')),
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, ''),
            t('new.title'),
        ));
    }

    /**
     * The trail above the form: where the new page goes (its namespace), or —
     * for a copy — the page it starts from, then what this screen is.
     *
     * @return list<array{label: string, href?: ?string}>
     */
    private static function crumbs(string $basePath, string $path, ?string $duplicateOf, string $label): array
    {
        if ($duplicateOf !== null) {
            $trail = Breadcrumb::namespaceTrail($basePath, ChromeVars::namespaceOf($duplicateOf));
            $trail[] = ['label' => ReportPath::leaf($duplicateOf), 'href' => $basePath . '/' . $duplicateOf];

            return [...$trail, ['label' => t('page.duplicate')]];
        }
        // "ns:" is a namespace; anything else is a page, whose namespace is above it
        $ns = str_ends_with($path, ':') ? trim($path, ':') : ChromeVars::namespaceOf($path);

        return [...Breadcrumb::namespaceTrail($basePath, $ns), ['label' => $label]];
    }

    /**
     * Builder prefill for a namespace under `reports:` (at most modality and
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
