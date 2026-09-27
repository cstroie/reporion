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
        $descriptionPath = null;
        if ($ns !== '' && $this->index->findByPath($ns, $principal) !== null) {
            $descriptionPath = $ns;
        } elseif ($nsIndex !== null) {
            $descriptionPath = $indexPath;
        }
        $nsDescriptionHtml = $descriptionPath !== null
            ? $this->render->toHtml($this->storage->read($descriptionPath)->body, $request->basePath)->html
            : null;
        // A namespace with a description is called by it: `reports:mri:medicline`
        // described as "MEDIC line" is titled "MEDIC line" (2026-09-27)
        $nsLabel = $descriptionPath !== null ? $this->titleOf($descriptionPath, $principal) : null;
        // …and so are the sub-namespaces listed here, each by its own description
        foreach ($subnamespaces as $i => $sub) {
            $subnamespaces[$i]['title'] = $this->titleOf($ns === '' ? (string) $sub['name'] : $ns . ':' . $sub['name'], $principal);
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
            ] + ChromeVars::shell($request, $principal, $this->index, $ns),
            $nsLabel ?? ($ns === '' ? t('ns.root_title') : $ns . ':'),
        ));
    }

    /** The title of the page at $path the caller can read, or null when there is none (or no title) */
    private function titleOf(string $path, ?User $principal): ?string
    {
        $row = $this->index->findByPath($path, $principal);
        $title = $row !== null ? trim((string) ($row['title'] ?? '')) : '';

        return $title !== '' ? $title : null;
    }
}
