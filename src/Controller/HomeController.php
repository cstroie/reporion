<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use DateTimeImmutable;
use Reporion\Auth\User;
use Reporion\Http\ChromeVars;
use Reporion\Http\PageTemplateRenderer;
use Reporion\Http\QuickNav;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\NewReport;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ReportPath;

/**
 * GET / (docs/architecture-api.md §6): anonymous gets `site:home` — an
 * ordinary public page, edited like any other — or a built-in stub if it
 * does not exist yet. A signed-in user gets the start page (dashboard()),
 * or with ?all=1 the full list of recent changes (recent()).
 */
final class HomeController
{
    /** A draft untouched this long is flagged on the start page */
    public const STALE_DAYS = 3;

    /** Rows per start-page list */
    public const SHOWN = 5;

    /** The most rows a start-page count looks at ("200+" beyond) */
    public const CAP = 200;

    private const ISO = 'Y-m-d\\TH:i:sP';

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
     * GET / for a signed-in user: the start page (2026-10-01) — what to do
     * next and where to go, not a feed. Every list is Index::listRecent()
     * on hand edits (`by_hand`, so imports and plugin fills stay out), the
     * listing predicate (invariant 6):
     *
     * - the caller's open report drafts, all of them, oldest first — the
     *   count, how many have waited more than STALE_DAYS, and a Sign link;
     * - the caller's own changes of the last week (count, today's count,
     *   the five newest); "Continue" is the newest report among them (else
     *   the newest draft, else the newest change);
     * - the team's changes of the last week, the caller's own left out;
     * - the quick-navigation list (Http\QuickNav) around the namespace of
     *   the caller's last change, so the modality's templates are a click
     *   away; and the actions: new report, a follow-up exam for the
     *   patient of the last report, the plugins' worklists (`new_report`).
     *
     * The old full list stays at /?all=1 (and ?mod=, ?days=30, ?mine=1).
     */
    private function dashboard(Request $request, User $principal): Response
    {
        $query = $request->query;
        if (($query['all'] ?? null) === '1' || isset($query['mod']) || isset($query['days']) || isset($query['mine'])) {
            return $this->recent($request, $principal);
        }

        $now = new DateTimeImmutable();
        $me = $principal->username;
        $week = $now->modify('-7 days')->format(self::ISO);
        $today = $now->setTime(0, 0)->format(self::ISO);
        $staleBefore = $now->modify('-' . self::STALE_DAYS . ' days')->format(self::ISO);

        $mine = $this->index->listRecent($principal, ['by_hand' => '1', 'updated_by' => $me, 'since' => $week], self::CAP);
        $drafts = array_values(array_filter(
            $this->index->listRecent($principal, ['ns' => QuickNav::REPORTS, 'status' => 'draft', 'by_hand' => '1', 'updated_by' => $me], self::CAP),
            static fn (array $row): bool => ReportPath::isReport((string) $row['path']),
        ));
        $team = $this->index->listRecent($principal, ['by_hand' => '1', 'not_by' => $me, 'since' => $week], self::SHOWN);

        // Continue with the newest report the caller touched — a template or
        // a prompt page edited in between is in "My recent changes" anyway
        $myReports = array_values(array_filter($mine, static fn (array $row): bool => ReportPath::isReport((string) $row['path'])));
        $last = $myReports[0] ?? $drafts[0] ?? $mine[0] ?? null;
        $lastPath = $last !== null ? (string) $last['path'] : '';
        $canReports = NewReport::canCreateReports($principal);
        $lastIsReport = $last !== null && ReportPath::isReport($lastPath);

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/dashboard.php', [
            // Two parts, so a narrow screen breaks the line after the comma, never inside the name
            'greeting' => t(match (true) {
                (int) $now->format('G') < 12 => 'start.morning',
                (int) $now->format('G') < 18 => 'start.afternoon',
                default => 'start.evening',
            }),
            'greetingName' => $principal->signatureName(),
            'today' => $now->format('l, j F Y'),
            'stats' => [
                'drafts' => \count($drafts),
                'stale' => \count(array_filter($drafts, static fn (array $row): bool => (string) $row['updated'] < $staleBefore)),
                'today' => \count(array_filter($mine, static fn (array $row): bool => (string) $row['updated'] >= $today)),
                'week' => \count($mine),
            ],
            'cap' => self::CAP,
            'staleDays' => self::STALE_DAYS,
            'staleBefore' => $staleBefore,
            'last' => $last,
            'lastActions' => $last === null ? [] : [
                'edit' => $principal->canWrite($lastPath),
                'sign' => $lastIsReport && (string) $last['status'] === 'draft' && $principal->canWrite($lastPath),
                'patient' => $lastIsReport,
            ],
            'drafts' => \array_slice(array_reverse($drafts), 0, self::SHOWN),
            'mine' => \array_slice($mine, 0, self::SHOWN),
            'team' => $team,
            'startQuick' => QuickNav::links($principal, $this->index, $last !== null ? ChromeVars::namespaceOf($lastPath) : ''),
            'canWritePath' => static fn (string $path): bool => $principal->canWrite($path),
            'actions' => [
                'newReport' => $canReports ? ($lastIsReport ? '/' . ChromeVars::namespaceOf($lastPath) : '') . '/new' : null,
                'newReportNs' => $canReports && $lastIsReport ? ChromeVars::namespaceOf($lastPath) : '',
                'followUp' => $canReports && $lastIsReport && (string) $last['pid'] !== '' ? '/new?after=' . rawurlencode((string) $last['pid']) : null,
                'newPage' => !$canReports && $principal->hasAnyWriteAccess() ? '/new' : null,
                'worklists' => $canReports ? (reporion_plugin_ui()['new_report'] ?? []) : [],
            ],
            // templates/layout.php: from here the brand goes to the site home page
            'isStartPage' => true,
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('dash.title')));
    }

    /**
     * GET /?all=1: every recently changed page the caller can see, filterable
     * by plain query-string links (?mod=, ?days=30, ?mine=1 — no
     * JavaScript). The start page links here as "All recent changes".
     */
    private function recent(Request $request, User $principal): Response
    {
        $modality = \is_string($request->query['mod'] ?? null) && \in_array($request->query['mod'], $this->modalities, true) ? $request->query['mod'] : '';
        $days = ($request->query['days'] ?? null) === '30' ? 30 : 0;
        $mine = ($request->query['mine'] ?? null) === '1';

        $filters = ['modality' => $modality];
        if ($days > 0) {
            $filters['since'] = (new DateTimeImmutable('-' . $days . ' days'))->format(self::ISO);
        }
        if ($mine) {
            $filters['updated_by'] = $principal->username;
        }

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/recent.php', [
            'rows' => $this->index->listRecent($principal, $filters),
            'modalities' => $this->modalities,
            'filter' => ['mod' => $modality, 'days' => $days, 'mine' => $mine],
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('recent.title')));
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
