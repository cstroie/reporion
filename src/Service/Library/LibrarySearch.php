<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Support\SourcePassages;

/**
 * The library's own text search (2026-11-02, docs/library.md): the articles holding every word of
 * a query, in their text (passage_fts: the best passage's snippet and page) or in their record
 * (the pages' full text: title, summary, tags), the two fused by rank (RRF, k = 60, as the asks).
 * Only library sources (`{library}:{key}`, a page with a record), under the caller's visibility
 * (invariant 6); never a report, never a saved analysis or abstract page beside a source. Reads the
 * index only.
 */
final class LibrarySearch
{
    private const RRF = 60;

    /** The most articles one search lists */
    public const LIMIT = 100;

    public function __construct(
        private readonly Sqlite $index,
        private readonly string $libraryNamespace,
    ) {
    }

    /**
     * @return list<array{pid: string, path: string, key: string, title: string, snippet: string, page: ?int, in: string}>
     *         `snippet` raw (Sqlite::highlightSnippet() for HTML); `in`: text (a passage) or record
     */
    public function search(string $query, ?User $principal, int $limit = self::LIMIT): array
    {
        $query = trim(mb_substr($query, 0, 200));
        if ($query === '') {
            return [];
        }
        $prefix = $this->libraryNamespace . ':';
        $sources = array_flip($this->index->sourcePids($this->libraryNamespace, $principal));

        $found = [];
        $rrf = [];
        $rank = 0;
        foreach ($this->index->passagesMatching($query, $principal) as $p) {
            if (isset($found[$p['pid']]) || !isset($sources[$p['pid']])) {
                continue;
            }
            // The best passage of each article stands for it, its page the one where the words are
            [$snippet, $page] = self::snippet($p['snippet'], $p['page']);
            $found[$p['pid']] = ['pid' => $p['pid'], 'path' => $p['path'], 'key' => substr($p['path'], \strlen($prefix)), 'title' => $p['title'], 'snippet' => $snippet, 'page' => $page, 'in' => 'text'];
            $rrf[$p['pid']] = 1 / (self::RRF + ++$rank);
        }
        $rank = 0;
        foreach ($this->index->search($query, $principal, 'relevance', $this->libraryNamespace, [], 200) as $row) {
            $path = (string) $row['path'];
            $pid = (string) $row['pid'];
            if (!isset($sources[$pid])) {
                continue;
            }
            $found[$pid] ??= ['pid' => $pid, 'path' => $path, 'key' => substr($path, \strlen($prefix)), 'title' => (string) $row['title'], 'snippet' => self::snippet((string) $row['snippet'], null)[0], 'page' => null, 'in' => 'record'];
            $rrf[$pid] = ($rrf[$pid] ?? 0.0) + 1 / (self::RRF + ++$rank);
        }
        arsort($rrf);

        return array_values(array_map(static fn (string $pid): array => $found[$pid], \array_slice(array_map('strval', array_keys($rrf)), 0, max(1, $limit))));
    }

    /**
     * A raw snippet as one line of text, its page markers out, and the page the first match is on:
     * the last marker before it, else the passage's own (the marker in force where it starts)
     *
     * @return array{0: string, 1: ?int}
     */
    private static function snippet(string $raw, ?int $page): array
    {
        $before = strstr($raw, Sqlite::SNIPPET_OPEN, true);
        if ($before !== false && preg_match_all(SourcePassages::MARKER, $before, $m) > 0) {
            $page = (int) end($m[1]);
        }
        $text = (string) preg_replace(SourcePassages::MARKER, ' ', $raw);
        // Headings' hashes and table pipes say nothing in one line
        $text = (string) preg_replace(['/(^|\n)\s{0,3}#{1,6}\s+/u', '/\s*\|\s*/u'], ['$1', ' '], $text);

        return [trim((string) preg_replace('/\s+/u', ' ', $text)), $page];
    }
}
