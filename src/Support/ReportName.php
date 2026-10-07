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
 * name heading out: the name never reaches a public page or a teaching
 * copy, and a report's own export names the patient once, in its patient
 * block (D1/D30 as amended 2026-09-27).
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
     * $body without a first heading that is the patient's name — `#` in the
     * normalized shape (docs/FORMATS.md §11), `##`/`###` in an imported
     * report not normalized yet.
     *
     * @param array<string, mixed> $frontmatter
     */
    public static function withoutNameHeading(string $body, array $frontmatter): string
    {
        if (preg_match('/\A\s*#{1,6}[ \t]+(.+?)[ \t#]*(?:\R|\z)/u', $body, $m) === 1 && self::isPatientName($m[1], $frontmatter)) {
            return ltrim(substr($body, \strlen($m[0])), "\r\n");
        }

        return $body;
    }

    /**
     * $body as an export or the public layout prints it under the exam
     * title: no name heading, and no exam heading either when the report
     * has a single `##` exam whose text is that title — it would print the
     * title twice. A multi-exam report keeps every exam heading.
     *
     * @param array<string, mixed> $frontmatter
     */
    public static function forExport(string $body, array $frontmatter): string
    {
        $body = self::withoutNameHeading($body, $frontmatter);
        $title = self::fold(self::examTitle($frontmatter));
        if ($title === '' || preg_match_all('/^ {0,3}##[ \t]+(.+?)[ \t#]*$/mu', $body, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return $body;
        }
        if (self::fold($m[1][0][0]) !== $title) {
            return $body;
        }
        $start = $m[0][0][1];
        $end = $start + \strlen($m[0][0][0]);
        if (preg_match('/\G\R(?:[ \t]*\R)?/', $body, $gap, 0, $end) === 1) {
            $end += \strlen($gap[0]);
        }

        return substr($body, 0, $start) . substr($body, $end);
    }

    /**
     * The heading is the patient's name: their `patient.name`, or — in a
     * report of the app's shape, `exam_title` holding the exam (D30 as amended
     * 2026-09-26) — its `title`, which is the name too and may be spelled
     * otherwise than the name a HIS or a PACS filled in. An imported report's
     * `title` is its exam, never taken for the name.
     *
     * @param array<string, mixed> $frontmatter
     */
    private static function isPatientName(string $text, array $frontmatter): bool
    {
        if (!\is_array($frontmatter['patient'] ?? null)) {
            return false;
        }
        $heading = self::fold($text);
        $names = [MetaText::text($frontmatter['patient']['name'] ?? null)];
        if (MetaText::text($frontmatter['exam_title'] ?? null) !== '') {
            $names[] = MetaText::text($frontmatter['title'] ?? null);
        }
        foreach ($names as $name) {
            if ($name !== '' && $heading === self::fold($name)) {
                return true;
            }
        }

        return false;
    }

    private static function fold(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
