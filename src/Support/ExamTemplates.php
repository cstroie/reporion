<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Which template each exam of a report was made from: each declared exam's
 * own `exams[].template`, the report's `template` standing for the first
 * exam when that names none; a report without `exams:` is one exam with
 * the top-level `exam_title` and `template`. Read by everything a template
 * gives a report at read time — its checklist (phase 26) and its
 * references (phase 25) — so nothing is copied into the report (D19).
 */
final class ExamTemplates
{
    /**
     * Exam index (0-based) → [exam title, template path] ('' when unset).
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function of(array $frontmatter): array
    {
        $exams = $frontmatter['exams'] ?? null;
        if (\is_array($exams) && array_is_list($exams) && $exams !== []) {
            $out = [];
            foreach ($exams as $n => $exam) {
                $exam = \is_array($exam) ? $exam : [];
                $template = MetaText::text($exam['template'] ?? null);
                if ($template === '' && $n === 0) {
                    $template = MetaText::text($frontmatter['template'] ?? null);
                }
                $out[$n] = [MetaText::text($exam['title'] ?? null), $template];
            }

            return $out;
        }

        return [0 => [MetaText::text($frontmatter['exam_title'] ?? null), MetaText::text($frontmatter['template'] ?? null)]];
    }
}
