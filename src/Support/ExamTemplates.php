<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Which template each exam of a report was made from: each exam's own
 * `exams[].template` and nothing else (the top-level `template` of an older
 * save counts only for a report of one exam, Exams::of). Read by everything a template
 * gives a report at read time: its checklist (phase 26) and its
 * references (phase 25), so nothing is copied into the report (D19).
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
        // Each exam's own template; a report of several never takes one exam's for another (Exams::of)
        return array_map(static fn (array $exam): array => [MetaText::text($exam['title'] ?? null), MetaText::text($exam['template'] ?? null)], Exams::of($frontmatter));
    }
}
