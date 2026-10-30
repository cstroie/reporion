<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The record a web page gives of itself (docs/library.md, phase 7, web clip): the `citation_*`
 * meta tags journals publish for Google Scholar (title, authors, date, journal, volume, issue,
 * pages, DOI, PMID), else Dublin Core and Open Graph, else `<title>`; as CSL-JSON fields. Only
 * the page's head is read, by pattern (no DOM, no network, no entity beyond HTML's own). Pure.
 */
final class HtmlMeta
{
    /** Only the start of the page is read: the head is there */
    private const HEAD = 200000;

    /** @return array<string, mixed> CSL-JSON fields; empty when the page names nothing */
    public static function csl(string $html): array
    {
        $html = substr($html, 0, self::HEAD);
        $meta = [];
        preg_match_all('/<meta\s[^>]*>/i', $html, $tags);
        foreach ($tags[0] as $tag) {
            $name = self::attr($tag, 'name') ?? self::attr($tag, 'property');
            $content = self::attr($tag, 'content');
            if ($name !== null && $content !== null && $content !== '') {
                $meta[strtolower($name)][] = $content;
            }
        }
        $first = static fn (string ...$names): ?string => array_values(array_filter(array_map(static fn (string $n): ?string => $meta[$n][0] ?? null, $names)))[0] ?? null;
        $csl = [];
        $title = $first('citation_title', 'dc.title', 'og:title');
        if ($title === null && preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $m) === 1) {
            $title = $m[1];
        }
        if ($title !== null && ($title = self::text($title)) !== '') {
            $csl['title'] = $title;
        }
        $authors = [];
        foreach ($meta['citation_author'] ?? $meta['dc.creator'] ?? [] as $name) {
            $name = self::text($name);
            if ($name === '') {
                continue;
            }
            if (str_contains($name, ',')) {
                [$family, $given] = array_map('trim', explode(',', $name, 2));
            } else {
                $parts = preg_split('/\s+/u', $name) ?: [$name];
                $family = (string) array_pop($parts);
                $given = implode(' ', $parts);
            }
            $authors[] = array_filter(['family' => $family, 'given' => $given], static fn (string $v): bool => $v !== '');
            if (\count($authors) >= 100) {
                break;
            }
        }
        if ($authors !== []) {
            $csl['author'] = $authors;
        }
        $date = $first('citation_publication_date', 'citation_date', 'citation_online_date', 'dc.date', 'article:published_time');
        if ($date !== null && preg_match('/\b(1[89]\d\d|20\d\d)(?:[-\/](\d{1,2})(?:[-\/](\d{1,2}))?)?/', $date, $d) === 1) {
            $csl['issued'] = ['date-parts' => [array_map('intval', array_values(array_filter([$d[1], $d[2] ?? '', $d[3] ?? ''], static fn (string $v): bool => $v !== '')))]];
        }
        foreach (['container-title' => ['citation_journal_title', 'citation_conference_title', 'og:site_name'], 'volume' => ['citation_volume'], 'issue' => ['citation_issue'], 'publisher' => ['citation_publisher', 'dc.publisher'], 'PMID' => ['citation_pmid']] as $field => $names) {
            $value = $first(...$names);
            if ($value !== null && ($value = self::text($value)) !== '') {
                $csl[$field] = $value;
            }
        }
        $firstPage = $first('citation_firstpage');
        if ($firstPage !== null) {
            $last = $first('citation_lastpage');
            $csl['page'] = self::text($firstPage) . ($last !== null ? '-' . self::text($last) : '');
        }
        $doi = $first('citation_doi', 'dc.identifier', 'prism.doi');
        if ($doi !== null && ($doi = ArticleIds::normalizeDoi(self::text($doi))) !== null) {
            $csl['DOI'] = $doi;
        }

        return $csl;
    }

    private static function attr(string $tag, string $name): ?string
    {
        return preg_match('/\s' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m) === 1 ? ($m[1] !== '' ? $m[1] : ($m[2] ?? '') . ($m[3] ?? '')) : null;
    }

    private static function text(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 500);
    }
}
