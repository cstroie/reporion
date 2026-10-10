<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A page body's format (docs/FORMATS.md §3j, D40): markdown — the default,
 * the key absent — or `format: text`, plain text shown exactly as typed
 * (Service\Render::body()). Anything else in the key reads as markdown, so
 * a typo in raw mode never hides a page's text.
 */
final class BodyFormat
{
    public const KEY = 'format';
    public const MARKDOWN = 'markdown';
    public const TEXT = 'text';

    /** @param array<string, mixed> $frontmatter */
    public static function of(array $frontmatter): string
    {
        return self::isText($frontmatter) ? self::TEXT : self::MARKDOWN;
    }

    /** @param array<string, mixed> $frontmatter */
    public static function isText(array $frontmatter): bool
    {
        return ($frontmatter[self::KEY] ?? null) === self::TEXT;
    }
}
