<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\PageTemplateRenderer;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Storage\StorageInterface;

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
        if ($indexed === null) {
            throw new PageNotFoundException();
        }

        // Disk is authoritative (invariant 1): render from current.md, not
        // from the index's cached copy, even though the index already
        // proved this exact path is readable right now.
        $record = $this->storage->read($path);

        return Response::html($this->templates->render($record, $principal, $request));
    }

    /**
     * GET /{path}/delete — a confirmation step, not the delete itself.
     * Deletion has no restore UI (unlike revert/deactivate, which stay
     * reversible from inside the app) — the only way back is
     * data/trash/ on disk until trash:purge runs, so a stray click on the
     * kebab menu must not be enough on its own to remove a page (see
     * docs/BUILD_LOG.md).
     */
    public function confirmDelete(Request $request, string $path, ?User $principal): Response
    {
        if ($principal === null || !$principal->canWrite($path)) {
            throw new PageNotFoundException();
        }

        $record = $this->storage->read($path);
        $title = (string) ($record->frontmatter['title'] ?? $path);

        return Response::html(View::render(
            \dirname(__DIR__, 2) . '/templates/page-delete-confirm.php',
            [
                'path' => $path,
                'title' => $title,
                'trashPurgeDays' => $this->trashPurgeDays,
                'basePath' => $request->basePath,
            ]
        ));
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

        $this->storage->delete($path, $principal->username);

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
