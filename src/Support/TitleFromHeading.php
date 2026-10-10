<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A page saved with no title takes its first `# ` heading as one
 * (2026-10-10). Only a blank title is filled — one already set is never
 * changed — and only on a markdown page: a text page has no headings.
 * A report's `#` is its patient's name, which is its title already (D30).
 */
final class TitleFromHeading
{
    /**
     * @param array<string, mixed> $frontmatter
     *
     * @return array<string, mixed>
     */
    public static function fill(array $frontmatter, string $body): array
    {
        if (trim(MetaText::text($frontmatter['title'] ?? null)) !== '' || BodyFormat::isText($frontmatter)) {
            return $frontmatter;
        }
        $heading = self::first($body);
        if ($heading !== null) {
            $frontmatter['title'] = $heading;
        }

        return $frontmatter;
    }

    /** The text of $body's first ATX level-1 heading outside fenced code, or null */
    public static function first(string $body): ?string
    {
        $fence = null;
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                if ($fence === null) {
                    $fence = $m[1];
                } elseif ($m[1][0] === $fence[0] && \strlen($m[1]) >= \strlen($fence) && trim(substr(ltrim($line), \strlen($m[1]))) === '') {
                    $fence = null;
                }
                continue;
            }
            if ($fence !== null) {
                continue;
            }
            if (preg_match('/^ {0,3}#(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/u', $line, $m) === 1) {
                $text = trim($m[1] ?? '');
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }
}
