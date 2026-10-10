<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Checklist;
use Reporion\Support\ExamTemplates;
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
    private readonly TemplatePages $templates;

    public function __construct(
        StorageInterface $storage,
        IndexInterface $index,
        ?TemplatePages $templates = null,
    ) {
        $this->templates = $templates ?? new TemplatePages($storage, $index);
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
        foreach (ExamTemplates::of($frontmatter) as $n => [$title, $template]) {
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
        $templates = ExamTemplates::of($frontmatter);

        return isset($templates[$exam]) ? Checklist::asText($this->items($templates[$exam][1], $principal)) : '';
    }

    /** @return list<array{section: bool, label: string, keywords: list<string>}> */
    private function items(string $template, ?User $principal): array
    {
        try {
            return Checklist::parse($this->templates->read($template, $principal)?->frontmatter['checklist'] ?? null);
        } catch (Throwable) {
            return [];
        }
    }
}
