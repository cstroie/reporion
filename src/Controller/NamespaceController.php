<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\PageMoves;
use Reporion\Service\Render;
use Reporion\Service\Tags;
use Reporion\Storage\StorageInterface;

/**
 * GET /{ns}: (and GET /: for the root namespace, $ns === '') — namespace
 * index (docs/architecture-api.md Table 1). Ported
 * from design/mockup/WikiNsIndex.dc.html, but only the parts that have a
 * backend: sub-namespace cards (Index\Sqlite::listSubnamespaces()), a plain
 * pages table (Index\Sqlite::listNamespace()), and the `_index`/`_template`
 * reserved-page cards + "Namespace description" panel (the segment-prefix
 * convention docs/architecture-storage-index.md already documents — an
 * `_index`/`_template` page is a page like any other, just read through the
 * same visibility-checked findByPath()/Storage::read() as PageController::
 * view() uses, so nothing new to build here). Bulk select + Move / Tag /
 * Export (roadmap phase 18): the pages table is a form; Move and Tag post
 * here (bulk(): a confirm step, then apply), Export posts to
 * ExportController::bundle(). A bulk *visibility* change is deliberately
 * not offered — D16 keeps it a per-page, acknowledged act. "Recent
 * activity here" is Index::listWorklist() (the drawer's list), not the
 * audit log, which names pages only by path_hash. The mockup's persistent
 * tree sidebar (design/mockup/WikiTree.dc.html) is a separate,
 * chrome-level concern, not part of this route.
 *
 * Same invariant-6 shape as PageController::view(): both listing calls go
 * through Search\Query::visibilityClause() before disk or an empty result
 * is ever distinguishable from "does not exist" — a namespace with zero
 * visible sub-namespaces and zero visible pages for this caller 404s the
 * same as one that was never created (invariant 9). `_index`/`_template`
 * are looked up the same way, so an invisible one is silently absent from
 * the cards, not an error.
 */
final class NamespaceController
{
    /** Bulk actions report what they did back on the index through these */
    private const DONE = ['move', 'tag', 'untag'];

    public function __construct(
        private readonly IndexInterface $index,
        private readonly StorageInterface $storage,
        private readonly Render $render,
        private readonly PageMoves $moves,
        private readonly Tags $tags,
    ) {
    }

    /**
     * POST /{ns}: — the bulk Move and Tag of pages directly in $ns. Two
     * steps, both plain forms: the table's button (`action`) shows a
     * confirm page with the selection and a target (namespace or tag);
     * that page posts back with `step=apply`. Every selected path must be
     * a page this caller can see and write, directly in $ns — anything else
     * in `paths[]` is dropped, never acted on. The result comes back on the
     * index as ?done=…&n=… (a plain redirect, like Admin → Tags).
     */
    public function bulk(Request $request, string $ns, ?User $principal): Response
    {
        if ($principal === null || !$principal->canWrite($ns)) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $action = \is_string($fields['action'] ?? null) ? $fields['action'] : '';
        if (!\in_array($action, ['move', 'tag'], true)) {
            throw new PageNotFoundException();
        }
        $year = \is_string($fields['year'] ?? null) && preg_match('/^(\d{4}|all)$/', $fields['year']) === 1 ? $fields['year'] : '';

        $selected = [];
        foreach ((array) ($fields['paths'] ?? []) as $path) {
            if (!\is_string($path) || isset($selected[$path]) || !$principal->canWrite($path)) {
                continue;
            }
            $row = $this->index->findByPath($path, $principal);
            if ($row !== null && (string) $row['ns'] === $ns) {
                $selected[$path] = $row;
            }
        }
        if ($selected === []) {
            return Response::redirect($this->indexUrl($request, $ns, $year));
        }

        if (($fields['step'] ?? '') !== 'apply') {
            return $this->confirm($request, $ns, $principal, $action, $selected, $year, null, $action === 'move' ? $ns : '');
        }

        if ($action === 'move') {
            $to = \is_string($fields['to'] ?? null) ? trim($fields['to'], " \t:") : '';
            $moves = [];
            foreach (array_keys($selected) as $path) {
                $moves[$path] = $to . ':' . self::leaf($path);
            }
            if ($to === '' || $to === $ns || \in_array(false, array_map(static fn (string $target): bool => $principal->canWrite($target), $moves), true)) {
                return $this->confirm($request, $ns, $principal, $action, $selected, $year, t('ns.bulk_err_target'), $to);
            }
            $result = $this->moves->moveMany($moves, $principal->username, $request);

            return Response::redirect($this->indexUrl($request, $ns, $year, ['done' => 'move', 'n' => \count($result['moved']), 'failed' => \count($result['failed'])]));
        }

        $tag = \is_string($fields['tag'] ?? null) ? $fields['tag'] : '';
        $add = ($fields['op'] ?? 'add') !== 'remove';
        try {
            $result = $this->tags->apply(array_keys($selected), $tag, $add, $principal->username, $request);
        } catch (InvalidArgumentException $e) {
            return $this->confirm($request, $ns, $principal, $action, $selected, $year, $e->getMessage(), $tag);
        }

        return Response::redirect($this->indexUrl($request, $ns, $year, ['done' => $add ? 'tag' : 'untag', 'n' => $result['changed'], 'signed' => $result['skippedSigned']]));
    }

    /**
     * @param array<string, array<string, mixed>> $selected path => index row
     */
    private function confirm(Request $request, string $ns, User $principal, string $action, array $selected, string $year, ?string $error, string $value): Response
    {
        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/namespace-bulk.php',
            [
                'ns' => $ns,
                'action' => $action,
                'selected' => array_values($selected),
                'year' => $year,
                'error' => $error,
                'value' => $value,
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, $ns),
            t($action === 'move' ? 'ns.bulk_move_title' : 'ns.bulk_tag_title', [\count($selected)]),
        ), $error !== null ? 422 : 200);
    }

    /**
     * @param array<string, string|int> $extra
     */
    private function indexUrl(Request $request, string $ns, string $year, array $extra = []): string
    {
        $query = array_filter(['year' => $year] + $extra, static fn (string|int $value): bool => $value !== '');

        return $request->basePath . '/' . $ns . ':' . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    private static function leaf(string $path): string
    {
        $colon = strrpos($path, ':');

        return $colon === false ? $path : substr($path, $colon + 1);
    }

    public function index(Request $request, string $ns, ?User $principal): Response
    {
        $subnamespaces = $this->index->listSubnamespaces($ns, $principal);
        $years = $this->index->listNamespaceYears($ns, $principal);
        // The year-card filter (GET /{ns}:?year=YYYY|all) — validated
        // against "YYYY" before it ever reaches SQL; anything else falls
        // through to the default. Unlike the dashboard's chips, "no param"
        // does not mean "no filter" here: with no explicit choice, a
        // namespace with more than one year defaults to its most recent
        // one (an archive like reports:ct:scuc: shouldn't dump 1000+ pages
        // in the table on first load) — "All" is its own explicit choice
        // (?year=all), same defensive-parse spirit as HomeController::
        // dashboard()'s modality/days query params.
        $yearParam = $request->query['year'] ?? '';
        if (\is_string($yearParam) && preg_match('/^\d{4}$/', $yearParam) === 1) {
            $year = $yearParam;
            $yearFilter = $yearParam;
        } elseif ($yearParam === 'all') {
            $year = null;
            $yearFilter = 'all';
        } elseif (\count($years) > 1) {
            // Only with cards to pick another year from: one year would hide
            // the undated pages with no "All" card to bring them back
            $year = (string) $years[0]['year'];
            $yearFilter = $year;
        } else {
            $year = null;
            $yearFilter = 'all';
        }
        $pages = $this->index->listNamespace($ns, $principal, $year);

        if ($subnamespaces === [] && $pages === []) {
            // A year filter (explicit or defaulted) can legitimately empty
            // an existing namespace's table (this caller's other years) —
            // only 404 (invariant 9) once the *unfiltered* namespace is
            // confirmed empty too.
            if ($year === null || $this->index->listNamespace($ns, $principal) === []) {
                throw new PageNotFoundException();
            }
        }

        // Root ($ns === '') has no leading segment to prefix — "_index",
        // not ":_index", which assertValidPath() would reject as a leading
        // empty segment.
        $indexPath = $ns === '' ? '_index' : $ns . ':_index';
        $templatePath = $ns === '' ? '_template' : $ns . ':_template';
        // Reserved pages ride along in listNamespace()'s plain "ns = :ns"
        // query like any other page — pulled out here so they render as
        // the mockup's dedicated cards instead of also showing up as rows
        // in the pages table.
        // A sub-namespace's description (the page named like it) is that
        // sub-namespace's row above, called by its title — not a page here too
        $described = array_map(static fn (array $sub): string => $ns === '' ? (string) $sub['name'] : $ns . ':' . $sub['name'], $subnamespaces);
        $pages = array_values(array_filter(
            $pages,
            static fn (array $page): bool => $page['path'] !== $indexPath && $page['path'] !== $templatePath && !\in_array($page['path'], $described, true)
        ));
        // Under reports: newest study first — a worklist of patients, not an
        // alphabet; same day by path (its {yymmdd}-name), undated pages last.
        // The table then shows the exam date instead of the last update.
        $isReports = $ns === 'reports' || str_starts_with($ns, 'reports:');
        if ($isReports) {
            usort($pages, static function (array $a, array $b): int {
                $da = substr((string) ($a['study_date'] ?? ''), 0, 10);
                $db = substr((string) ($b['study_date'] ?? ''), 0, 10);
                if (($da === '') !== ($db === '')) {
                    return $da === '' ? 1 : -1;
                }

                return [$db, (string) $b['path']] <=> [$da, (string) $a['path']];
            });
        }

        $nsIndex = $this->index->findByPath($indexPath, $principal);
        $nsTemplate = $this->index->findByPath($templatePath, $principal);
        // The description: the page named like the namespace (a page and a
        // namespace may share a name — `reports:mri:mioveni` describes the
        // site), else the older `{ns}:_index`
        $descriptionRowAtNs = $ns !== '' ? $this->index->findByPath($ns, $principal) : null;
        $descriptionPath = null;
        $descriptionRow = null;
        if ($descriptionRowAtNs !== null) {
            $descriptionPath = $ns;
            $descriptionRow = $descriptionRowAtNs;
        } elseif ($nsIndex !== null) {
            $descriptionPath = $indexPath;
            $descriptionRow = $nsIndex;
        }
        $descriptionRecord = $descriptionPath !== null ? $this->storage->read($descriptionPath) : null;
        $nsDescriptionHtml = $descriptionRecord !== null
            ? $this->render->toHtml($descriptionRecord->body, $request->basePath)->html
            : null;
        // A namespace with a description is called by it: `reports:mri:medicline`
        // described as "MEDIC line" is titled "MEDIC line" (2026-09-27)
        $nsLabel = $descriptionRow !== null && trim((string) ($descriptionRow['title'] ?? '')) !== '' ? trim((string) $descriptionRow['title']) : null;
        // Its tags and summary shown too (TODO 13's namespace-frontmatter
        // idea, the display half only — already generic page fields, no
        // schema change; the "visibility as the default for new pages
        // underneath" behaviour is not built, that needs its own decision).
        // tags is a list field (a page_tags child table, not a `pages`
        // column, D29-style), so read from disk rather than the index row.
        $nsTags = $descriptionRecord !== null
            ? array_values(array_filter((array) ($descriptionRecord->frontmatter['tags'] ?? []), 'is_string'))
            : [];
        $nsSummary = $descriptionRow !== null && trim((string) ($descriptionRow['summary'] ?? '')) !== '' ? trim((string) $descriptionRow['summary']) : null;
        $nsVisibility = $descriptionRow !== null ? (string) ($descriptionRow['visibility'] ?? '') : '';
        // …and so are the sub-namespaces listed here, each by its own description
        // (title, and its summary as the card's subtitle — TODO 13; both are
        // already generic page frontmatter, no schema change needed)
        foreach ($subnamespaces as $i => $sub) {
            $subPath = $ns === '' ? (string) $sub['name'] : $ns . ':' . $sub['name'];
            $subRow = $this->index->findByPath($subPath, $principal);
            $subnamespaces[$i]['title'] = $subRow !== null && trim((string) ($subRow['title'] ?? '')) !== '' ? trim((string) $subRow['title']) : null;
            $subnamespaces[$i]['summary'] = $subRow !== null && trim((string) ($subRow['summary'] ?? '')) !== '' ? trim((string) $subRow['summary']) : null;
            // Card background tint (TODO 13), decorative only: not an
            // indexed column (unlike title/summary above) — a plain
            // frontmatter field read from disk, same as $nsTags below,
            // because nothing here needs it searchable or sortable.
            $priority = null;
            if ($subRow !== null) {
                try {
                    $priority = trim((string) ($this->storage->read($subPath)->frontmatter['priority'] ?? ''));
                } catch (PageNotFoundException) {
                    $priority = null;
                }
            }
            $subnamespaces[$i]['priority'] = \in_array($priority, ['low', 'medium', 'high'], true) ? $priority : null;
        }

        // What a bulk action just did (bulk()'s redirect) — counts only
        $done = $request->query['done'] ?? '';
        $bulkDone = \is_string($done) && \in_array($done, self::DONE, true) ? [
            'action' => $done,
            'n' => (int) ($request->query['n'] ?? 0),
            'failed' => (int) ($request->query['failed'] ?? 0),
            'signed' => (int) ($request->query['signed'] ?? 0),
        ] : null;

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/namespace.php',
            [
                'ns' => $ns,
                'subnamespaces' => $subnamespaces,
                'pages' => $pages,
                'years' => $years,
                'yearFilter' => $yearFilter,
                'isReports' => $isReports,
                // Selecting is for signed-in callers: Export needs only read
                // access, Move and Tag also write access here
                'canSelect' => $principal !== null && $pages !== [],
                'canBulkWrite' => $principal?->canWrite($ns) ?? false,
                'bulkDone' => $bulkDone,
                'recent' => $this->index->listWorklist($ns, $principal, 8),
                'canCreateHere' => $principal?->canWrite($ns) ?? false,
                'basePath' => $request->basePath,
                'nsIndex' => $nsIndex,
                'nsTemplate' => $nsTemplate,
                'nsDescriptionHtml' => $nsDescriptionHtml,
                'descriptionPath' => $descriptionPath,
                'nsLabel' => $nsLabel,
                'nsTags' => $nsTags,
                'nsSummary' => $nsSummary,
                'nsVisibility' => $nsVisibility,
            ] + ChromeVars::shell($request, $principal, $this->index, $ns),
            $nsLabel ?? ($ns === '' ? t('ns.root_title') : $ns . ':'),
        ));
    }
}
