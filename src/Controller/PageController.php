<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Render;
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
        private readonly Render $render,
    ) {
    }

    /**
     * GET /{path}. Visibility is resolved by the index query
     * (Search\Query::pageAccessClause(), invariant 6) before disk is ever
     * touched — a private page and one that does not exist are the same
     * PageNotFoundException from here on (invariant 9: 404, never 403).
     */
    public function view(Request $request, string $path, bool $isOwner): Response
    {
        $indexed = $this->index->findByPath($path, $isOwner);
        if ($indexed === null) {
            throw new PageNotFoundException();
        }

        // Disk is authoritative (invariant 1): render from current.md, not
        // from the index's cached copy, even though the index already
        // proved this exact path is readable right now.
        $record = $this->storage->read($path);
        $rendered = $this->render->toHtml($record->body);

        $html = View::render(\dirname(__DIR__, 2) . '/templates/page-view.php', [
            'title' => (string) ($record->frontmatter['title'] ?? $record->path),
            'path' => $record->path,
            'rev' => $record->rev,
            'status' => $record->status,
            'visibility' => $record->visibility,
            // $record->frontmatter is deliberately not passed — see the
            // note at the top of templates/page-view.php.
            'contentHtml' => $rendered->html,
            'toc' => $rendered->toc,
            'warnings' => $rendered->warnings,
        ]);

        return Response::html($html);
    }
}
