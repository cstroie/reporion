<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Whether a page is a library source (docs/library.md, D58): it carries a bibliographic record
 * (`csl`) or an original (`original`). Such a page is always private, wherever it is moved: public,
 * or unlisted (readable by anyone with the link), would republish the article; grants decide who
 * reads it (owner, 2026-10-30). Storage enforces it on every write; the editor and the visibility
 * screen say it first.
 */
final class LibrarySource
{
    /** @param array<string, mixed> $frontmatter */
    public static function isSource(array $frontmatter): bool
    {
        return \is_array($frontmatter['csl'] ?? null) || \is_array($frontmatter['original'] ?? null);
    }

    /** @param array<string, mixed> $frontmatter */
    public static function refusesVisibility(array $frontmatter): bool
    {
        return self::isSource($frontmatter) && ($frontmatter['visibility'] ?? 'private') !== 'private';
    }
}
