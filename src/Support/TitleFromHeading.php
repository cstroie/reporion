<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The first `# ` heading of a markdown body: a page's title when its
 * frontmatter has none (Support\PageTitle). A report's `#` is its patient's
 * name (D30).
 */
final class TitleFromHeading
{
    /** The text of $body's first ATX level-1 heading outside fenced code, or null */
    public static function first(string $body): ?string
    {
        foreach (self::headings($body) as [$level, $text]) {
            if ($level === 1) {
                return $text;
            }
        }

        return null;
    }

    /**
     * The ATX level-1 and level-2 headings of $body outside fenced code, in
     * order, those with text only: [level, text].
     *
     * @return list<array{int, string}>
     */
    public static function headings(string $body): array
    {
        $found = [];
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
            if (preg_match('/^ {0,3}(#{1,2})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/u', $line, $m) === 1) {
                $text = trim($m[2] ?? '');
                if ($text !== '') {
                    $found[] = [\strlen($m[1]), $text];
                }
            }
        }

        return $found;
    }
}
