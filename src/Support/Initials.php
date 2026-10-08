<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Two letters for the account-menu avatar (templates/layout.php): the
 * first letter of the first two real name words, skipping a leading
 * honorific ("Dr.", "Prof.", "Dr", "Mr", "Mrs", "Ms" — with or without a
 * trailing dot) so "Dr. Costin Stroie" reads "CS", not "DR". Also the
 * start page's greeting name: the honorific kept, then the family name only.
 */
final class Initials
{
    private const PREFIXES = ['dr', 'prof', 'mr', 'mrs', 'ms', 'mx'];

    public static function of(string $name): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        while ($words !== [] && \in_array(mb_strtolower(rtrim($words[0], '.')), self::PREFIXES, true)) {
            array_shift($words);
        }

        if ($words === []) {
            return '';
        }

        $letters = array_map(
            static fn (string $word): string => mb_substr($word, 0, 1),
            \array_slice($words, 0, 2)
        );

        return mb_strtoupper(implode('', $letters));
    }

    /** "Dr. Costin Stroie" → "Dr. Stroie", "Costin Stroie" → "Stroie": the honorifics, then the last word */
    public static function familyName(string $name): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        $prefix = [];
        while (\count($words) > 1 && \in_array(mb_strtolower(rtrim($words[0], '.')), self::PREFIXES, true)) {
            $prefix[] = array_shift($words);
        }

        return implode(' ', [...$prefix, ...\array_slice($words, -1)]);
    }
}
