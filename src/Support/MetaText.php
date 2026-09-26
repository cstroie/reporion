<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use DateTimeImmutable;
use Exception;

/**
 * Frontmatter values as display text. Frontmatter is hand-edited YAML, so a
 * field can arrive as a string, int (`born: 1970`, or an unquoted date that
 * Symfony YAML turns into a Unix timestamp), bool, list or map — a template
 * must never assume a string and 500 on anything else.
 */
final class MetaText
{
    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            \is_bool($value) => $value ? 'yes' : 'no',
            \is_scalar($value) => (string) $value,
            \is_array($value) => implode(', ', array_filter(
                array_map(self::text(...), $value),
                static fn (string $part): bool => $part !== ''
            )),
            default => '',
        };
    }

    /**
     * A date/time field formatted with $format; an unparseable value is
     * shown as written rather than hidden or turned into an error.
     */
    public static function date(mixed $value, string $format): string
    {
        try {
            if (\is_int($value)) {
                return (new DateTimeImmutable('@' . $value))->format($format);
            }
            if (\is_string($value) && trim($value) !== '') {
                return (new DateTimeImmutable($value))->format($format);
            }
        } catch (Exception) {
            // fall through to the raw text
        }

        return self::text($value);
    }

    /**
     * A date that may carry a time: "$dateFormat$timeFormat" when the value
     * really has a time, just the date when it does not — a bare date
     * ("2026-09-23"), an exact midnight, or an unquoted YAML date (a Unix
     * timestamp at midnight UTC). A study date is often entered without a
     * time, and "00:00" would claim one.
     */
    public static function dateTime(mixed $value, string $dateFormat, string $timeFormat): string
    {
        return self::date($value, self::hasTime($value) ? $dateFormat . $timeFormat : $dateFormat);
    }

    private static function hasTime(mixed $value): bool
    {
        if (\is_int($value)) {
            return $value % 86400 !== 0;
        }
        if (!\is_string($value) || preg_match('/[T ](\d{1,2}):(\d{2})(?::(\d{2}))?/', $value, $m) !== 1) {
            return false;
        }

        return (int) $m[1] !== 0 || (int) $m[2] !== 0 || (int) ($m[3] ?? 0) !== 0;
    }

    /**
     * How a moment is shown on screen, everywhere: "24 Sep 2026, 01:42", or
     * "24 Sep 2026" when no real time was given — never raw ISO 8601.
     */
    public static function when(mixed $value): string
    {
        return self::dateTime($value, 'd M Y', ', H:i');
    }
}
