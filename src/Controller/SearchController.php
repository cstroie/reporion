<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Index\Sqlite;

/**
 * GET /search?q= (docs/architecture-api.md §1 Table 1): "first result page
 * rendered so the URL is shareable; facets then live" — this is that first
 * page only. The palette (⌘K) and live facets are a JS island, later,
 * separate work; this route works with JS disabled.
 */
final class SearchController
{
    public function __construct(
        private readonly IndexInterface $index,
    ) {
    }

    public function search(Request $request, bool $isOwner): Response
    {
        $term = trim($request->query['q'] ?? '');
        $results = $term !== '' ? $this->index->search($term, $isOwner) : [];

        // snippet() returns raw body text, not HTML — this is the one place
        // that turns it into something safe for search-results.php to echo
        // directly, so the template never has to think about it.
        foreach ($results as &$result) {
            $result['snippet_html'] = Sqlite::highlightSnippet((string) ($result['snippet'] ?? ''));
        }
        unset($result);

        $html = View::render(\dirname(__DIR__, 2) . '/templates/search-results.php', [
            'term' => $term,
            'results' => $results,
        ]);

        return Response::html($html);
    }
}
