<?php

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A patient's name as a report stores it: "TURLEA, Viorela" and "turlea viorela"
 * both become "Turlea Viorela" (commas dropped, one space between words, each
 * word, and each part of a hyphenated one, capitalised).
 */
final class PersonName
{
    public static function normalize(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', str_replace(',', ' ', $name)));

        return $name === '' ? '' : mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }
}
