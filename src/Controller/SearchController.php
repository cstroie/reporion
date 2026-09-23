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
            $stripped = self::stripMarkdownForSnippet((string) ($result['snippet'] ?? ''));
            $result['snippet_html'] = Sqlite::highlightSnippet($stripped);
        }
        unset($result);

        $html = View::render(\dirname(__DIR__, 2) . '/templates/search-results.php', [
            'term' => $term,
            'results' => $results,
            'basePath' => $request->basePath,
        ]);

        return Response::html($html);
    }

    /**
     * snippet() extracts raw markdown body text, so a result fragment came
     * back with visible "##" heading markers and blank lines (caught live:
     * the mockup's single-line .wk-row-s made it obvious). A search result
     * is a text fragment, not a document — this strips heading markers and
     * collapses newlines rather than running it through the full renderer,
     * which would produce nested block markup a one-line row isn't built
     * for. Emphasis/list markers can still slip through; a known, narrower
     * remainder of the same class of issue, not fixed here.
     */
    private static function stripMarkdownForSnippet(string $raw): string
    {
        $lines = array_map(
            static fn (string $line): string => preg_replace('/^\s{0,3}#{1,6}\s+/', '', $line) ?? $line,
            explode("\n", $raw)
        );

        return trim(preg_replace('/\s+/', ' ', implode(' ', $lines)) ?? implode(' ', $lines));
    }
}
