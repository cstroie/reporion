<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\DocumentFormat;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * GET/POST /{path}/edit — the write UI this project has been missing:
 * before this route existed, the only way to create or edit a page's
 * content was a raw call to `POST/PUT /api/v1/pages`. Classic SSR form,
 * no JavaScript — the same shape `AdminUsersController`/`HistoryController`
 * already established, and, per this project's own SSR-vs-island rule,
 * arguably the right shape even once an island exists (a report you write
 * once and rarely re-edit is not "manipulate state faster than a round
 * trip allows").
 *
 * Deliberately scoped, not an oversight:
 *
 * - **One textarea, the whole document** — matching
 *   design/mockup/WikiEditor.dc.html exactly (its `<textarea class="wk-ta">`
 *   holds the full `---\nfrontmatter\n---\n\nbody` block, not a generated
 *   per-field form). `Storage::save()` replaces frontmatter wholesale, not
 *   a merge — a form exposing only a curated subset of fields (title,
 *   visibility, ...) would silently delete every field it doesn't show.
 *   Editing the raw document is what makes that impossible: whatever the
 *   page already had round-trips through the same textarea, untouched
 *   fields included.
 * - **No marked.js live preview, no autosave, no IndexedDB draft, no JS
 *   conflict-resolution UI.** All separate, independently useful
 *   follow-ups — see docs/BUILD_LOG.md.
 * - **`GET /new` is `Controller\NewPageController`, a separate controller**,
 *   not a mode of this one — creating a page needs a path the user
 *   supplies, `POST` not `PUT`, and there is no existing document to
 *   round-trip. They share `Support\DocumentFormat` for the
 *   encode/parse step, nothing else.
 * - **A conflict without JavaScript** doesn't lose the editor's typed
 *   text: `RevisionConflictException` re-renders the same form with
 *   exactly what they submitted still in the textarea, the server's
 *   current document shown read-only alongside it for comparison, and
 *   `base_rev` advanced to the current revision so a deliberate resubmit
 *   (after they've reconciled by hand) succeeds.
 */
final class EditorController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
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
        if ($principal === null || $this->index->findByPath($path, $principal) === null) {
            return Response::notFound();
        }
        if (!$principal->canWrite($path)) {
            return Response::notFound();
        }

        try {
            $record = $this->storage->read($path);
        } catch (PageNotFoundException) {
            return Response::notFound();
        }

        return $this->render($request, $record, error: null, document: DocumentFormat::encode($record->frontmatter, $record->body), conflictDocument: null, principal: $principal);
    }

    public function save(Request $request, string $path, ?User $principal): Response
    {
        if ($principal === null || $this->index->findByPath($path, $principal) === null) {
            return Response::notFound();
        }
        if (!$principal->canWrite($path)) {
            return Response::notFound();
        }

        try {
            $record = $this->storage->read($path);
        } catch (PageNotFoundException) {
            return Response::notFound();
        }

        parse_str($request->body, $fields);
        $document = \is_string($fields['document'] ?? null) ? $fields['document'] : '';
        $baseRev = isset($fields['base_rev']) && ctype_digit((string) $fields['base_rev']) ? (int) $fields['base_rev'] : null;
        $note = \is_string($fields['note'] ?? null) ? trim($fields['note']) : '';

        if ($baseRev === null) {
            return Response::notFound();
        }

        try {
            [$frontmatter, $body] = DocumentFormat::parse($document);
        } catch (RuntimeException | ParseException $e) {
            return $this->render($request, $record, error: t('editor.err_parse', [$e->getMessage()]), document: $document, conflictDocument: null, principal: $principal);
        }

        try {
            $this->storage->save($path, $frontmatter, $body, $baseRev, $principal->username, $note !== '' ? $note : null);
        } catch (RevisionConflictException $e) {
            return $this->render(
                $request,
                $e->current,
                error: t('editor.err_conflict'),
                document: $document,
                conflictDocument: DocumentFormat::encode($e->current->frontmatter, $e->current->body),
                principal: $principal,
            );
        }

        return Response::redirect($request->basePath . '/' . $path);
    }

    private function render(Request $request, PageRecord $record, ?string $error, string $document, ?string $conflictDocument, ?User $principal): Response
    {
        return Response::html(View::render(
            \dirname(__DIR__, 2) . '/templates/editor.php',
            [
                'path' => $record->path,
                'baseRev' => $record->rev,
                'error' => $error,
                'document' => $document,
                'conflictDocument' => $conflictDocument,
                'basePath' => $request->basePath,
                'railActive' => 'edit',
                'tabActive' => 'edit',
            ] + ChromeVars::forPath($principal, $record->path)
        ));
    }

}
