<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Render;
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
 * view() uses, so nothing new to build here). Deliberately NOT ported:
 * bulk select/move/tag/export/visibility (no such service exists) and
 * "recent activity here" (needs an audit log, not built yet) — see
 * docs/BUILD_LOG.md. The mockup's persistent tree sidebar
 * (design/mockup/WikiTree.dc.html) is a separate, chrome-level concern, not
 * part of this route.
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
    public function __construct(
        private readonly IndexInterface $index,
        private readonly StorageInterface $storage,
        private readonly Render $render,
    ) {
    }

    public function index(Request $request, string $ns, ?User $principal): Response
    {
        $subnamespaces = $this->index->listSubnamespaces($ns, $principal);
        $pages = $this->index->listNamespace($ns, $principal);

        if ($subnamespaces === [] && $pages === []) {
            throw new PageNotFoundException();
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

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/namespace.php',
            [
                'ns' => $ns,
                'subnamespaces' => $subnamespaces,
                'pages' => $pages,
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
