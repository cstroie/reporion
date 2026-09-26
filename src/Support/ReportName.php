<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A report is titled by its patient on screen and by its exam everywhere
 * else (D30 as amended 2026-09-26): a report created in the app has the
 * patient's name as `title` and as its first `#` heading — how the team
 * finds it — and the exam title ("IRM Cerebral") as `exam_title`. Exports,
 * the public layout and a duplicate use the exam title and leave the
 * name heading out, so the name never leaves through a PDF, a public page
 * or a teaching copy (invariant 8).
 */
final class ReportName
{
    /**
     * The title to show where the patient must not appear: `exam_title`
     * when there is one, else `title` unless that is the patient's name.
     *
     * @param array<string, mixed> $frontmatter
     */
    public static function examTitle(array $frontmatter, string $fallback = ''): string
    {
        $exam = MetaText::text($frontmatter['exam_title'] ?? null);
        if ($exam !== '') {
            return $exam;
        }
        $title = MetaText::text($frontmatter['title'] ?? null);

        return $title !== '' && !self::isPatientName($title, $frontmatter) ? $title : $fallback;
    }

    /**
     * $body without a first `#` heading that is the patient's name.
     *
     * @param array<string, mixed> $frontmatter
     */
    public static function withoutNameHeading(string $body, array $frontmatter): string
    {
        if (preg_match('/\A\s*#[ \t]+(.+?)[ \t#]*(?:\R|\z)/u', $body, $m) === 1 && self::isPatientName($m[1], $frontmatter)) {
            return ltrim(substr($body, \strlen($m[0])), "\r\n");
        }

        return $body;
    }

    /** @param array<string, mixed> $frontmatter */
    private static function isPatientName(string $text, array $frontmatter): bool
    {
        $name = \is_array($frontmatter['patient'] ?? null) ? MetaText::text($frontmatter['patient']['name'] ?? null) : '';

        return $name !== '' && self::fold($text) === self::fold($name);
    }

    private static function fold(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
