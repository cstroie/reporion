<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use DateTimeImmutable;
use Reporion\Auth\User;
use Reporion\Http\ChromeVars;
use Reporion\Http\PageTemplateRenderer;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;

/**
 * GET / (docs/architecture-api.md §6): anonymous gets `site:home` — an
 * ordinary public page, edited like any other — or a built-in stub if it
 * does not exist yet. A signed-in user gets the dashboard: recently
 * updated reports they can see, with filters, and their own drafts.
 */
final class HomeController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly PageTemplateRenderer $templates,
        private readonly string $homePagePath,
        /** @var list<string> modality codes offered as dashboard filters */
        private readonly array $modalities = [],
    ) {
    }

    public function home(Request $request, ?User $principal): Response
    {
        if ($principal !== null) {
            return $this->dashboard($request, $principal);
        }

        $indexed = $this->index->findByPath($this->homePagePath, $principal);

        // pageAccessClause() (what findByPath() enforces) allows unlisted —
        // and, for a grant-holder, private too — for a caller who already
        // has the exact path in hand. That is not true here: "/" is not
        // knowledge of site:home's path, it is the landing page, so a
        // caller only gets it when it is actually public, OR they are
        // entitled to read site:home specifically (owner, or a grant
        // covering the site: namespace — ordinary staff access, not a
        // token; Table 2, docs/architecture-storage-index.md).
        $canReadDirectly = $principal?->canRead($this->homePagePath) ?? false;
        $found = $indexed !== null && ($canReadDirectly || $indexed['visibility'] === 'public');

        $record = $found ? $this->storage->read($this->homePagePath) : $this->stub();

        return Response::html($this->templates->render($record, $principal, $request));
    }

    /**
     * GET / for a signed-in user (design/mockup/WikiWorklist.dc.html): what
     * changed recently across everything they can see, filterable by plain
     * query-string links (?mod=, ?days=30, ?mine=1 — no JavaScript), and
     * their own drafts. Both lists are Index::listRecent(), the listing
     * predicate (invariant 6).
     */
    private function dashboard(Request $request, User $principal): Response
    {
        $modality = \is_string($request->query['mod'] ?? null) && \in_array($request->query['mod'], $this->modalities, true) ? $request->query['mod'] : '';
        $days = ($request->query['days'] ?? null) === '30' ? 30 : 0;
        $mine = ($request->query['mine'] ?? null) === '1';

        $filters = ['modality' => $modality];
        if ($days > 0) {
            $filters['since'] = (new DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d\TH:i:sP');
        }
        if ($mine) {
            $filters['updated_by'] = $principal->username;
        }

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/dashboard.php', [
            'rows' => $this->index->listRecent($principal, $filters),
            'drafts' => $this->index->listRecent($principal, ['status' => 'draft', 'updated_by' => $principal->username], 10),
            'modalities' => $this->modalities,
            'filter' => ['mod' => $modality, 'days' => $days, 'mine' => $mine],
            'homePagePath' => $this->homePagePath,
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('dash.title')));
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
