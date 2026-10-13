<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A page's title: the frontmatter `title` when one is set (an override, for a
 * display that differs from the heading), else the text of the body's first
 * `# ` heading. Nothing writes `title` on the author's behalf, and a heading
 * edited later changes the title with it (2026-10-13). A text page (D40) has
 * no headings; '' means the caller falls back to the path.
 */
final class PageTitle
{
    /** @param array<string, mixed> $frontmatter */
    public static function of(array $frontmatter, string $body): string
    {
        $title = trim(MetaText::text($frontmatter['title'] ?? null));
        if ($title !== '') {
            return $title;
        }

        return BodyFormat::isText($frontmatter) ? '' : (TitleFromHeading::first($body) ?? '');
    }
}
