<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\EgressGuard;
use Reporion\Service\Duplicates;
use Reporion\Service\ExamAccessions;
use Reporion\Service\Checklists;
use Reporion\Service\References;
use Reporion\Service\FrontmatterFields;
use Reporion\Service\FrontmatterGuess;
use Reporion\Service\PatientStudies;
use Reporion\Service\Publishing;
use Reporion\Service\Snippets;
use Reporion\Storage\FlatFile;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ConclusionSummary;
use Reporion\Support\DocumentFormat;
use Reporion\Support\MetaText;
use Reporion\Support\ReportPath;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * GET/POST /{path}/edit — the write UI this project has been missing:
 * before this route existed, the only way to create or edit a page's
 * content was a raw call to `POST/PUT /api/v1/pages`. Classic SSR form,
 * no JavaScript — the same shape `AdminUsersController`/`RevisionsController`
 * already established.
 *
 * **Two modes (roadmap phase 14, TODO.md idea 11), same route:**
 * - **Curated (the default)**: a body-only textarea (`name="body"`) plus a
 *   Details panel (`Service\FrontmatterFields`) of native form fields for
 *   the frontmatter — one Save, one revision, no raw YAML shown. A field
 *   with no picker is listed read only, with a link to raw mode, never a
 *   second YAML box. A submitted body that starts with `---` (a whole
 *   document pasted in) is refused with a message, never silently stored.
 * - **Raw (`?raw=1`, or always for a multi-exam report — `exams:` is the
 *   exam tabs' own concern, phase 12, untouched by this phase)**: the
 *   whole `---\nfrontmatter\n---\n\nbody` file in one textarea
 *   (`name="document"`), exactly as this editor worked before phase 14.
 *   `isRawMode()` decides, from the request and the page's own
 *   frontmatter — never a client-declared mode, so a multi-exam report
 *   can never be edited in the mode that would corrupt its `exams:` list.
 *
 * Either way, the frontmatter write is a **merge**, never a wholesale
 * replace of what raw mode does not also come with: `Publishing::merge()`
 * only touches a key the caller actually mentions. Raw mode's own
 * "whatever the page already had round-trips through the textarea"
 * property is a stronger, simpler version of the same guarantee — it is
 * still there for a field the curated fields do not have a picker for.
 *
 * Deliberately scoped, not an oversight:
 * - **No marked.js live preview change, no autosave change, no IndexedDB
 *   draft-shape change beyond what phase 14 needed.** Independently useful
 *   follow-ups (docs/BUILD_LOG.md) are unaffected.
 * - **`GET /new` is `Controller\NewPageController`, a separate controller**,
 *   not a mode of this one — creating a page needs a path the user
 *   supplies, `POST` not `PUT`, and there is no existing document to
 *   round-trip. They share `Support\DocumentFormat` for the
 *   encode/parse step, nothing else.
 * - **A conflict without JavaScript** doesn't lose the editor's typed
 *   text, in either mode: `RevisionConflictException` re-renders the same
 *   form with exactly what they submitted still there, the server's
 *   current document shown read-only alongside it for comparison, and
 *   `base_rev` advanced to the current revision so a deliberate resubmit
 *   (after they've reconciled by hand) succeeds.
 */
final class EditorController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
        private readonly PatientStudies $studies,
        private readonly Snippets $snippets,
        private readonly ExamAccessions $examAccessions,
        private readonly FrontmatterFields $fields,
        private readonly ?Actions $aiActions = null,
        private readonly ?AiConfig $aiConfig = null,
        private readonly ?Checklists $checklists = null,
        private readonly ?References $references = null,
    ) {
    }

    public function edit(Request $request, string $path, ?User $principal): Response
    {
        // Read access resolved through the index query, not re-derived
        // from canWrite() alone — the same predicate every other read
        // route uses (invariant 6: "resolved in the query, not after it").
        // canWrite() happens to imply read access for every grant shape
        // that exists today, but this keeps that one query the single
        // source of truth instead of a second, parallel path that could
        // silently drift from it.
        if ($principal === null) {
            throw new PageNotFoundException();
        }
        // A page not written yet (decided 2026-09-27): the editor opens on
        // its path and the first Save creates it, so revision 1 is what the
        // user wrote — never an empty scaffold
        if ($this->index->findByPath($path, $principal) === null) {
            if (!$this->mayCreate($path, $principal)) {
                throw new PageNotFoundException();
            }
            if ($this->isRawMode($request, [])) {
                return $this->renderNew($request, $path, $this->starter($request, $path, $principal), null, $principal);
            }
            [$frontmatter, $body] = DocumentFormat::parseOrBare($this->starter($request, $path, $principal));
            $frontmatter ??= FrontmatterGuess::forNewPage($path, $body);

            return $this->renderNewCurated($request, $path, $body, $frontmatter, null, $principal);
        }
        if (!$principal->canWrite($path)) {
            throw new PageNotFoundException();
        }

        try {
            $record = $this->storage->read($path);
        } catch (PageNotFoundException) {
            throw new PageNotFoundException();
        }

        if ($this->isRawMode($request, $record->frontmatter)) {
            return $this->render($request, $record, error: null, document: DocumentFormat::encode($record->frontmatter, $record->body), conflictDocument: null, principal: $principal);
        }

        return $this->renderCurated($request, $record, error: null, body: $record->body, frontmatter: $record->frontmatter, conflictDocument: null, principal: $principal);
    }

    /**
     * Raw mode: `?raw=1` (a link the template carries into its form's own
     * `action`, so the choice survives the POST — `Http\Request::$query`
     * is `$_GET` regardless of method), or a multi-exam report always —
     * `exams:` is the exam tabs' own concern (phase 12), and this phase
     * does not touch it. Never a client-declared mode on its own: the
     * `exams:` check is the server's, from the page's own frontmatter.
     *
     * @param array<string, mixed> $frontmatter
     */
    private function isRawMode(Request $request, array $frontmatter): bool
    {
        return ($request->query['raw'] ?? null) === '1' || isset($frontmatter['exams']);
    }

    /**
     * The raw/curated toggle at the end of the editor's crumbs line (phase
     * 14; it sat in the page header until the editor went full-bleed):
     * "Raw edit" from the normal editor, "Normal edit" from
     * raw mode.
     *
     * @return array{href: string, label: string}
     */
    private function rawLinkFor(Request $request, string $path, bool $raw): array
    {
        return $raw
            ? ['href' => $request->basePath . '/' . $path . '/edit', 'label' => t('details.curated_link')]
            : ['href' => $request->basePath . '/' . $path . '/edit?raw=1', 'label' => t('details.raw_link')];
    }

    /** A submitted body that is actually a whole document (frontmatter and all) pasted in */
    private function looksLikeWholeDocument(string $body): bool
    {
        return preg_match('/^\s*---(\r\n|\r|\n|$)/', $body) === 1;
    }

    /** The first Save of a page not written yet: it creates it (base_rev 0) */
    private function create(Request $request, string $path, User $principal, array $fields): Response
    {
        $note = \is_string($fields['note'] ?? null) ? trim($fields['note']) : '';

        if ($this->isRawMode($request, [])) {
            $document = \is_string($fields['document'] ?? null) ? $fields['document'] : '';
            try {
                [$frontmatter, $body] = DocumentFormat::parseOrBare($document);
            } catch (RuntimeException | ParseException $e) {
                return $this->renderNew($request, $path, $document, t('editor.err_parse', [$e->getMessage()]), $principal);
            }
            $frontmatter ??= FrontmatterGuess::forNewPage($path, $body);
        } else {
            $body = \is_string($fields['body'] ?? null) ? $fields['body'] : '';
            $starter = FrontmatterGuess::forNewPage($path, $body);
            if ($this->looksLikeWholeDocument($body)) {
                return $this->renderNewCurated($request, $path, $body, $starter, t('details.err_body_looks_like_document'), $principal);
            }
            $fm = \is_array($fields['fm'] ?? null) ? $fields['fm'] : [];
            $shown = \is_array($fields['fm_shown'] ?? null) ? array_map('strval', $fields['fm_shown']) : [];
            $frontmatter = Publishing::merge($starter, $this->fields->changesFrom($fm, $shown, [], $path));
        }

        $frontmatter = $this->examAccessions->fill($path, $frontmatter);
        $frontmatter = ConclusionSummary::fill($path, $frontmatter, $body);
        $record = $this->storage->create($path, $frontmatter, $body, $principal->username, $note !== '' ? $note : null);
        $this->audit->record('page.create', $principal->username, $request, $record->pid, $record->path, $record->rev);

        return Response::redirect($request->basePath . '/' . $record->path);
    }

    /** A page may be created at $path by $principal: a valid path, theirs to write, nothing there yet */
    private function mayCreate(string $path, User $principal): bool
    {
        if (!FlatFile::isValidPath($path) || !$principal->canWrite($path)) {
            return false;
        }
        try {
            $this->storage->read($path);

            return false;
        } catch (PageNotFoundException) {
            return true;
        }
    }

    /**
     * What a new page's editor starts from: a copy of ?from= (a page the
     * caller can read, as /new?from= offers it — Service\Duplicates), else
     * the frontmatter its path already says (Service\FrontmatterGuess).
     */
    private function starter(Request $request, string $path, User $principal): string
    {
        $from = \is_string($request->query['from'] ?? null) ? trim($request->query['from'], ': ') : '';
        if ($from !== '' && $this->index->findByPath($from, $principal) !== null) {
            [$frontmatter, $body] = Duplicates::document($this->storage->read($from));

            return DocumentFormat::encode($frontmatter, $body);
        }

        return DocumentFormat::encode(FrontmatterGuess::forNewPage($path, ''), '');
    }

    private function renderNew(Request $request, string $path, string $document, ?string $error, User $principal): Response
    {
        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/editor.php',
            [
                'path' => $path,
                'baseRev' => 0,
                'newPage' => true,
                'raw' => true,
                'rawLink' => $this->rawLinkFor($request, $path, true),
                'error' => $error,
                'document' => $document,
                'body' => null,
                'details' => null,
                'conflictDocument' => null,
                'basePath' => $request->basePath,
                'priorCandidates' => [],
                'templates' => $this->fields->templatesFor($path, $principal),
                'template' => '',
                'snippets' => $this->snippets->forPage($path, $principal),
                'editorShell' => true,
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($path)),
            t('editor.new_title', [$path]),
        ), $error !== null ? 422 : 200);
    }

    /** @param array<string, mixed> $frontmatter what the Details panel's fields are populated from */
    private function renderNewCurated(Request $request, string $path, string $body, array $frontmatter, ?string $error, User $principal): Response
    {
        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/editor.php',
            [
                'path' => $path,
                'baseRev' => 0,
                'newPage' => true,
                'raw' => false,
                'rawLink' => $this->rawLinkFor($request, $path, false),
                'error' => $error,
                'document' => null,
                'body' => $body,
                'details' => $this->fields->forPage($path, $frontmatter, $principal),
                'conflictDocument' => null,
                'basePath' => $request->basePath,
                'priorCandidates' => [],
                'templates' => $this->fields->templatesFor($path, $principal),
                'template' => MetaText::text($frontmatter['template'] ?? null),
                'snippets' => $this->snippets->forPage($path, $principal),
                'editorShell' => true,
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($path)),
            t('editor.new_title', [$path]),
        ), $error !== null ? 422 : 200);
    }

    public function save(Request $request, string $path, ?User $principal): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }
        if ($this->index->findByPath($path, $principal) === null) {
            parse_str($request->body, $first);
            if (($first['base_rev'] ?? null) !== '0' || !$this->mayCreate($path, $principal)) {
                throw new PageNotFoundException();
            }

            return $this->create($request, $path, $principal, $first);
        }
        if (!$principal->canWrite($path)) {
            throw new PageNotFoundException();
        }

        try {
            $record = $this->storage->read($path);
        } catch (PageNotFoundException) {
            throw new PageNotFoundException();
        }

        parse_str($request->body, $fields);
        $baseRev = isset($fields['base_rev']) && ctype_digit((string) $fields['base_rev']) ? (int) $fields['base_rev'] : null;
        $note = \is_string($fields['note'] ?? null) ? trim($fields['note']) : '';
        $minor = ($fields['minor'] ?? null) === '1';
        if ($baseRev === null) {
            throw new PageNotFoundException();
        }

        $rawMode = $this->isRawMode($request, $record->frontmatter);
        $document = null;
        if ($rawMode) {
            $document = \is_string($fields['document'] ?? null) ? $fields['document'] : '';
            try {
                [$frontmatter, $body] = DocumentFormat::parseOrBare($document);
            } catch (RuntimeException | ParseException $e) {
                return $this->render($request, $record, error: t('editor.err_parse', [$e->getMessage()]), document: $document, conflictDocument: null, principal: $principal);
            }
            // The frontmatter left out: the page keeps the one it has, never an empty one
            $frontmatter ??= $record->frontmatter;
        } else {
            $body = \is_string($fields['body'] ?? null) ? $fields['body'] : '';
            if ($this->looksLikeWholeDocument($body)) {
                return $this->renderCurated($request, $record, error: t('details.err_body_looks_like_document'), body: $body, frontmatter: $record->frontmatter, conflictDocument: null, principal: $principal);
            }
            $fm = \is_array($fields['fm'] ?? null) ? $fields['fm'] : [];
            $shown = \is_array($fields['fm_shown'] ?? null) ? array_map('strval', $fields['fm_shown']) : [];
            $frontmatter = Publishing::merge($record->frontmatter, $this->fields->changesFrom($fm, $shown, $record->frontmatter, $path));
        }

        // An exam added in the editor gets its accession now (phase 12, D20)
        $frontmatter = $this->examAccessions->fill($path, $frontmatter);
        $frontmatter = ConclusionSummary::fill($path, $frontmatter, $body);
        // What the assistant proposed and the doctor applied (phase 15, D8 as
        // amended): the revision is theirs, the note and the audit say so
        $assisted = array_values(array_unique(array_filter(
            explode(',', \is_string($fields['ai_assisted'] ?? null) ? $fields['ai_assisted'] : ''),
            static fn (string $id): bool => preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $id) === 1,
        )));
        if ($assisted !== []) {
            $note = trim($note . ($note !== '' ? ' · ' : '') . t('editor.ai_note', [implode(', ', $assisted)]));
        }
        try {
            $saved = $this->storage->save($path, $frontmatter, $body, $baseRev, $principal->username, $note !== '' ? $note : null, minor: $minor);
            $this->audit->record('page.save', $principal->username, $request, $saved->pid, $saved->path, $saved->rev, extra: $assisted !== [] ? ['assisted' => $assisted] : []);
        } catch (RevisionConflictException $e) {
            $conflictDocument = DocumentFormat::encode($e->current->frontmatter, $e->current->body);

            return $rawMode
                ? $this->render($request, $e->current, error: t('editor.err_conflict'), document: $document ?? '', conflictDocument: $conflictDocument, principal: $principal)
                : $this->renderCurated($request, $e->current, error: t('editor.err_conflict'), body: $body, frontmatter: $frontmatter, conflictDocument: $conflictDocument, principal: $principal);
        }

        return Response::redirect($request->basePath . '/' . $path);
    }

    private function render(Request $request, PageRecord $record, ?string $error, string $document, ?string $conflictDocument, ?User $principal): Response
    {
        $indexed = $this->index->findByPath($record->path, $principal);
        if ($indexed === null) {
            throw new PageNotFoundException();
        }

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/editor.php',
            [
                'path' => $record->path,
                'baseRev' => $record->rev,
                'signed' => $record->status === 'signed',
                'status' => $record->status,
                'raw' => true,
                'error' => $error,
                'document' => $document,
                'body' => null,
                'details' => null,
                'conflictDocument' => $conflictDocument,
                'basePath' => $request->basePath,
                'priorCandidates' => $this->priorCandidates($record, $indexed, $principal),
                'templates' => $this->fields->templatesFor($record->path, $principal),
                'template' => MetaText::text($record->frontmatter['template'] ?? null),
                'snippets' => $this->snippets->forPage($record->path, $principal),
                // The assistant rail (phase 15d): only when on, and the page's profile has actions (D15)
                'ai' => $this->aiRail($record->path),
                // Phase 26: what each exam's template says to check, beside the text
                'checklists' => $this->checklists?->forReport($record->frontmatter, $principal) ?? [],
                'references' => $this->references?->forReport($record->frontmatter, $principal, $request->basePath) ?? [],
                'rawLink' => $this->rawLinkFor($request, $record->path, true),
                'editorShell' => true,
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($record->path))
              + ChromeVars::pageHeaderFromRow($indexed, $principal, 'edit'),
            t('tabs.edit') . ' · ' . (string) $indexed['title'],
        ));
    }

    /**
     * @param array<string, mixed> $frontmatter what the Details panel's fields are populated from —
     *        the page's own on a plain GET, or what was just attempted, on an error redisplay
     */
    private function renderCurated(Request $request, PageRecord $record, ?string $error, string $body, array $frontmatter, ?string $conflictDocument, ?User $principal): Response
    {
        $indexed = $this->index->findByPath($record->path, $principal);
        if ($indexed === null) {
            throw new PageNotFoundException();
        }

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/editor.php',
            [
                'path' => $record->path,
                'baseRev' => $record->rev,
                'signed' => $record->status === 'signed',
                'status' => $record->status,
                'raw' => false,
                'error' => $error,
                'document' => null,
                'body' => $body,
                'details' => $this->fields->forPage($record->path, $frontmatter, $principal),
                'conflictDocument' => $conflictDocument,
                'basePath' => $request->basePath,
                'priorCandidates' => $this->priorCandidates($record, $indexed, $principal),
                'templates' => $this->fields->templatesFor($record->path, $principal),
                'template' => MetaText::text($frontmatter['template'] ?? null),
                'snippets' => $this->snippets->forPage($record->path, $principal),
                'ai' => $this->aiRail($record->path),
                'checklists' => $this->checklists?->forReport($frontmatter, $principal) ?? [],
                'references' => $this->references?->forReport($frontmatter, $principal, $request->basePath) ?? [],
                'rawLink' => $this->rawLinkFor($request, $record->path, false),
                'editorShell' => true,
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($record->path))
              + ChromeVars::pageHeaderFromRow($indexed, $principal, 'edit'),
            t('tabs.edit') . ' · ' . (string) $indexed['title'],
        ));
    }

    /**
     * Insert prior study (phase 10): this patient's other reports the
     * caller can read, newest first — the timeline's lookup, so the same
     * visibility predicate (invariant 6).
     *
     * @param array<string, mixed> $indexed
     *
     * @return list<array{path: string, label: string, date: string, modality: string}>
     */
    private function priorCandidates(PageRecord $record, array $indexed, ?User $principal): array
    {
        if (!ReportPath::isReport($record->path)) {
            return [];
        }
        $candidates = [];
        foreach ($this->studies->forRow($indexed, $principal) as $row) {
            $path = (string) $row['path'];
            if ($path === $record->path || !ReportPath::isReport($path)) {
                continue;
            }
            $candidates[] = [
                'path' => $path,
                'label' => MetaText::text($row['exam_title'] ?? null) !== '' ? MetaText::text($row['exam_title']) : (string) $row['title'],
                'date' => MetaText::date($row['study_date'] ?? null, 'd.m.Y'),
                'modality' => (string) ($row['modality'] ?? ''),
            ];
        }

        return $candidates;
    }

    /**
     * @return ?array{actions: list<array{id: string, label: string, tooltip: string, icon: string, result: string}>, provider: string, external: bool}
     */
    private function aiRail(string $path): ?array
    {
        $actions = $this->aiActions?->forPage($path) ?? [];
        if ($actions === [] || $this->aiConfig === null) {
            return null;
        }
        try {
            $external = (new EgressGuard())->isExternal($this->aiConfig->endpoint);
        } catch (\Reporion\Exception\AiException) {
            return null;
        }

        return [
            'actions' => array_map(static fn (\Reporion\Service\Ai\Action $a): array => $a->forEditor() + ['custom' => str_contains($a->prompt, '{prompt}')], $actions),
            'provider' => (string) parse_url($this->aiConfig->endpoint, PHP_URL_HOST) . ' · ' . $this->aiConfig->model,
            // The rail head names the server in use (Admin → AI); host and model are its tooltip
            'server' => $this->aiConfig->serverName,
            'external' => $external,
        ];
    }
}
