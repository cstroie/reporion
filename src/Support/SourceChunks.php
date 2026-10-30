<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A library source's text cut to fit a model's context (docs/library.md, "Long sources"): whole
 * when it fits, else in parts at its headings (then paragraphs, then sentences), each part opening
 * with the page marker in force where it starts so a fact can still be traced to its page. Pure.
 */
final class SourceChunks
{
    /** Characters per token, low on purpose: a table of figures or Romanian text costs more tokens than English prose */
    public const CHARS_PER_TOKEN = 3;

    /** Tokens kept for the prompt page and the answer */
    public const RESERVED_TOKENS = 3000;

    /** The text a request of $contextTokens can hold, in characters */
    public static function budget(int $contextTokens): int
    {
        return max(2000, ($contextTokens - self::RESERVED_TOKENS) * self::CHARS_PER_TOKEN);
    }

    /** The article without the reference list the library keeps at the end of its page (Service\Library\Library::body()) */
    public static function withoutReferences(string $body): string
    {
        $cut = strrpos($body, "\n## References\n\n```");
        $body = $cut === false ? $body : substr($body, 0, $cut);

        return trim($body);
    }

    /**
     * @return list<string> one part when $text fits $max
     */
    public static function split(string $text, int $max): array
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text === '' ? [] : [$text];
        }
        // Sections first: a heading line starts one
        $units = preg_split('/(?=^#{1,3}[ \t]+\S)/mu', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $pieces = [];
        foreach ($units as $unit) {
            foreach (self::fit($unit, $max) as $piece) {
                $pieces[] = $piece;
            }
        }
        $parts = [];
        $current = '';
        $page = null;
        foreach ($pieces as $piece) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($piece) + 2 > $max) {
                $parts[] = $current;
                $current = '';
            }
            if ($current === '' && $page !== null && preg_match('/\A\s*\*— p\. \d+ —\*/u', $piece) !== 1) {
                $piece = '*— p. ' . $page . ' —*' . "\n\n" . $piece;
            }
            $current = $current === '' ? $piece : $current . "\n\n" . $piece;
            if (preg_match_all('/\*— p\. (\d+) —\*/u', $piece, $m) > 0) {
                $page = (int) end($m[1]);
            }
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /** @return list<string> $unit cut at paragraphs, then sentences, then anywhere, into pieces of at most $max */
    private static function fit(string $unit, int $max): array
    {
        $unit = trim($unit);
        if (mb_strlen($unit) <= $max) {
            return $unit === '' ? [] : [$unit];
        }
        $pieces = [];
        foreach (preg_split('/\n{2,}/u', $unit, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $paragraph) {
            if (mb_strlen($paragraph) <= $max) {
                $pieces[] = trim($paragraph);
                continue;
            }
            $current = '';
            foreach (preg_split('/(?<=[.!?])\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sentence) {
                while (mb_strlen($sentence) > $max) {
                    $pieces[] = mb_substr($sentence, 0, $max);
                    $sentence = mb_substr($sentence, $max);
                }
                if ($current !== '' && mb_strlen($current) + mb_strlen($sentence) + 1 > $max) {
                    $pieces[] = $current;
                    $current = '';
                }
                $current = $current === '' ? $sentence : $current . ' ' . $sentence;
            }
            if ($current !== '') {
                $pieces[] = $current;
            }
        }

        return $pieces;
    }

    /** The page of the last marker `*— p. N —*` at or before $offset in $text, or null */
    public static function pageAt(string $text, int $offset): ?int
    {
        $before = substr($text, 0, $offset);

        return preg_match_all('/\*— p\. (\d+) —\*/u', $before, $m) > 0 ? (int) end($m[1]) : null;
    }
}
