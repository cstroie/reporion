<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A library source's citation key, which is also its slug (docs/library.md): `[@nobody2024]`
 * is the page `library:nobody2024`. The first author's family name and the year, folded to
 * a-z0-9; a second one taken gets `b`, then `c` (as pandoc and BibTeX users write them).
 * No author: the title's first word that is not an article; no year: `nd`.
 */
final class CitationKey
{
    private const SKIP = ['a', 'an', 'the', 'of', 'on', 'in', 'for', 'and', 'to', 'un', 'o', 'despre', 'la', 'le', 'les', 'der', 'die', 'das'];

    /** A key the writer may type: a letter first, then letters, digits and hyphens */
    public const PATTERN = '/^[a-z][a-z0-9-]{1,62}$/';

    /**
     * @param array<string, mixed>   $csl
     * @param callable(string): bool $taken whether a key is in use already
     */
    public static function make(array $csl, callable $taken): string
    {
        $base = self::base($csl);
        if (!$taken($base)) {
            return $base;
        }
        foreach (range('b', 'z') as $suffix) {
            if (!$taken($base . $suffix)) {
                return $base . $suffix;
            }
        }
        for ($n = 2; ; ++$n) {
            if (!$taken($base . '-' . $n)) {
                return $base . '-' . $n;
            }
        }
    }

    public static function isValid(string $key): bool
    {
        return preg_match(self::PATTERN, $key) === 1;
    }

    /** @param array<string, mixed> $csl */
    private static function base(array $csl): string
    {
        $name = '';
        $first = \is_array($csl['author'][0] ?? null) ? $csl['author'][0] : [];
        if (\is_string($first['family'] ?? null)) {
            $name = self::letters($first['family']);
        } elseif (\is_string($first['literal'] ?? null)) {
            $name = self::letters($first['literal']);
        }
        if ($name === '' && \is_string($csl['title'] ?? null)) {
            foreach (preg_split('/\s+/u', $csl['title']) ?: [] as $word) {
                $folded = self::letters($word);
                if ($folded !== '' && !\in_array($folded, self::SKIP, true)) {
                    $name = $folded;
                    break;
                }
            }
        }
        if ($name === '' || !ctype_alpha($name[0])) {
            $name = 'source' . $name;
        }
        $year = $csl['issued']['date-parts'][0][0] ?? null;
        $year = is_numeric($year) ? (string) (int) $year : 'nd';

        return substr($name, 0, 40) . $year;
    }

    private static function letters(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Slug::fold($text));
    }
}
