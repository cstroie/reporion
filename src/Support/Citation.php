<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A library record (CSL-JSON, docs/library.md) as a reference: Vancouver, the medical style
 * (ICMJE/NLM: "Nobody A, Nobody BB. Title. J Abbrev. 2024;3(2):11-14. doi:…"), and the short
 * forms the library's lists show. APA and AMA come with the citations (phase 3). Pure.
 */
final class Citation
{
    /** Vancouver names six authors, then "et al." */
    private const VANCOUVER_AUTHORS = 6;

    /** @param array<string, mixed> $csl */
    public static function vancouver(array $csl): string
    {
        $parts = [];
        $authors = self::people($csl['author'] ?? null);
        if ($authors !== []) {
            $names = array_map(self::vancouverName(...), \array_slice($authors, 0, self::VANCOUVER_AUTHORS));
            $parts[] = implode(', ', $names) . (\count($authors) > self::VANCOUVER_AUTHORS ? ', et al.' : '.');
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
