<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The shape of a template page (D45): one `#` heading: the template's name,
 * shown in pickers, which may differ from the exam's, then one `##` heading,
 * the exam's name, which becomes the report's `## exam` and `exams[].title`,
 * then its `###` sections. A wrong shape is a warning, never a block (D7).
 */
final class TemplateHeadings
{
    /** The template's name: its first `#` heading */
    public static function name(string $body): ?string
    {
        return TitleFromHeading::first($body);
    }

    /**
     * The exam's name: the text of the first `##` heading after the `#` name
     * heading (D45). A body with no `#` is of the old shape, whose `##` are
     * sections (Descriere, Tehnica…), so it names no exam: null.
     */
    public static function exam(string $body): ?string
    {
        $named = false;
        foreach (TitleFromHeading::headings($body) as [$level, $text]) {
            if ($level === 1) {
                $named = true;
            } elseif ($named) {
                return $text;
            }
        }

        return null;
    }

    /** $body without its first line when that is the template's `#` heading: what a report's body starts without */
    public static function withoutName(string $body): string
    {
        return preg_replace('/\A\s*# [^\n]*\n*/', '', $body) ?? $body;
    }

    /**
     * What is wrong with the shape, as codes the Metadata view and `doctor`
     * word: `name.missing`, `name.many`, `exam.missing`, `exam.many`,
     * `order` (a `##` before the `#`). A text template (D40) has no headings
     * to check.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return list<string>
     */
    public static function problems(array $frontmatter, string $body): array
    {
        if (BodyFormat::isText($frontmatter)) {
            return [];
        }
        $names = 0;
        $exams = 0;
        $order = false;
        foreach (TitleFromHeading::headings($body) as [$level, $text]) {
            if ($level === 1) {
                ++$names;
                $order = $order || $exams > 0 && $names === 1;
            } else {
                ++$exams;
            }
        }
        $problems = [];
        if ($names === 0) {
            $problems[] = 'name.missing';
        } elseif ($names > 1) {
            $problems[] = 'name.many';
        }
        if ($exams === 0) {
            $problems[] = 'exam.missing';
        } elseif ($exams > 1) {
            $problems[] = 'exam.many';
        }
        if ($order) {
            $problems[] = 'order';
        }

        return $problems;
    }
}
