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
        $pages = array_values(array_filter(
            $pages,
            static fn (array $page): bool => $page['path'] !== $indexPath && $page['path'] !== $templatePath
        ));

        $nsIndex = $this->index->findByPath($indexPath, $principal);
        $nsTemplate = $this->index->findByPath($templatePath, $principal);
        $nsDescriptionHtml = null;
        if ($nsIndex !== null) {
            $nsDescriptionHtml = $this->render->toHtml($this->storage->read($indexPath)->body, $request->basePath)->html;
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
            ] + ChromeVars::shell($request, $principal, $this->index, $ns),
            $ns === '' ? t('ns.root_title') : $ns . ':',
        ));
    }
}
