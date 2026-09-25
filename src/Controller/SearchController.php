<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Http\ApiResponse;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Index\Sqlite;

/**
 * GET /search?q= (docs/architecture-api.md §1 Table 1): "first result page
 * rendered so the URL is shareable; facets then live" — this is that first
 * page only. Live facets are still not built. The palette (⌘K) is —
 * `suggest()` below is what it calls.
 */
final class SearchController
{
    public function __construct(
        private readonly IndexInterface $index,
    ) {
    }

    public function search(Request $request, ?User $principal): Response
    {
        $term = trim($request->query['q'] ?? '');
        $results = $term !== '' ? $this->index->search($term, $principal) : [];

        // snippet() returns raw body text, not HTML — this is the one place
        // that turns it into something safe for search-results.php to echo
        // directly, so the template never has to think about it.
        foreach ($results as &$result) {
            $stripped = self::stripMarkdownForSnippet((string) ($result['snippet'] ?? ''));
            $result['snippet_html'] = Sqlite::highlightSnippet($stripped);
        }
        unset($result);

        $html = View::page(\dirname(__DIR__, 2) . '/templates/search-results.php', [
            'term' => $term,
            'searchTerm' => $term,
            'results' => $results,
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('search.title'));

        return Response::html($html);
    }

    /**
     * GET /api/v1/search?q= — the palette's JSON typeahead
     * (docs/architecture-api.md §"What each island actually needs":
     * editor/palette local state). Same visibility/grant rules as the SSR
     * route (same `IndexInterface::search()` call), reachable anonymously.
     * Deliberately minimal against the documented shape: no `facets`,
     * `score`, `took_ms`, filters or a `/search/suggest`-specific
     * paths/tags/commands index — this is query-as-you-type against the
     * same full-text index the SSR page already uses, nothing more.
     */
    public function suggest(Request $request, ?User $principal): Response
    {
        $term = trim($request->query['q'] ?? '');
        $results = $term !== '' ? $this->index->search($term, $principal) : [];

        $data = array_map(static fn (array $result): array => [
            'pid' => $result['pid'],
            'path' => $result['path'],
            'title' => $result['title'],
            'visibility' => $result['visibility'],
            // Plain text, not the SSR route's snippet_html — a JSON
            // consumer decides its own rendering. plainSnippet() drops the
            // sentinel markers outright (Sqlite::highlightSnippet()'s
            // <mark> substitution is meaningless outside HTML, and the raw
            // \x02/\x03 bytes must never reach a JSON response).
            'snippet' => self::stripMarkdownForSnippet(Sqlite::plainSnippet((string) ($result['snippet'] ?? ''))),
        ], $results);

        return ApiResponse::json(['data' => $data]);
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
