<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Checklist;
use Reporion\Support\MetaText;
use Reporion\Support\Templates;
use Throwable;

/**
 * The checklists a report's exams bring from their templates (roadmap
 * phase 26). Nothing is copied into the report (D19: a template gives
 * metadata): each exam's own `exams[].template`, else the report's
 * `template` for its one exam, is read now — so changing a template's list
 * changes it for every report made from it. A template the caller cannot
 * read gives nothing (invariant 6).
 */
final class Checklists
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
    ) {
    }

    /**
     * One entry per exam whose template has a checklist, in exam order.
     *
     * @param array<string, mixed> $frontmatter the report's
     *
     * @return list<array{exam: int, title: string, template: string, items: list<array{section: bool, label: string, keywords: list<string>}>}>
     */
    public function forReport(array $frontmatter, ?User $principal): array
    {
        $out = [];
        foreach ($this->examTemplates($frontmatter) as $n => [$title, $template]) {
            $items = $this->items($template, $principal);
            if ($items !== []) {
                $out[] = ['exam' => $n, 'title' => $title, 'template' => $template, 'items' => $items];
            }
        }

        return $out;
    }

    /**
     * The checklist of exam $exam (0-based) as prompt text, or ''.
     *
     * @param array<string, mixed> $frontmatter
     */
    public function textFor(array $frontmatter, int $exam, ?User $principal): string
    {
        $templates = $this->examTemplates($frontmatter);

        return isset($templates[$exam]) ? Checklist::asText($this->items($templates[$exam][1], $principal)) : '';
    }

    /**
     * Exam index → [title, template path]: each declared exam's own, else
     * the report's one exam.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function examTemplates(array $frontmatter): array
    {
        $exams = $frontmatter['exams'] ?? null;
        if (\is_array($exams) && array_is_list($exams) && $exams !== []) {
            $out = [];
            foreach ($exams as $n => $exam) {
                $exam = \is_array($exam) ? $exam : [];
                $template = MetaText::text($exam['template'] ?? null);
                // The report's template stands for its first exam when that exam names none
                if ($template === '' && $n === 0) {
                    $template = MetaText::text($frontmatter['template'] ?? null);
                }
                $out[$n] = [MetaText::text($exam['title'] ?? null), $template];
            }

            return $out;
        }

        return [0 => [MetaText::text($frontmatter['exam_title'] ?? null), MetaText::text($frontmatter['template'] ?? null)]];
    }

    /** @return list<array{section: bool, label: string, keywords: list<string>}> */
    private function items(string $template, ?User $principal): array
    {
        if ($template === '' || !str_starts_with($template, Templates::NS . ':') || $this->index->findByPath($template, $principal) === null) {
            return [];
        }
        try {
            return Checklist::parse($this->storage->read($template)->frontmatter['checklist'] ?? null);
        } catch (Throwable) {
            return [];
        }
    }
}
