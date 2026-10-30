<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * BibTeX, as Mendeley, Zotero and LaTeX write it, to and from library records (CSL-JSON,
 * docs/library.md). Reading is forgiving (`@string`, `@comment`, braced and quoted values, `#`
 * joins, LaTeX accents decoded to UTF-8); writing is plain UTF-8 with the special characters
 * escaped. An entry is {key, csl, tags}; the key is the file's, unchecked. Pure.
 */
final class Bibtex
{
    private const TYPES = [
        'article' => 'article-journal', 'book' => 'book', 'inbook' => 'chapter', 'incollection' => 'chapter',
        'inproceedings' => 'paper-conference', 'conference' => 'paper-conference', 'techreport' => 'report',
        'phdthesis' => 'thesis', 'mastersthesis' => 'thesis', 'misc' => 'webpage', 'online' => 'webpage',
        'unpublished' => 'manuscript', 'manual' => 'report', 'proceedings' => 'book', 'booklet' => 'book',
    ];

    /** The types written back: the first BibTeX type for each */
    private const WRITE_TYPES = [
        'article-journal' => 'article', 'book' => 'book', 'chapter' => 'incollection', 'paper-conference' => 'inproceedings',
        'report' => 'techreport', 'thesis' => 'phdthesis', 'webpage' => 'misc', 'manuscript' => 'unpublished',
        'article' => 'misc', 'dataset' => 'misc', 'standard' => 'misc', 'review' => 'article',
    ];

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /** LaTeX accent commands as the combining mark they stand for */
    private const ACCENTS = [
        "'" => "\u{301}", '`' => "\u{300}", '^' => "\u{302}", '"' => "\u{308}", '~' => "\u{303}", '=' => "\u{304}",
        '.' => "\u{307}", 'u' => "\u{306}", 'v' => "\u{30C}", 'c' => "\u{327}", 'H' => "\u{30B}", 'k' => "\u{328}", 'r' => "\u{30A}",
    ];

    private const LETTERS = [
        'ss' => 'ß', 'o' => 'ø', 'O' => 'Ø', 'aa' => 'å', 'AA' => 'Å', 'ae' => 'æ', 'AE' => 'Æ', 'oe' => 'œ', 'OE' => 'Œ',
        'l' => 'ł', 'L' => 'Ł', 'i' => 'ı', 'j' => 'ȷ',
    ];

    /** @return list<array{key: string, csl: array<string, mixed>, tags: list<string>}> */
    public static function parse(string $text): array
    {
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $text));
        $macros = [];
        $entries = [];
        $pos = 0;
        $length = \strlen($text);
        while (($at = strpos($text, '@', $pos)) !== false) {
            if (preg_match('/\G@\s*([A-Za-z]+)\s*([{(])/', $text, $m, 0, $at) !== 1) {
                $pos = $at + 1;
                continue;
            }
            $type = strtolower($m[1]);
            $start = $at + \strlen($m[0]);
            $end = self::closing($text, $start, $m[2] === '{' ? '}' : ')', $m[2]);
            $inner = substr($text, $start, $end - $start);
            $pos = min($length, $end + 1);
            if ($type === 'comment' || $type === 'preamble') {
                continue;
            }
            if ($type === 'string') {
                foreach (self::fields($inner, $macros) as $name => $value) {
                    $macros[$name] = $value;
                }
                continue;
            }
            $comma = strpos($inner, ',');
            $key = trim($comma === false ? $inner : substr($inner, 0, $comma));
            $fields = $comma === false ? [] : self::fields(substr($inner, $comma + 1), $macros);
            $entry = self::entry($type, $key, $fields);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param list<array{key: string, csl: array<string, mixed>, tags?: list<string>}> $entries
     */
    public static function write(array $entries): string
    {
        $out = [];
        foreach ($entries as $entry) {
            $csl = $entry['csl'];
            $type = self::WRITE_TYPES[(string) ($csl['type'] ?? '')] ?? 'article';
            $fields = [];
            $names = array_map(static fn (mixed $p): string => \is_array($p) ? (($p['family'] ?? '') !== '' ? self::escape((string) $p['family']) . (($p['given'] ?? '') !== '' ? ', ' . self::escape((string) $p['given']) : '') : '{' . self::escape((string) ($p['literal'] ?? '')) . '}') : '', \is_array($csl['author'] ?? null) ? $csl['author'] : []);
            $fields['author'] = implode(' and ', array_filter($names, static fn (string $n): bool => $n !== '' && $n !== '{}'));
            $fields['title'] = self::escape((string) ($csl['title'] ?? ''));
            $container = self::escape((string) ($csl['container-title'] ?? ''));
            $fields[\in_array($type, ['incollection', 'inproceedings'], true) ? 'booktitle' : 'journal'] = $container;
            $year = Citation::year($csl);
            $fields['year'] = $year !== null ? (string) $year : '';
            $month = $csl['issued']['date-parts'][0][1] ?? null;
            $fields['month'] = is_numeric($month) ? (string) (int) $month : '';
            $fields['volume'] = self::escape((string) ($csl['volume'] ?? ''));
            $fields['number'] = self::escape((string) ($csl['issue'] ?? $csl['number'] ?? ''));
            $fields['pages'] = str_replace('-', '--', str_replace('–', '-', self::escape((string) ($csl['page'] ?? ''))));
            $fields['publisher'] = self::escape((string) ($csl['publisher'] ?? ''));
            $fields['address'] = self::escape((string) ($csl['publisher-place'] ?? ''));
            $fields['edition'] = self::escape((string) ($csl['edition'] ?? ''));
            $fields['issn'] = self::escape((string) ($csl['ISSN'] ?? ''));
            $fields['isbn'] = self::escape((string) ($csl['ISBN'] ?? ''));
            $fields['doi'] = (string) ($csl['DOI'] ?? '');
            $fields['pmid'] = (string) ($csl['PMID'] ?? '');
            $fields['url'] = (string) ($csl['URL'] ?? '');
            $fields['abstract'] = self::escape((string) ($csl['abstract'] ?? ''));
            $fields['keywords'] = implode(', ', array_map(self::escape(...), $entry['tags'] ?? []));
            $lines = [];
            foreach ($fields as $name => $value) {
                if ($value !== '') {
                    $lines[] = '  ' . str_pad($name, 9) . '= {' . ($name === 'title' ? '{' . $value . '}' : $value) . '}';
                }
            }
            $out[] = '@' . $type . '{' . preg_replace('/[^A-Za-z0-9_:\-]/', '', $entry['key']) . ",\n" . implode(",\n", $lines) . "\n}\n";
        }

        return implode("\n", $out);
    }

    /** The offset of the delimiter closing the entry that starts at $from (braces balanced) */
    private static function closing(string $text, int $from, string $close, string $open): int
    {
        $depth = 0;
        $length = \strlen($text);
        for ($i = $from; $i < $length; ++$i) {
            $c = $text[$i];
            if ($c === '\\') {
                ++$i;
            } elseif ($c === '{') {
                ++$depth;
            } elseif ($c === '}' && $depth > 0) {
                --$depth;
            } elseif ($c === $close && $depth === 0 && ($close === '}' || $open === '(')) {
                return $i;
            }
        }

        return $length;
    }

    /**
     * `name = value, name = value` of an entry (or one @string) as name => raw value (braces kept)
     *
     * @param array<string, string> $macros
     *
     * @return array<string, string>
     */
    private static function fields(string $text, array $macros): array
    {
        $fields = [];
        $i = 0;
        $length = \strlen($text);
        while ($i < $length) {
            if (preg_match('/\G[\s,]*([A-Za-z0-9_\-:]+)\s*=\s*/', $text, $m, 0, $i) !== 1) {
                break;
            }
            $i += \strlen($m[0]);
            $value = '';
            while ($i < $length) {
                $c = $text[$i];
                if ($c === '{') {
                    $end = self::closing($text, $i + 1, '}', '{');
                    $value .= substr($text, $i + 1, $end - $i - 1);
                    $i = $end + 1;
                } elseif ($c === '"') {
                    $j = $i + 1;
                    $depth = 0;
                    while ($j < $length && !($text[$j] === '"' && $depth === 0)) {
                        $depth += $text[$j] === '{' ? 1 : ($text[$j] === '}' && $depth > 0 ? -1 : 0);
                        $j += $text[$j] === '\\' ? 2 : 1;
                    }
                    $value .= substr($text, $i + 1, $j - $i - 1);
                    $i = $j + 1;
                } elseif (preg_match('/\G([A-Za-z0-9_\-:.]+)/', $text, $w, 0, $i) === 1) {
                    $word = $w[1];
                    $value .= $macros[strtolower($word)] ?? (ctype_digit($word) ? $word : (isset(self::MONTHS[strtolower($word)]) ? $word : ''));
                    $i += \strlen($word);
                } else {
                    ++$i;
                }
                if (preg_match('/\G\s*#\s*/', $text, $join, 0, $i) === 1) {
                    $i += \strlen($join[0]);
                    continue;
                }
                break;
            }
            $fields[strtolower($m[1])] = $value;
        }

        return $fields;
    }

    /** Decoded, braces and line breaks dropped: a field's text */
    private static function clean(string $raw): string
    {
        // An escaped brace is text; the others only protect capitals
        $text = self::decode(strtr($raw, ['\\{' => "\x01", '\\}' => "\x02"]));

        return trim((string) preg_replace('/\s+/u', ' ', strtr(str_replace(['{', '}'], '', $text), ["\x01" => '{', "\x02" => '}'])));
    }

    /**
     * LaTeX accents and letters as UTF-8: \'e, \'{e}, {\"o}, \c{s}, \u{a}, \^{i}, \ss, \&; Romanian
     * comma-below letters for the cedilla ones (ş → ș), which is how LaTeX writes them.
     */
    public static function decode(string $text): string
    {
        $marks = preg_quote(implode('', array_keys(self::ACCENTS)), '/');
        $text = (string) preg_replace_callback(
            '/\\\\([' . $marks . '])\s*(?:\{\\\\?([A-Za-z])\}|\\\\?([A-Za-z]))/u',
            static fn (array $m): string => ($m[2] !== '' ? $m[2] : $m[3]) === 'i' && $m[1] !== 'c' ? 'i' . self::ACCENTS[$m[1]] : ($m[2] !== '' ? $m[2] : $m[3]) . self::ACCENTS[$m[1]],
            $text
        );
        $text = (string) preg_replace_callback(
            '/\\\\([uvcHkr])(?:\s+|(?=\{))\{?([A-Za-z])\}?/u',
            static fn (array $m): string => $m[2] . self::ACCENTS[$m[1]],
            $text
        );
        $text = (string) preg_replace_callback('/\\\\(ss|aa|AA|ae|AE|oe|OE|[oOlLij])(?![A-Za-z])\s*(?:\{\})?/u', static fn (array $m): string => self::LETTERS[$m[1]], $text);
        $text = preg_replace(['/\\\\([&%$#_{}])/', '/\\\\textasciitilde\s*(?:\{\})?/', '/\\\\textasciicircum\s*(?:\{\})?/', '/\\\\textbackslash\s*(?:\{\})?/', '/(?<!\\\\)~/', '/--+/'], ['$1', '~', '^', '\\', ' ', '–'], $text) ?? $text;
        $text = class_exists(\Normalizer::class) ? (\Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text) : $text;

        return strtr($text, ['ş' => 'ș', 'Ş' => 'Ș', 'ţ' => 'ț', 'Ţ' => 'Ț']);
    }

    /**
     * @param array<string, string> $f
     *
     * @return array{key: string, csl: array<string, mixed>, tags: list<string>}|null
     */
    private static function entry(string $type, string $key, array $f): ?array
    {
        // The names keep their braces ({{WHO}} is one body) until they are split
        $names = $f['author'] ?? ($f['editor'] ?? '');
        $f = array_map(self::clean(...), $f);
        if (($f['title'] ?? '') === '') {
            return null;
        }
        $csl = ['type' => self::TYPES[$type] ?? 'article-journal', 'title' => $f['title']];
        $authors = self::people($names);
        if ($authors !== []) {
            $csl['author'] = $authors;
        }
        foreach (['journal' => 'container-title', 'booktitle' => 'container-title', 'volume' => 'volume', 'publisher' => 'publisher', 'address' => 'publisher-place', 'edition' => 'edition', 'issn' => 'ISSN', 'isbn' => 'ISBN', 'abstract' => 'abstract', 'url' => 'URL'] as $from => $to) {
            if (($f[$from] ?? '') !== '' && !isset($csl[$to])) {
                $csl[$to] = $f[$from];
            }
        }
        if (($f['number'] ?? '') !== '') {
            $csl[$csl['type'] === 'report' ? 'number' : 'issue'] = $f['number'];
        }
        if (($f['pages'] ?? '') !== '') {
            $csl['page'] = $f['pages'];
        }
        $doi = $f['doi'] ?? '';
        if ($doi !== '') {
            $csl['DOI'] = $doi;
        }
        $pmid = $f['pmid'] ?? '';
        if ($pmid !== '') {
            $csl['PMID'] = $pmid;
        }
        if (preg_match('/\b(1[5-9]\d\d|20\d\d)\b/', $f['year'] ?? ($f['date'] ?? ''), $y) === 1) {
            $date = [(int) $y[1]];
            $month = strtolower(substr(trim($f['month'] ?? ''), 0, 3));
            $number = ctype_digit($f['month'] ?? '') ? (int) $f['month'] : (self::MONTHS[$month] ?? 0);
            if ($number >= 1 && $number <= 12) {
                $date[] = $number;
            }
            $csl['issued'] = ['date-parts' => [$date]];
        }
        $tags = array_values(array_filter(array_map('trim', preg_split('/[;,]/', $f['keywords'] ?? '') ?: [])));

        return ['key' => $key, 'csl' => $csl, 'tags' => $tags];
    }

    /**
     * `Family, Given and Given Family and {{Literal Body}}` as CSL people
     *
     * @return list<array<string, string>>
     */
    public static function people(string $text): array
    {
        $people = [];
        foreach (self::splitAnd($text) as $name) {
            $name = trim($name);
            if ($name === '' || $name === 'others') {
                continue;
            }
            if (preg_match('/^\{(.*)\}$/su', $name, $m) === 1 && !str_contains($m[1], ',') && substr_count($name, '{') === 1) {
                $people[] = ['literal' => self::clean($m[1])];
                continue;
            }
            $name = self::clean($name);
            if (str_contains($name, ',')) {
                $parts = array_map('trim', explode(',', $name));
                $people[] = array_filter(['family' => $parts[0], 'given' => $parts[\count($parts) - 1] !== $parts[0] ? $parts[\count($parts) - 1] : ''], static fn (string $v): bool => $v !== '');
                continue;
            }
            $tokens = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (\count($tokens) === 1) {
                $people[] = ['literal' => $tokens[0]];
                continue;
            }
            $split = \count($tokens) - 1;
            for ($i = 1; $i < \count($tokens) - 1; ++$i) {
                if (preg_match('/^\p{Ll}/u', $tokens[$i]) === 1) {
                    $split = $i;
                    break;
                }
            }
            $people[] = ['family' => implode(' ', \array_slice($tokens, $split)), 'given' => implode(' ', \array_slice($tokens, 0, $split))];
        }

        return $people;
    }

    /** @return list<string> $text split at the ` and ` outside braces */
    private static function splitAnd(string $text): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $length = \strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $c = $text[$i];
            $depth += $c === '{' ? 1 : ($c === '}' && $depth > 0 ? -1 : 0);
            if ($depth === 0 && preg_match('/\G\s+and\s+/i', $text, $m, 0, $i) === 1) {
                $parts[] = $current;
                $current = '';
                $i += \strlen($m[0]) - 1;
                continue;
            }
            $current .= $c;
        }
        $parts[] = $current;

        return $parts;
    }

    private static function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\textbackslash{}', '&' => '\\&', '%' => '\\%', '$' => '\\$', '#' => '\\#', '_' => '\\_', '{' => '\\{', '}' => '\\}', '~' => '\\textasciitilde{}', '^' => '\\textasciicircum{}']);
    }
}
