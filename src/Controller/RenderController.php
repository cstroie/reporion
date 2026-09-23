<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

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
 * Owner-only: the API has exactly one authenticated caller plus anonymous
 * readers of public pages (docs/architecture-api.md §3), and this is
 * compute-on-demand, not reading a page — it is not on the public surface
 * (Table 4). Refused as 404, not 401/403, for the same reason a private
 * page is (invariant 9): this route's existence is not owner-only
 * information worth confirming to an anonymous caller either.
 */
final class RenderController
{
    public function __construct(
        private readonly Render $render,
    ) {
    }

    public function render(Request $request, bool $isOwner): Response
    {
        if (!$isOwner) {
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
