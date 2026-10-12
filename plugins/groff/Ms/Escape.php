<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Groff\Ms;

/**
 * Text into groff source. A document is UTF-8 (groff -K utf-8), so only
 * what troff itself reads is touched: the backslash, a control line's
 * leading `.` or `'`, a leading space (it breaks the line), the grave accent
 * (an opening quote in troff), and a minus sign, which is `\-` rather than
 * the hyphen of "bi-parietal" or a date. Everything is on one line: a
 * newline in the text is the caller's decision, never the text's.
 */
final class Escape
{
    /** Inline text: no newline survives, every special character is made harmless */
    public static function text(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = str_replace('\\', '\\e', $text);
        $text = str_replace('`', '\\(ga', $text);
        // A minus sign ("-5 mm"), not the hyphen of a word, a date or a range
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])-(?=\d)/u', '\\-', $text);
        // The nbsp stays a no-break space, the others collapse
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);

        return $text;
    }

    /**
     * One output line: leading blanks go, and a `.` or `'` at its start
     * (which would be a request) is shielded with the zero-width `\&`.
     */
    public static function line(string $line): string
    {
        $line = ltrim($line, " \t");

        return $line !== '' && ($line[0] === '.' || $line[0] === "'") ? '\\&' . $line : $line;
    }

    /**
     * One line of a no-fill block (code, a text page): spaces and their count are the text's own,
     * a tab becomes eight spaces, and every minus is a minus; a request-looking start is shielded.
     */
    public static function literal(string $line): string
    {
        $line = str_replace("\t", '        ', (string) preg_replace('/[\x00-\x08\x0A-\x1F\x7F]+/u', ' ', $line));
        $line = str_replace(['\\', '`', '-'], ['\\e', '\\(ga', '\\-'], $line);

        return $line !== '' && ($line[0] === '.' || $line[0] === "'") ? '\\&' . $line : $line;
    }

    /** A comment line: the text can never end the comment or start a request */
    public static function comment(string $text): string
    {
        return '.\\" ' . trim((string) preg_replace('/[\x00-\x1F\x7F\\\\]+/u', ' ', $text));
    }
}
