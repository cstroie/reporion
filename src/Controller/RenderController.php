<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Service\Render;

/**
 * POST /render — docs/architecture-api.md §"Render — two parsers, one
 * dialect". The editor's marked.js preview and the print/PDF/ODT paths both
 * end up wanting exactly this: canonical HTML from the same PHP parser
 * (D17), over HTTP instead of a direct Render::toHtml() call.
 *
 * Any signed-in user, not owner-only (D35): this compiles caller-supplied
 * markdown with no page path and no data lookup, so there is nothing here
 * a namespace grant could scope — a viewer previewing a print rendition of
 * a page they can already read needs this exactly as much as an editor
 * previewing a draft does. Still refused as 404, not 401/403, for an
 * anonymous caller — it is compute-on-demand, not reading a page, and is
 * not on the public surface (Table 4); this route's existence is not
 * information worth confirming to an anonymous caller (invariant 9's
 * reasoning, extended past "private page").
 */
final class RenderController
{
    public function __construct(
        private readonly Render $render,
    ) {
    }

    public function render(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }

        $body = $request->json();
        $markdown = $body['markdown'] ?? null;

        if (!\is_string($markdown)) {
            return ApiResponse::error(422, 'invalid_body', '"markdown" is required and must be a string.');
        }

        $result = $this->render->toHtml($markdown);

        return ApiResponse::json([
            'html' => $result->html,
            'toc' => $result->toc,
            'warnings' => $result->warnings,
        ]);
    }
}
