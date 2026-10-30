<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * What a library source cites (docs/library.md, phase 7): its reference list (the `text` fence
 * under `## References` that Service\Library\Library keeps at the end of the page) cut into
 * entries, and each matched against the library's records. Exact by DOI or PMID; by title only
 * when the library's title is long enough to be unlikely by chance, stands whole in the entry
 * (folded: case, diacritics, punctuation) and the entry has the record's year: a loose match
 * would be a wrong link. Pure.
 */
final class ReferenceList
{
    /** The most entries read */
    public const MAX = 300;

    /** A title shorter than this (folded) is never matched by title */
    private const MIN_TITLE = 25;

    /** An entry is shown up to this many characters */
    private const SHOW = 400;

    /** The reference list's text, or '' when the page has none */
    public static function of(string $body): string
    {
        if (preg_match('/\n## References\n\n(`{3,}|~{3,})text\n(.*?)\n\1\s*$/su', "\n" . $body, $m) !== 1) {
            return '';
        }

        return trim($m[2]);
    }

    /**
     * The entries: numbered ones (`1.`, `[1]`, `1)`) with their wrapped lines joined; otherwise
     * paragraphs, or each line when there is no blank line at all
     *
     * @return list<string>
     */
    public static function entries(string $references): array
    {
        $lines = preg_split('/\R/u', trim($references)) ?: [];
        $numbered = '/^\s*(?:\[\d{1,4}\]|\d{1,4}[.)])\s+/u';
        $entries = [];
        if (\count(preg_grep($numbered, $lines) ?: []) >= max(2, (int) (\count($lines) / 10))) {
            foreach ($lines as $line) {
                if (preg_match($numbered, $line) === 1 || $entries === []) {
                    $entries[] = trim((string) preg_replace($numbered, '', $line));
                } elseif (trim($line) !== '') {
                    $entries[\count($entries) - 1] .= ' ' . trim($line);
                }
            }
        } elseif (preg_match('/\n\s*\n/u', $references) === 1) {
            $entries = array_map(static fn (string $p): string => trim((string) preg_replace('/\s+/u', ' ', $p)), preg_split('/\n\s*\n/u', $references) ?: []);
        } else {
            $entries = array_map('trim', $lines);
        }
        $entries = array_values(array_filter(array_map(static fn (string $e): string => trim((string) preg_replace('/\s+/u', ' ', $e)), $entries), static fn (string $e): bool => mb_strlen($e) >= 10));

        return \array_slice($entries, 0, self::MAX);
    }

    /**
     * Each entry with the library record it names, if any
     *
     * @param list<array{key: string, path: string, title: string, csl: array<string, mixed>}> $records the library's, as the caller reads it
     *
     * @return list<array{text: string, doi: ?string, match: ?array{key: string, path: string, title: string, by: string}}>
     */
    public static function match(array $entries, array $records): array
    {
        $byDoi = [];
        $byPmid = [];
        $byTitle = [];
        foreach ($records as $r) {
            $doi = \is_string($r['csl']['DOI'] ?? null) ? ArticleIds::normalizeDoi($r['csl']['DOI']) : null;
            if ($doi !== null) {
                $byDoi[$doi] = $r;
            }
            $pmid = \is_scalar($r['csl']['PMID'] ?? null) ? (string) $r['csl']['PMID'] : '';
            if ($pmid !== '') {
                $byPmid[$pmid] = $r;
            }
            $title = self::fold(\is_string($r['csl']['title'] ?? null) ? $r['csl']['title'] : $r['title']);
            if (mb_strlen($title) >= self::MIN_TITLE) {
                $byTitle[] = [$title, Citation::year($r['csl']), $r];
            }
        }
        $out = [];
        foreach ($entries as $entry) {
            $ids = ArticleIds::find($entry);
            $doi = $ids['doi'][0] ?? null;
            $match = null;
            if ($doi !== null && isset($byDoi[$doi])) {
                $match = [$byDoi[$doi], 'doi'];
            }
            foreach ($ids['pmid'] as $pmid) {
                if ($match === null && isset($byPmid[$pmid])) {
                    $match = [$byPmid[$pmid], 'pmid'];
                }
            }
            if ($match === null) {
                $folded = self::fold($entry);
                foreach ($byTitle as [$title, $year, $r]) {
                    if ($year !== null && str_contains(' ' . $folded . ' ', ' ' . $title . ' ') && preg_match('/\b' . $year . '\b/', $entry) === 1) {
                        $match = [$r, 'title'];
                        break;
                    }
                }
            }
            $out[] = [
                'text' => mb_strimwidth($entry, 0, self::SHOW, '…'),
                'doi' => $doi,
                'match' => $match !== null ? ['key' => $match[0]['key'], 'path' => $match[0]['path'], 'title' => $match[0]['title'], 'by' => $match[1]] : null,
            ];
        }

        return $out;
    }

    /** Lower case, no diacritics, letters and digits only, single spaces */
    private static function fold(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', RecallTerms::fold($text)));
    }
}
