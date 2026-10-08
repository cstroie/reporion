<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A stopgap `summary` for reports saved without the assistant's: the first
 * sentence of the first paragraph of the first conclusion section
 * (Support\Conclusion — any heading level, "Concluzii", "Conclusion"…).
 * Filled on a user's save (editor and PUT/POST /api/v1/pages) while
 * `summary` is empty, and **kept in step** while it is still the stopgap
 * itself — equal to what this would have made from the revision being
 * replaced. A summary typed by hand or written by the assistant differs
 * from that, so it is never touched; neither is one when the new text has
 * no conclusion to take it from.
 */
final class ConclusionSummary
{
    /**
     * @param array<string, mixed> $frontmatter
     * @param ?string              $previousBody the revision being replaced, when there is one
     *
     * @return array<string, mixed> $frontmatter, with `summary` filled or refreshed when it may be and the text has a conclusion
     */
    public static function fill(string $path, array $frontmatter, string $body, ?string $previousBody = null): array
    {
        if (!ReportPath::isReport($path)) {
            return $frontmatter;
        }
        $current = trim(MetaText::text($frontmatter['summary'] ?? null));
        if ($current !== '' && !self::isStopgap($current, $previousBody)) {
            return $frontmatter;
        }
        $summary = self::extract($body);
        if ($summary !== null) {
            $frontmatter['summary'] = $summary;
        }

        return $frontmatter;
    }

    /** Whether $summary is what the stopgap makes of $body — a summary nobody wrote */
    public static function isStopgap(string $summary, ?string $body): bool
    {
        return $body !== null && $summary !== '' && $summary === self::extract($body);
    }

    public static function extract(string $body): ?string
    {
        $section = Conclusion::first($body);

        return $section === null ? null : self::firstSentence(self::firstParagraph(preg_split('/\R/u', $section) ?: []));
    }

    /**
     * The lines of the first paragraph, up to a blank line or the next
     * heading; when it is a list, its first item only.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function firstParagraph(array $lines): array
    {
        $paragraph = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($paragraph !== []) {
                    break;
                }
                continue;
            }
            if (preg_match('/^ {0,3}#{1,6}(\s|$)/u', $line) === 1) {
                break;
            }
            $item = preg_match('/^([-*+]|\d+[.)])\s+/u', $trimmed) === 1;
            if ($item && $paragraph !== []) {
                break;
            }
            $paragraph[] = $item ? (string) preg_replace('/^([-*+]|\d+[.)])\s+/u', '', $trimmed) : $trimmed;
        }

        return $paragraph;
    }

    /**
     * @param list<string> $paragraph
     */
    private static function firstSentence(array $paragraph): ?string
    {
        $text = implode(' ', $paragraph);
        // Plain text: link text without its target, no emphasis or code marks
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
        $text = str_replace(['**', '__', '`'], '', $text);
        $text = (string) preg_replace('/(?<!\w)\*(\S[^*]*)\*(?!\w)/u', '$1', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        // A sentence ends at . ! ? … followed by a capital letter — so "cca. 5 mm"
        // or "2.5 cm" does not cut it short
        if (preg_match('/^(.+?[.!?…])(?=\s+["„(]?\p{Lu})/u', $text, $m) === 1) {
            $text = $m[1];
        }

        return $text !== '' ? $text : null;
    }
}
