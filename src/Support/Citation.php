<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A library record (CSL-JSON, docs/library.md) as a reference: Vancouver, the medical style
 * (ICMJE/NLM: "Nobody A, Nobody BB. Title. J Abbrev. 2024;3(2):11-14. doi:…"), AMA and APA 7
 * (plain text: no italics), the in-text label of each, and the short forms the library's lists
 * show. Pure.
 */
final class Citation
{
    /** Vancouver names six authors, then "et al." */
    private const VANCOUVER_AUTHORS = 6;

    /** AMA names six, and above six the first three */
    private const AMA_AUTHORS = 6;
    private const AMA_SHORT = 3;

    /** APA names twenty, and above twenty the first nineteen, "…" and the last */
    private const APA_AUTHORS = 20;

    public const STYLES = ['vancouver', 'apa', 'ama'];

    /** A style a page names, else Vancouver */
    public static function style(mixed $name): string
    {
        $name = \is_string($name) ? strtolower(trim($name)) : '';

        return \in_array($name, self::STYLES, true) ? $name : 'vancouver';
    }

    /** @param array<string, mixed> $csl */
    public static function format(string $style, array $csl): string
    {
        return match ($style) {
            'apa' => self::apa($csl),
            'ama' => self::ama($csl),
            default => self::vancouver($csl),
        };
    }

    /**
     * The in-text form of an author-date style: "Nobody & Somebody, 2024" (APA); a numbered style
     * has the number instead (Service\Render)
     *
     * @param array<string, mixed> $csl
     */
    public static function apaLabel(array $csl): string
    {
        $authors = self::people($csl['author'] ?? null);
        $year = self::year($csl);
        $family = static fn (array $p): string => ($p['family'] ?? '') !== '' ? $p['family'] : ($p['literal'] ?? '');
        $who = match (true) {
            $authors === [] => self::text($csl['title'] ?? null),
            \count($authors) === 1 => $family($authors[0]),
            \count($authors) === 2 => $family($authors[0]) . ' & ' . $family($authors[1]),
            default => $family($authors[0]) . ' et al.',
        };

        return $who . ', ' . ($year ?? 'n.d.');
    }

    /** @param array<string, mixed> $csl */
    public static function ama(array $csl): string
    {
        return self::numbered($csl, self::AMA_AUTHORS, self::AMA_SHORT);
    }

    /** @param array<string, mixed> $csl */
    public static function apa(array $csl): string
    {
        $authors = self::people($csl['author'] ?? null);
        $names = array_map(self::apaName(...), $authors);
        if (\count($names) > self::APA_AUTHORS) {
            $names = [...\array_slice($names, 0, self::APA_AUTHORS - 1), '… ' . $names[\count($names) - 1]];
            $who = implode(', ', $names);
        } elseif (\count($names) > 1) {
            $last = array_pop($names);
            $who = implode(', ', $names) . ', & ' . $last;
        } else {
            $who = $names[0] ?? '';
        }
        $year = self::year($csl);
        $title = self::text($csl['title'] ?? null);
        $type = self::text($csl['type'] ?? null);
        $parts = [];
        if ($who !== '') {
            $parts[] = self::sentence($who);
        }
        $parts[] = '(' . ($year ?? 'n.d.') . ').';
        if ($type === 'book' || $type === 'report' || $type === 'thesis') {
            $parts[] = self::sentence($title);
            $publisher = self::text($csl['publisher'] ?? null);
            if ($publisher !== '') {
                $parts[] = self::sentence($publisher);
            }
        } else {
            if ($title !== '') {
                $parts[] = self::sentence($title);
            }
            $journal = self::text($csl['container-title'] ?? null);
            $volume = self::text($csl['volume'] ?? null);
            $issue = self::text($csl['issue'] ?? null);
            $page = str_replace('-', '–', self::text($csl['page'] ?? null));
            $where = $journal . ($volume !== '' ? ($journal !== '' ? ', ' : '') . $volume : '') . ($issue !== '' ? '(' . $issue . ')' : '') . ($page !== '' ? ', ' . $page : '');
            if ($where !== '') {
                $parts[] = $where . '.';
            }
        }
        $doi = self::text($csl['DOI'] ?? null);
        if ($doi !== '') {
            $parts[] = 'https://doi.org/' . $doi;
        }

        return trim(implode(' ', array_filter($parts, static fn (string $p): bool => $p !== '')));
    }

    /** @param array<string, mixed> $csl */
    public static function vancouver(array $csl): string
    {
        return self::numbered($csl, self::VANCOUVER_AUTHORS, self::VANCOUVER_AUTHORS);
    }

    /**
     * Vancouver and AMA differ in the authors only: up to $max named, above it $short and "et al."
     *
     * @param array<string, mixed> $csl
     */
    private static function numbered(array $csl, int $max, int $short): string
    {
        $parts = [];
        $authors = self::people($csl['author'] ?? null);
        if ($authors !== []) {
            $shown = \count($authors) > $max ? $short : \count($authors);
            $names = array_map(self::vancouverName(...), \array_slice($authors, 0, $shown));
            $parts[] = implode(', ', $names) . (\count($authors) > $max ? ', et al.' : '.');
        }
        $title = self::text($csl['title'] ?? null);
        if ($title !== '') {
            $parts[] = self::sentence($title);
        }
        $year = self::year($csl);
        $type = self::text($csl['type'] ?? null);
        if ($type === 'book' || $type === 'report' || $type === 'thesis') {
            $edition = self::text($csl['edition'] ?? null);
            if ($edition !== '') {
                $parts[] = self::sentence($edition . (ctype_digit($edition) ? self::ordinal((int) $edition) . ' ed' : ''));
            }
            $place = self::text($csl['publisher-place'] ?? null);
            $publisher = self::text($csl['publisher'] ?? null);
            $imprint = trim(($place !== '' ? $place . ': ' : '') . $publisher);
            $parts[] = ($imprint !== '' ? $imprint . ($year !== null ? '; ' : '') : '') . ($year ?? '') . ($imprint !== '' || $year !== null ? '.' : '');
        } else {
            $journal = self::text($csl['container-title-short'] ?? null);
            $journal = $journal !== '' ? $journal : self::text($csl['container-title'] ?? null);
            if ($journal !== '') {
                $parts[] = self::sentence($journal);
            }
            $volume = self::text($csl['volume'] ?? null);
            $issue = self::text($csl['issue'] ?? null);
            $page = self::text($csl['page'] ?? null);
            $where = ($year ?? '')
                . ($volume !== '' ? ';' . $volume : '')
                . ($issue !== '' ? '(' . $issue . ')' : '')
                . ($page !== '' ? ':' . $page : '');
            if ($where !== '') {
                $parts[] = $where . '.';
            }
        }
        $doi = self::text($csl['DOI'] ?? null);
        if ($doi !== '') {
            $parts[] = 'doi:' . $doi;
        }

        return trim(implode(' ', array_filter($parts, static fn (string $p): bool => $p !== '')));
    }

    /**
     * "Nobody A, Somebody B, et al." for a list: at most $max names
     *
     * @param array<string, mixed> $csl
     */
    public static function authorsShort(array $csl, int $max = 3): string
    {
        $authors = self::people($csl['author'] ?? null);
        $names = array_map(self::vancouverName(...), \array_slice($authors, 0, $max));

        return implode(', ', $names) . (\count($authors) > $max ? ', et al.' : '');
    }

    /** @param array<string, mixed> $csl */
    public static function year(array $csl): ?int
    {
        $year = $csl['issued']['date-parts'][0][0] ?? null;

        return is_numeric($year) ? (int) $year : null;
    }

    /** @return list<array<string, string>> */
    private static function people(mixed $authors): array
    {
        $people = [];
        foreach (\is_array($authors) ? $authors : [] as $person) {
            if (\is_array($person) && (self::text($person['family'] ?? null) !== '' || self::text($person['literal'] ?? null) !== '')) {
                $people[] = array_map(self::text(...), array_filter($person, 'is_scalar'));
            }
        }

        return $people;
    }

    /** @param array<string, string> $person "Nobody BB": the family name and the given names' initials */
    private static function vancouverName(array $person): string
    {
        if (($person['family'] ?? '') === '') {
            return $person['literal'] ?? '';
        }
        $initials = '';
        foreach (preg_split('/[\s.\-]+/u', $person['given'] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
            $initials .= mb_strtoupper(mb_substr($name, 0, 1));
        }

        return $person['family'] . ($initials !== '' ? ' ' . $initials : '');
    }

    /** @param array<string, string> $person "Nobody, A. B.": APA's family name and initials with stops */
    private static function apaName(array $person): string
    {
        if (($person['family'] ?? '') === '') {
            return $person['literal'] ?? '';
        }
        $initials = [];
        foreach (preg_split('/[\s.]+/u', $person['given'] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
            foreach (explode('-', $name) as $i => $bit) {
                if ($bit !== '') {
                    $initials[] = mb_strtoupper(mb_substr($bit, 0, 1)) . '.';
                }
            }
        }

        return $person['family'] . ($initials !== [] ? ', ' . implode(' ', $initials) : '');
    }

    /** A part ending in its full stop, unless it ends in its own ? or ! */
    private static function sentence(string $text): string
    {
        return preg_match('/[.?!]$/u', $text) === 1 ? $text : $text . '.';
    }

    private static function ordinal(int $n): string
    {
        return match (true) {
            $n % 100 >= 11 && $n % 100 <= 13 => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default => 'th',
        };
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }
}
