<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Whether a page is a library source (docs/library.md, D58): it carries a bibliographic record
 * (`csl`) or an original (`original`). Such a page is never public, wherever it is moved:
 * publishing it would republish the article (owner, 2026-10-30). Storage enforces it on every
 * write; the editor and the visibility screen say it first.
 */
final class LibrarySource
{
    /** @param array<string, mixed> $frontmatter */
    public static function isSource(array $frontmatter): bool
    {
        return \is_array($frontmatter['csl'] ?? null) || \is_array($frontmatter['original'] ?? null);
    }

    /** @param array<string, mixed> $frontmatter */
    public static function refusesPublic(array $frontmatter): bool
    {
        return ($frontmatter['visibility'] ?? null) === 'public' && self::isSource($frontmatter);
    }
}
