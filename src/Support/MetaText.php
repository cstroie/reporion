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
}
