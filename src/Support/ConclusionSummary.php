<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A stopgap `summary` for reports saved without one: the first sentence of
 * the first paragraph under the first heading that starts with "concluz"
 * (Concluzie, Concluzii — any level). Filled on a user's save (editor and
 * PUT/POST /api/v1/pages) only while `summary` is empty, so once a report
 * has one — typed or taken from here — later saves leave it alone.
 */
final class ConclusionSummary
{
    /**
     * @param array<string, mixed> $frontmatter
     *
     * @return array<string, mixed> $frontmatter, with `summary` filled when it was empty and the text has one
     */
    public static function fill(string $path, array $frontmatter, string $body): array
    {
        if (!ReportPath::isReport($path) || trim(MetaText::text($frontmatter['summary'] ?? null)) !== '') {
            return $frontmatter;
        }
        $summary = self::extract($body);
        if ($summary !== null) {
            $frontmatter['summary'] = $summary;
        }

        return $frontmatter;
    }

    public static function extract(string $body): ?string
    {
        $lines = preg_split('/\R/u', $body) ?: [];
        $count = \count($lines);
        for ($i = 0; $i < $count; ++$i) {
            if (preg_match('/^ {0,3}#{1,6}\s+(.*)$/u', $lines[$i], $m) !== 1) {
                continue;
            }
            if (preg_match('/^[\s*_]*concluz/iu', $m[1]) !== 1) {
                continue;
            }

            return self::firstSentence(self::firstParagraph(\array_slice($lines, $i + 1)));
        }

        return null;
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
