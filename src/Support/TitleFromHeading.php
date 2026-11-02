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
     * $body without the line of its first level-1 heading (the one first()
     * finds): for a print or an export that shows the title above the text
     * already. Unchanged when there is none.
     */
    public static function withoutFirst(string $body): string
    {
        $lines = preg_split('/(?<=\n)/', $body) ?: [];
        foreach (self::scan($lines) as [$index, $level]) {
            if ($level === 1) {
                unset($lines[$index]);

                return ltrim(implode('', $lines), "\r\n");
            }
        }

        return $body;
    }

    /**
     * The ATX level-1 and level-2 headings of $body outside fenced code, in
     * order, those with text only: [level, text].
     *
     * @return list<array{int, string}>
     */
    public static function headings(string $body): array
    {
        $lines = preg_split('/\R/', $body) ?: [];

        return array_map(static fn (array $h): array => [$h[1], $h[2]], self::scan($lines));
    }

    /**
     * The level-1 and level-2 headings among $lines outside fenced code:
     * [line index, level, text].
     *
     * @param list<string> $lines
     * @return list<array{int, int, string}>
     */
    private static function scan(array $lines): array
    {
        $found = [];
        $fence = null;
        foreach ($lines as $index => $line) {
            $line = rtrim($line, "\r\n");
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
                    $found[] = [$index, \strlen($m[1]), $text];
                }
            }
        }

        return $found;
    }
}
