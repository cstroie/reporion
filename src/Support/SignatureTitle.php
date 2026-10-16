<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * An account's signature title, one line in the form: a "|" marks where a printed
 * signature block breaks the line ("Medic primar | Radiologie-Imagistică Medicală").
 */
final class SignatureTitle
{
    public const BREAK = '|';

    /** @return list<string> the title's lines, trimmed, none empty */
    public static function lines(string $title): array
    {
        return array_values(array_filter(
            array_map('trim', explode(self::BREAK, $title)),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /** The title on one line, for places that cannot break it */
    public static function inline(string $title): string
    {
        return implode(' · ', self::lines($title));
    }
}
