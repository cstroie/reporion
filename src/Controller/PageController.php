<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\PageTemplateRenderer;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\PageMoves;
use Reporion\Service\Revisions;
use Reporion\Storage\StorageInterface;
use Reporion\Support\MetaText;

/**
 * Thin (CLAUDE.md "Controller/"): parse, call services, render. Never
 * touches Storage\FlatFile's disk paths or Index\Sqlite's SQL directly
 * beyond calling the interfaces.
 */
final class PageController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly PageTemplateRenderer $templates,
        private readonly int $trashPurgeDays,
        private readonly Revisions $revisions,
        private readonly AuditLog $audit,
        private readonly PageMoves $moves,
    ) {
    }

    /**
     * GET /{path}. Visibility and namespace grants are resolved by the
     * index query (Search\Query::pageAccessClause(), invariant 6) before
     * disk is ever touched — a private page with no covering grant and one
     * that does not exist are the same PageNotFoundException from here on
     * (invariant 9: 404, never 403).
     */
    public function view(Request $request, string $path, ?User $principal): Response
    {
        $indexed = $this->index->findByPath($path, $principal);
        // "{path}@{rev}" is a revision of {path} — only when no page is
        // literally named that way, so a real page is never shadowed.
        if ($indexed === null && preg_match('/^(.+)@([1-9][0-9]{0,8})$/', $path, $m) === 1) {
            return $this->viewRevision($request, $m[1], (int) $m[2], $principal);
        }
        if ($indexed === null) {
            // A moved page's old path: follow the stub — but only to a page
            // this caller may see, or the stub would reveal where it went
            $target = $this->storage->redirectTarget($path);
            if ($target !== null && $this->index->findByPath($target, $principal) !== null) {
                return Response::redirect($request->basePath . '/' . $target, 301);
            }
            throw new PageNotFoundException();
        }

        // Disk is authoritative (invariant 1): render from current.md, not
        // from the index's cached copy, even though the index already
        // proved this exact path is readable right now.
        $record = $this->storage->read($path);

        return Response::html($this->templates->render($record, $principal, $request));
    }

    /**
     * GET /r/{pid}/{rev} — the citable, rename-proof link exports print
     * (D3): redirects to the page's current path at that revision. Same
     * access rule as the page itself; a pid or rev the caller cannot see is
     * a 404, never a 403 (invariant 9).
     */
    public function permalink(Request $request, string $pid, string $rev, ?User $principal): Response
    {
        $indexed = $this->index->findByPid($pid, $principal);
        if ($indexed === null || !ctype_digit($rev) || (int) $rev < 1 || (int) $rev > (int) $indexed['rev']) {
            throw new PageNotFoundException();
        }

        return Response::redirect($request->basePath . '/' . $indexed['path'] . '@' . (int) $rev);
    }

    private function viewRevision(Request $request, string $path, int $rev, ?User $principal): Response
    {
        if ($this->index->findByPath($path, $principal) === null) {
            throw new PageNotFoundException();
        }

        $current = $this->storage->read($path);
        $record = $this->revisions->record($current, $rev);

        return Response::html($this->templates->render(
            $record,
            $principal,
            $request,
            currentRev: $current->rev,
            signature: $this->revisions->signature($current, $rev),
        ));
    }

    /**
     * GET /{path}/delete — a confirmation step, not the delete itself.
     * Deletion has no restore UI (unlike revert/deactivate, which stay
     * reversible from inside the app) — the only way back is
     * data/trash/ on disk until trash:purge runs, so a stray click on the
     * kebab menu must not be enough on its own to remove a page (see
     * docs/BUILD_LOG.md).
     */
    /**
     * GET /{path}/move — the move form, under the page header; GET
     * /{path}/rename ($rename) is the same form restricted to the last
     * segment. The old /{path}/move?rename=1 is a permanent redirect.
     */
    public function moveForm(Request $request, string $path, ?User $principal, bool $rename = false): Response
    {
        if (!$rename && self::isRename($request)) {
            return Response::redirect($request->basePath . '/' . $path . '/rename', 301);
        }

        return $this->renderMove($request, $path, $principal, error: null, to: $rename ? self::lastSegment($path) : $path, rename: $rename);
    }

    /**
     * POST /{path}/move — needs write access at both the old and the new
     * path. Links in unsigned pages are rewritten (Service\PageMoves); every
     * write is audited.
     *
     * POST /{path}/rename ($rename; `/move?rename=1` still works) is a
     * stricter front end onto the same move: the
     * namespace prefix comes from $path itself, never from the request, so
     * a rename can never smuggle a namespace change even from a hand-built
     * POST — `$name` has every colon stripped before it is used.
     */
    public function move(Request $request, string $path, ?User $principal, bool $rename = false): Response
    {
        if ($principal === null || !$principal->canWrite($path) || $this->index->findByPath($path, $principal) === null) {
            throw new PageNotFoundException();
        }

        $rename = $rename || self::isRename($request);
        parse_str($request->body, $fields);
        if ($rename) {
            $name = \is_string($fields['name'] ?? null) ? str_replace(':', '', trim($fields['name'])) : '';
            $to = self::withLastSegment($path, $name);
        } else {
            $to = \is_string($fields['to'] ?? null) ? trim($fields['to'], " \t:") : '';
        }
        if ($to === '' || $to === $path || !$principal->canWrite($to)) {
            return $this->renderMove($request, $path, $principal, error: t('move.err_target'), to: $rename ? ($name ?? '') : $to, rename: $rename);
        }

        try {
            $result = $this->moves->move($path, $to, $principal->username, $request);
        } catch (InvalidArgumentException $e) {
            return $this->renderMove($request, $path, $principal, error: $e->getMessage(), to: $rename ? ($name ?? '') : $to, rename: $rename);
        }

        return Response::redirect($request->basePath . '/' . $result['moved']->path);
    }

    private function renderMove(Request $request, string $path, ?User $principal, ?string $error, string $to, bool $rename = false): Response
    {
        $indexed = $this->index->findByPath($path, $principal);
        if ($principal === null || $indexed === null || !$principal->canWrite($path)) {
            throw new PageNotFoundException();
        }

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/page-move.php',
            ['path' => $path, 'to' => $to, 'error' => $error, 'basePath' => $request->basePath, 'rename' => $rename, 'nsPrefix' => self::nsPrefix($path)]
                + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($path))
                + ChromeVars::pageHeaderFromRow($indexed, $principal, 'move'),
            $rename ? t('rename.title') : t('move.title'),
        ), $error !== null ? 422 : 200);
    }

    private static function isRename(Request $request): bool
    {
        return ($request->query['rename'] ?? null) === '1';
    }

    /** "reports:mri:mioveni:260922-x" -> "260922-x"; a top-level page has no prefix to strip */
    private static function lastSegment(string $path): string
    {
        $segments = explode(':', $path);

        return (string) end($segments);
    }

    /** "reports:mri:mioveni:" for "reports:mri:mioveni:260922-x"; "" for a top-level page */
    private static function nsPrefix(string $path): string
    {
        $segments = explode(':', $path);
        array_pop($segments);

        return $segments === [] ? '' : implode(':', $segments) . ':';
    }

    private static function withLastSegment(string $path, string $name): string
    {
        return self::nsPrefix($path) . $name;
    }

    public function confirmDelete(Request $request, string $path, ?User $principal): Response
    {
        return $this->renderDeleteConfirm($request, $path, $principal, null, 200);
    }

    private function renderDeleteConfirm(Request $request, string $path, ?User $principal, ?string $error, int $status): Response
    {
        if ($principal === null || !$principal->canWrite($path)) {
            throw new PageNotFoundException();
        }

        $indexed = $this->index->findByPath($path, $principal);
        if ($indexed === null) {
            throw new PageNotFoundException();
        }
        $title = MetaText::text($indexed['title'] ?? null);
        if ($title === '') {
            $title = $path;
        }

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/page-delete-confirm.php',
            [
                'path' => $path,
                'title' => $title,
                'trashPurgeDays' => $this->trashPurgeDays,
                'basePath' => $request->basePath,
                'error' => $error,
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($path))
          + ChromeVars::pageHeaderFromRow($indexed, $principal, 'delete'),
            t('page.delete_confirm_title'),
        ), $status);
    }

    /**
     * POST /{path}/delete — soft delete (Storage\FlatFile::delete() moves
     * the page directory to data/trash/, invariant 1: never a hard delete
     * from a controller). Gated on canWrite() alone, like
     * EditorController::save() and NewPageController::create() — a write
     * action, not a read, so the namespace-grant check is sufficient and a
     * covering grant bypasses visibility (Search\Query's own docblock),
     * exactly as it does for editing.
     */
    public function delete(Request $request, string $path, ?User $principal): Response
    {
        if ($principal === null || !$principal->canWrite($path)) {
            throw new PageNotFoundException();
        }

        $deleted = $this->storage->read($path);
        try {
            $this->storage->delete($path, $principal->username);
        } catch (InvalidArgumentException) {
            // A page and a namespace share this name: the pages under it stay
            return $this->renderDeleteConfirm($request, $path, $principal, t('page.delete_has_children'), 409);
        }
        $this->audit->record('page.delete', $principal->username, $request, $deleted->pid, $deleted->path, $deleted->rev);

        // Land on the parent namespace index — the page just vanished from
        // its listing (Storage::delete() removes it from the index as part
        // of the same call), so this is the most useful place to end up. A
        // top-level page with no ":" has no namespace index to land on;
        // home is the fallback.
        $segments = explode(':', $path);
        array_pop($segments);
        $redirectTo = $segments === [] ? '/' : '/' . implode(':', $segments) . ':';

        return Response::redirect($request->basePath . $redirectTo);
    }
}
