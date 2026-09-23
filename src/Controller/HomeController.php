<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Http\PageTemplateRenderer;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\IndexInterface;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;

/**
 * GET / (docs/architecture-api.md §6): anonymous gets `site:home` — an
 * ordinary public page, edited like any other — or a built-in stub if it
 * does not exist yet. Owner also gets `site:home` for now: the real
 * dashboard (recent, drafts, order queue, index health) needs listing
 * queries and a worklist that are not built yet (see docs/BUILD_LOG.md) —
 * this route is not a placeholder, it just doesn't lie about having a
 * dashboard.
 */
final class HomeController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly PageTemplateRenderer $templates,
        private readonly string $homePagePath,
    ) {
    }

    public function home(Request $request, bool $isOwner): Response
    {
        $indexed = $this->index->findByPath($this->homePagePath, $isOwner);

        // pageAccessClause() (what findByPath() enforces) allows unlisted
        // for anonymous, because it is meant for a caller who already has
        // the exact path in hand. That is not true here: "/" is not
        // knowledge of site:home's path, it is the landing page, so an
        // anonymous caller only gets it when it is actually public
        // (Table 2 — unlisted is reachable "with the exact path or a share
        // token", neither of which "/" is).
        $found = $indexed !== null && ($isOwner || $indexed['visibility'] === 'public');

        $record = $found ? $this->storage->read($this->homePagePath) : $this->stub();

        return Response::html($this->templates->render($record, $isOwner));
    }

    private function stub(): PageRecord
    {
        return new PageRecord(
            pid: '',
            path: $this->homePagePath,
            rev: 0,
            status: 'draft',
            visibility: 'public',
            frontmatter: ['title' => t('app.name')],
            body: t('home.stub_body', [$this->homePagePath]),
            revlog: [],
            meta: [],
        );
    }
}
