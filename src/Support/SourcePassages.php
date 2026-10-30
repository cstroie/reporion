<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A text cut into the passages Ask the notebook and Studio's check choose from (docs/library.md,
 * phases 5 and 7): about PASSAGE characters, cut at headings, paragraphs and sentences
 * (SourceChunks::split()), each with the page marker in force where it starts. The one place that
 * cuts them: `index:vectors` embeds a library source's passages by their place `n`, and the query
 * side reads the vector of passage `n` back for the same text (its sha says so), so the two must
 * never cut differently. Pure.
 */
final class SourcePassages
{
    /** A passage: about this many characters */
    public const PASSAGE = 1200;

    /**
     * @return list<array{n: int, page: ?int, text: string}>
     */
    public static function cut(string $text): array
    {
        $parts = [];
        foreach (SourceChunks::split($text, self::PASSAGE) as $part) {
            $words = mb_strlen(trim((string) preg_replace(self::MARKER, '', $part)));
            // A page marker alone says nothing
            if ($words === 0) {
                continue;
            }
            // A short tail joins the passage before it, when they fit together
            $last = \count($parts) - 1;
            if ($words < self::SHORT && $last >= 0 && mb_strlen($parts[$last]) + mb_strlen($part) + 2 <= (int) (self::PASSAGE * 1.25)) {
                $parts[$last] .= "\n\n" . $part;
                continue;
            }
            $parts[] = $part;
        }
        $out = [];
        foreach ($parts as $n => $part) {
            // A part opens with the page marker in force where it starts (SourceChunks::split())
            $out[] = ['n' => $n, 'page' => preg_match(self::MARKER, $part, $m) === 1 ? (int) $m[1] : null, 'text' => $part];
        }

        return $out;
    }

    /** A page marker (Support\SourceText) */
    private const MARKER = '/\*— p\. (\d+) —\*/u';

    /** A part with fewer characters than this, markers aside, is joined to the one before */
    private const SHORT = 200;

    /**
     * A library source's passages: its body without the reference list it keeps at the end
     *
     * @return list<array{n: int, page: ?int, text: string}>
     */
    public static function ofSource(string $body): array
    {
        return self::cut(SourceChunks::withoutReferences($body));
    }
}
