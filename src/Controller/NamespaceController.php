<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;

/**
 * GET /{ns}: — namespace index (docs/architecture-api.md Table 1). Ported
 * from design/mockup/WikiNsIndex.dc.html, but only the parts that have a
 * backend: sub-namespace cards (Index\Sqlite::listSubnamespaces()) and a
 * plain pages table (Index\Sqlite::listNamespace()). Deliberately NOT
 * ported: bulk select/move/tag/export/visibility (no such service exists),
 * "recent activity here" (needs an audit log, not built yet), and the
 * "namespace description" panel (would read an `_index` page convention
 * that does not exist) — see docs/BUILD_LOG.md. The mockup's persistent
 * tree sidebar (design/mockup/WikiTree.dc.html) is a separate, chrome-level
 * concern, not part of this route.
 *
 * Same invariant-6 shape as PageController::view(): both listing calls go
 * through Search\Query::visibilityClause() before disk or an empty result
 * is ever distinguishable from "does not exist" — a namespace with zero
 * visible sub-namespaces and zero visible pages for this caller 404s the
 * same as one that was never created (invariant 9).
 */
final class NamespaceController
{
    public function __construct(
        private readonly IndexInterface $index,
    ) {
    }

    public function index(Request $request, string $ns, ?User $principal): Response
    {
        $subnamespaces = $this->index->listSubnamespaces($ns, $principal);
        $pages = $this->index->listNamespace($ns, $principal);

        if ($subnamespaces === [] && $pages === []) {
            throw new PageNotFoundException();
        }

        return Response::html(View::render(
            \dirname(__DIR__, 2) . '/templates/namespace.php',
            [
                'ns' => $ns,
                'subnamespaces' => $subnamespaces,
                'pages' => $pages,
                'canCreate' => $principal?->canWrite($ns) ?? false,
                'basePath' => $request->basePath,
            ]
        ));
    }
}
