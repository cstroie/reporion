<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * RIS (the tagged format of EndNote, Mendeley, Zotero and PubMed's export) to and from library
 * records (CSL-JSON, docs/library.md). An entry is {key, csl, tags}; the key is the `ID` tag's
 * when there is one. Pure.
 */
final class Ris
{
    private const TYPES = [
        'JOUR' => 'article-journal', 'JFULL' => 'article-journal', 'EJOUR' => 'article-journal', 'BOOK' => 'book', 'EBOOK' => 'book',
        'CHAP' => 'chapter', 'ECHAP' => 'chapter', 'CONF' => 'paper-conference', 'CPAPER' => 'paper-conference', 'RPRT' => 'report',
        'THES' => 'thesis', 'ELEC' => 'webpage', 'WEB' => 'webpage', 'UNPB' => 'manuscript', 'DATA' => 'dataset', 'STAND' => 'standard',
        'GEN' => 'article',
    ];

    private const WRITE_TYPES = [
        'article-journal' => 'JOUR', 'book' => 'BOOK', 'chapter' => 'CHAP', 'paper-conference' => 'CONF', 'report' => 'RPRT',
        'thesis' => 'THES', 'webpage' => 'ELEC', 'manuscript' => 'UNPB', 'dataset' => 'DATA', 'standard' => 'STAND', 'article' => 'GEN', 'review' => 'JOUR',
    ];

    /** @return list<array{key: string, csl: array<string, mixed>, tags: list<string>}> */
    public static function parse(string $text): array
    {
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $entries = [];
        $tags = [];
        $open = false;
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (preg_match('/^([A-Z][A-Z0-9])  ?-\s?(.*)$/', $line, $m) !== 1) {
                // A long value continued on the next line
                if ($open && trim($line) !== '' && $tags !== []) {
                    $last = array_key_last($tags);
                    $tags[$last][\count($tags[$last]) - 1] .= ' ' . trim($line);
                }
                continue;
            }
            [$tag, $value] = [$m[1], trim($m[2])];
            if ($tag === 'TY') {
                $tags = [];
                $open = true;
            }
            if ($tag === 'ER') {
                if ($open && ($entry = self::entry($tags)) !== null) {
                    $entries[] = $entry;
                }
                $tags = [];
                $open = false;
                continue;
            }
            if ($open && $value !== '') {
                $tags[$tag][] = $value;
            }
        }

        return $entries;
    }

    /** @param list<array{key: string, csl: array<string, mixed>, tags?: list<string>}> $entries */
    public static function write(array $entries): string
    {
        $out = '';
        foreach ($entries as $entry) {
            $csl = $entry['csl'];
            $lines = ['TY  - ' . (self::WRITE_TYPES[(string) ($csl['type'] ?? '')] ?? 'JOUR'), 'ID  - ' . $entry['key']];
            $lines[] = 'TI  - ' . self::line((string) ($csl['title'] ?? ''));
            foreach (\is_array($csl['author'] ?? null) ? $csl['author'] : [] as $p) {
                if (\is_array($p)) {
                    $lines[] = 'AU  - ' . self::line(($p['family'] ?? '') !== '' ? $p['family'] . (($p['given'] ?? '') !== '' ? ', ' . $p['given'] : '') : (string) ($p['literal'] ?? ''));
                }
            }
            $year = Citation::year($csl);
            if ($year !== null) {
                $parts = $csl['issued']['date-parts'][0];
                $lines[] = 'PY  - ' . $year;
                $lines[] = 'DA  - ' . $year . (isset($parts[1]) ? '/' . sprintf('%02d', $parts[1]) . (isset($parts[2]) ? '/' . sprintf('%02d', $parts[2]) : '') : '');
            }
            $pages = preg_split('/\s*[-–]+\s*/u', (string) ($csl['page'] ?? ''), 2) ?: [];
            foreach ([
                'JO' => $csl['container-title'] ?? '', 'JA' => $csl['container-title-short'] ?? '', 'VL' => $csl['volume'] ?? '', 'IS' => $csl['issue'] ?? '',
                'SP' => $pages[0] ?? '', 'EP' => $pages[1] ?? '', 'DO' => $csl['DOI'] ?? '', 'AB' => $csl['abstract'] ?? '', 'UR' => $csl['URL'] ?? '',
                'PB' => $csl['publisher'] ?? '', 'CY' => $csl['publisher-place'] ?? '', 'SN' => $csl['ISSN'] ?? $csl['ISBN'] ?? '', 'AN' => isset($csl['PMID']) ? 'PMID:' . $csl['PMID'] : '',
            ] as $tag => $value) {
                if ((string) $value !== '') {
                    $lines[] = $tag . '  - ' . self::line((string) $value);
                }
            }
            foreach ($entry['tags'] ?? [] as $tag) {
                $lines[] = 'KW  - ' . self::line($tag);
            }
            $out .= implode("\r\n", $lines) . "\r\nER  - \r\n\r\n";
        }

        return $out;
    }

    /**
     * @param array<string, list<string>> $t
     *
     * @return array{key: string, csl: array<string, mixed>, tags: list<string>}|null
     */
    private static function entry(array $t): ?array
    {
        $first = static function (string ...$tags) use ($t): string {
            foreach ($tags as $tag) {
                if (($t[$tag][0] ?? '') !== '') {
                    return $t[$tag][0];
                }
            }

            return '';
        };
        $title = $first('TI', 'T1', 'CT');
        if ($title === '') {
            return null;
        }
        $csl = ['type' => self::TYPES[$t['TY'][0] ?? ''] ?? 'article-journal', 'title' => $title];
        $authors = [];
        foreach (array_merge($t['AU'] ?? [], $t['A1'] ?? []) as $name) {
            $parts = array_map('trim', explode(',', $name));
            $authors[] = \count($parts) > 1 && $parts[0] !== '' ? array_filter(['family' => $parts[0], 'given' => $parts[1]], static fn (string $v): bool => $v !== '') : ['literal' => $name];
        }
        if ($authors !== []) {
            $csl['author'] = $authors;
        }
        $map = ['container-title' => ['JO', 'JF', 'T2', 'BT'], 'container-title-short' => ['JA', 'J2'], 'volume' => ['VL'], 'issue' => ['IS'], 'DOI' => ['DO'], 'abstract' => ['AB', 'N2'], 'URL' => ['UR'], 'publisher' => ['PB'], 'publisher-place' => ['CY']];
        foreach ($map as $field => $tags) {
            $value = $first(...$tags);
            if ($value !== '') {
                $csl[$field] = $value;
            }
        }
        $sn = $first('SN');
        if ($sn !== '') {
            $csl[\in_array($csl['type'], ['book', 'chapter'], true) ? 'ISBN' : 'ISSN'] = $sn;
        }
        $start = $first('SP');
        if ($start !== '') {
            $csl['page'] = $start . (($end = $first('EP')) !== '' ? '-' . $end : '');
        }
        if (preg_match('/(?:PMID:?\s*)?(\d{1,9})$/', $first('AN'), $m) === 1 && stripos($first('AN'), 'pmid') !== false) {
            $csl['PMID'] = $m[1];
        }
        if (preg_match('/^(1[5-9]\d\d|20\d\d)(?:[\/-](\d{1,2}))?/', $first('PY', 'Y1', 'DA'), $y) === 1) {
            $date = [(int) $y[1]];
            if (isset($y[2]) && (int) $y[2] >= 1 && (int) $y[2] <= 12) {
                $date[] = (int) $y[2];
            }
            $csl['issued'] = ['date-parts' => [$date]];
        }

        return ['key' => $first('ID'), 'csl' => $csl, 'tags' => $t['KW'] ?? []];
    }

    private static function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
