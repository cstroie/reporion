<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageExistsException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\CommitNote;
use Reporion\Service\Ai\EgressGuard;
use Reporion\Service\Duplicates;
use Reporion\Service\ExamAccessions;
use Reporion\Service\Checklists;
use Reporion\Service\References;
use Reporion\Service\FrontmatterFields;
use Reporion\Service\FrontmatterGuess;
use Reporion\Service\NamespaceDefaults;
use Reporion\Service\PatientStudies;
use Reporion\Service\Publishing;
use Reporion\Service\Snippets;
use Reporion\Storage\FlatFile;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ConclusionSummary;
use Reporion\Support\DocumentFormat;
use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\ReportPath;
use Reporion\Support\TitleFromHeading;
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
        /** Admin → Settings `editor.save_stays_open`: a save returns to the editor, not the page */
        private readonly bool $saveStaysOpen = false,
        /** The revision note for a Save that leaves *What changed?* empty (2026-10-10) */
        private readonly ?CommitNote $commitNote = null,
        /** The visibility a new page starts with, from its nearest namespace description (phase 16, F) */
        private readonly ?NamespaceDefaults $defaults = null,
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
            // A path that starts blank (no ?from= copy) opens on its namespace's default
            $from = null;
            if (!\is_string($request->query['from'] ?? null) || trim($request->query['from'], ': ') === '') {
                [$frontmatter, $from] = $this->withInheritedVisibility($path, $frontmatter, $principal);
            }

            return $this->renderNewCurated($request, $path, $body, $frontmatter, null, $principal, visibilityFrom: $from);
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
        // Every report opens in the Metadata view, multi-exam ones too (phase 28b: a card per exam)
        return ($request->query['raw'] ?? null) === '1';
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
            // The guided form's report (openNew()) carries its frontmatter; anything else starts from its path
            $carried = self::carried($fields);
            $starter = $carried ?? FrontmatterGuess::forNewPage($path, $body);
            if ($this->looksLikeWholeDocument($body)) {
                return $this->renderNewCurated($request, $path, $body, $starter, t('details.err_body_looks_like_document'), $principal, carried: $carried !== null);
            }
            $fm = \is_array($fields['fm'] ?? null) ? $fields['fm'] : [];
            $shown = \is_array($fields['fm_shown'] ?? null) ? array_map('strval', $fields['fm_shown']) : [];
            $frontmatter = Publishing::merge($starter, $this->fields->changesFrom($fm, $shown, $carried ?? [], $path));
            $frontmatter = self::withChosenVisibility($frontmatter, $fields);
        }

        // D16: public only with the acknowledgement, which only the Metadata view asks for
        if (($frontmatter['visibility'] ?? null) === 'public') {
            if ($this->isRawMode($request, [])) {
                return $this->renderNew($request, $path, $document ?? '', t('vis.err_raw_public'), $principal);
            }
            if (($fields['acknowledge'] ?? null) !== '1') {
                return $this->renderNewCurated($request, $path, $body, $frontmatter, t('vis.err_ack'), $principal, ackMissing: true, carried: isset($carried));
            }
        }

        // A guided report's numbers are allocated now, at its create (D20)
        $frontmatter = $this->examAccessions->fill($path, $frontmatter, create: isset($carried));
        $frontmatter = ConclusionSummary::fill($path, $frontmatter, $body);
        // A blank title takes the first `#` heading (2026-10-10)
        $frontmatter = TitleFromHeading::fill($frontmatter, $body);
        try {
            // Exclusive: a page made there meanwhile (a second tab, a double submit) is shown, never overwritten nor `-2`
            $record = $this->storage->create($path, $frontmatter, $body, $principal->username, $note !== '' ? $note : null, exclusive: true);
        } catch (PageExistsException) {
            return $this->renderNewCurated($request, $path, $body, $frontmatter, t('new.err_exists'), $principal, existingPath: $path, carried: isset($carried));
        }
        $this->audit->record('page.create', $principal->username, $request, $record->pid, $record->path, $record->rev);
        if ($record->visibility === 'public') {
            $this->audit->record('page.publish', $principal->username, $request, $record->pid, $record->path, $record->rev, extra: ['acknowledged' => true]);
        }

        return $this->afterSave($request, $record->path, $record->rev);
    }

    /**
     * The editor on a report the guided form drafted, not written yet
     * (2026-10-10): its frontmatter travels in the form (`carried`), so the
     * first Save — revision 1 — keeps every field, Details panel or not.
     * The caller has checked write access to the path.
     *
     * @param array<string, mixed> $frontmatter
     */
    public function openNew(Request $request, string $path, array $frontmatter, string $body, User $principal): Response
    {
        [$frontmatter, $from] = $this->withInheritedVisibility($path, self::withoutAccessions($frontmatter), $principal);

        return $this->renderNewCurated($request, $path, $body, $frontmatter, null, $principal, carried: true, visibilityFrom: $from);
    }

    /**
     * A new page's starting visibility from the nearest namespace
     * description (Service\NamespaceDefaults), only as the picker's
     * pre-selection: nothing is saved by it, and `public` still needs the
     * acknowledgement on the Save. The description it came from is returned
     * beside the frontmatter (never inside it: `carried` posts the
     * frontmatter back and it would be saved).
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function withInheritedVisibility(string $path, array $frontmatter, User $principal): array
    {
        if ($this->defaults === null || ($frontmatter['visibility'] ?? 'private') !== 'private') {
            return [$frontmatter, null];
        }
        $default = $this->defaults->forNewPage($path, $principal);
        if ($default['from'] === null || $default['visibility'] === 'private') {
            return [$frontmatter, null];
        }
        $frontmatter['visibility'] = $default['visibility'];

        return [$frontmatter, $default['from']];
    }

    /**
     * The frontmatter a guided report's form carried, or null. Its accessions
     * are dropped: they are allocated at the create, never taken from a
     * client (D20; Service\ExamAccessions).
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>|null
     */
    private static function carried(array $fields): ?array
    {
        $json = \is_string($fields['carried'] ?? null) ? $fields['carried'] : '';
        $frontmatter = $json !== '' ? json_decode($json, true) : null;

        return \is_array($frontmatter) && !array_is_list($frontmatter) ? self::withoutAccessions($frontmatter) : null;
    }

    /**
     * @param array<string, mixed> $frontmatter
     *
     * @return array<string, mixed>
     */
    private static function withoutAccessions(array $frontmatter): array
    {
        unset($frontmatter['accession']);
        if (\is_array($frontmatter['exams'] ?? null)) {
            foreach ($frontmatter['exams'] as $i => $exam) {
                if (\is_array($exam)) {
                    unset($frontmatter['exams'][$i]['accession']);
                }
            }
        }

        return $frontmatter;
    }

    /** The current revision's "What changed" note — what a minor edit keeps */
    private static function lastNote(PageRecord $record): string
    {
        foreach ($record->revlog as $entry) {
            if ((int) ($entry['n'] ?? 0) === $record->rev) {
                return MetaText::text($entry['note'] ?? null);
            }
        }

        return '';
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
                'saveStaysOpen' => $this->saveStaysOpen,
                'savedRev' => self::savedRev($request),
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($path)),
            t('editor.new_title', [$path]),
        ), $error !== null ? 422 : 200);
    }

    /** @param array<string, mixed> $frontmatter what the Details panel's fields are populated from */
    private function renderNewCurated(Request $request, string $path, string $body, array $frontmatter, ?string $error, User $principal, bool $ackMissing = false, ?string $existingPath = null, bool $carried = false, ?string $visibilityFrom = null): Response
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
                'details' => self::withVisibility($this->fields->forPage($path, $frontmatter, $principal), $path, $frontmatter, 'private', 0, $ackMissing) + ['visibilityFrom' => $visibilityFrom],
                'conflictDocument' => null,
                'basePath' => $request->basePath,
                'priorCandidates' => [],
                'templates' => $this->fields->templatesFor($path, $principal),
                'template' => MetaText::text($frontmatter['template'] ?? null),
                'snippets' => $this->snippets->forPage($path, $principal),
                'editorShell' => true,
                'saveStaysOpen' => $this->saveStaysOpen,
                'savedRev' => self::savedRev($request),
                'existingPath' => $existingPath,
                'carried' => $carried ? json_encode($frontmatter, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
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
        // A minor edit keeps its revision's note: what is typed (no JavaScript to disable the field) is not used
        if ($minor) {
            $note = '';
        }
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
            $frontmatter = self::withChosenVisibility($frontmatter, $fields);
        }

        // D16: a page becomes public only with the acknowledgement the Metadata view asks for
        $publishes = ($frontmatter['visibility'] ?? null) === 'public' && $record->visibility !== 'public';
        if ($publishes && $rawMode) {
            return $this->render($request, $record, error: t('vis.err_raw_public'), document: $document ?? '', conflictDocument: null, principal: $principal);
        }
        if ($publishes && ($fields['acknowledge'] ?? null) !== '1') {
            return $this->renderCurated($request, $record, error: t('vis.err_ack'), body: $body, frontmatter: $frontmatter, conflictDocument: null, principal: $principal, ackMissing: true);
        }

        // An exam added in the editor gets its accession now (phase 12, D20)
        $frontmatter = $this->examAccessions->fill($path, $frontmatter);
        $frontmatter = ConclusionSummary::fill($path, $frontmatter, $body, $record->body);
        // A blank title takes the first `#` heading (2026-10-10)
        $frontmatter = TitleFromHeading::fill($frontmatter, $body);
        // What the assistant proposed and the doctor applied (phase 15, D8 as
        // amended): the revision is theirs, the note and the audit say so
        $assisted = array_values(array_unique(array_filter(
            explode(',', \is_string($fields['ai_assisted'] ?? null) ? $fields['ai_assisted'] : ''),
            static fn (string $id): bool => preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $id) === 1,
        )));
        // No note typed and not a minor edit (it keeps its revision's note):
        // the `commit` prompt writes one from what the body changes, said as
        // assisted like the rail's (2026-10-10). Not for a save about to
        // conflict — nothing is sent.
        if ($note === '' && !$minor && $record->rev === $baseRev && $body !== $record->body
            && ($line = $this->commitNote?->suggest($record, $frontmatter, $body, $principal, $request)) !== null) {
            $note = $line;
            $assisted[] = 'commit';
        }
        if ($assisted !== []) {
            // A minor edit's note stays its revision's, with what the assistant did added (D8)
            $note = $minor ? self::lastNote($record) : $note;
            $aiNote = t('editor.ai_note', [implode(', ', $assisted)]);
            // Saved again as minor with the same actions: said once, not once per save
            if (!str_ends_with($note, $aiNote)) {
                $note = trim($note . ($note !== '' ? ' · ' : '') . $aiNote);
            }
        }
        try {
            $saved = $this->storage->save($path, $frontmatter, $body, $baseRev, $principal->username, $note !== '' ? $note : null, minor: $minor);
            $this->audit->record('page.save', $principal->username, $request, $saved->pid, $saved->path, $saved->rev, extra: $assisted !== [] ? ['assisted' => $assisted] : []);
            if ($publishes) {
                $this->audit->record('page.publish', $principal->username, $request, $saved->pid, $saved->path, $saved->rev, extra: ['acknowledged' => true]);
            }
        } catch (RevisionConflictException $e) {
            $conflictDocument = DocumentFormat::encode($e->current->frontmatter, $e->current->body);

            return $rawMode
                ? $this->render($request, $e->current, error: t('editor.err_conflict'), document: $document ?? '', conflictDocument: $conflictDocument, principal: $principal)
                : $this->renderCurated($request, $e->current, error: t('editor.err_conflict'), body: $body, frontmatter: $frontmatter, conflictDocument: $conflictDocument, principal: $principal);
        }

        return $this->afterSave($request, $saved->path, $saved->rev);
    }

    /**
     * Where a successful save goes: the page, or — with "Save keeps the
     * editor open" (Admin → Settings, 2026-10-10) — back to the editor at
     * the revision just written (the same one, for a minor edit), raw mode
     * kept, with `saved` for its notice
     */
    private function afterSave(Request $request, string $path, int $rev): Response
    {
        if (!$this->saveStaysOpen) {
            return Response::redirect($request->basePath . '/' . $path);
        }

        return Response::redirect($request->basePath . '/' . $path . '/edit?' . (($request->query['raw'] ?? null) === '1' ? 'raw=1&' : '') . 'saved=' . $rev);
    }

    /** The revision a save that kept the editor open just wrote (`?saved=`), for its notice */
    private static function savedRev(Request $request): ?int
    {
        $saved = $request->query['saved'] ?? null;

        return \is_string($saved) && ctype_digit($saved) && $saved !== '0' ? (int) $saved : null;
    }

    /** The note of the revision a save that kept the editor open just wrote, for its notice ('' when none) */
    private static function savedNote(PageRecord $record, ?int $rev): string
    {
        foreach ($rev !== null ? $record->revlog : [] as $entry) {
            if ((int) ($entry['n'] ?? 0) === $rev) {
                return MetaText::text($entry['note'] ?? null);
            }
        }

        return '';
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
                'lastNote' => self::lastNote($record),
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
                'saveStaysOpen' => $this->saveStaysOpen,
                'savedRev' => self::savedRev($request),
                'savedNote' => self::savedNote($record, self::savedRev($request)),
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($record->path))
              + ChromeVars::pageHeaderFromRow($indexed, $principal, 'edit'),
            t('tabs.edit') . ' · ' . (string) $indexed['title'],
        ));
    }

    /**
     * @param array<string, mixed> $frontmatter what the Details panel's fields are populated from —
     *        the page's own on a plain GET, or what was just attempted, on an error redisplay
     */
    private function renderCurated(Request $request, PageRecord $record, ?string $error, string $body, array $frontmatter, ?string $conflictDocument, ?User $principal, bool $ackMissing = false): Response
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
                'lastNote' => self::lastNote($record),
                'status' => $record->status,
                'raw' => false,
                'error' => $error,
                'document' => null,
                'body' => $body,
                'details' => self::withVisibility($this->fields->forPage($record->path, $frontmatter, $principal), $record->path, $frontmatter, $record->visibility, \count($this->storage->mediaOf($record->path)), $ackMissing),
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
                'saveStaysOpen' => $this->saveStaysOpen,
                'savedRev' => self::savedRev($request),
                'savedNote' => self::savedNote($record, self::savedRev($request)),
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($record->path))
              + ChromeVars::pageHeaderFromRow($indexed, $principal, 'edit'),
            t('tabs.edit') . ' · ' . (string) $indexed['title'],
        ));
    }

    /**
     * The Metadata view's visibility picker (partials/visibility-picker.php)
     * posts `visibility`; anything else leaves the page's own.
     *
     * @param array<string, mixed> $frontmatter
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function withChosenVisibility(array $frontmatter, array $fields): array
    {
        $chosen = $fields['visibility'] ?? null;
        if (\is_string($chosen) && \in_array($chosen, Publishing::VISIBILITIES, true)) {
            $frontmatter['visibility'] = $chosen;
        }

        return $frontmatter;
    }

    /**
     * The details plus what the visibility picker needs: the saved level,
     * what Public would show (D16), whether a post lacked the acknowledgement.
     *
     * @param array<string, mixed> $details
     * @param array<string, mixed> $frontmatter
     *
     * @return array<string, mixed>
     */
    private static function withVisibility(array $details, string $path, array $frontmatter, string $now, int $media, bool $ackMissing): array
    {
        return $details + [
            'visibilityNow' => $now,
            'visibilityPreview' => Publishing::previewOf($path, $frontmatter, $media),
            'visibilityAckMissing' => $ackMissing,
        ];
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
                'date' => MetaText::date($row['study_date'] ?? null, MetaText::DATE),
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
