<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ExamTemplates;
use Throwable;

/**
 * The reference page a report's exams bring from their templates (roadmap
 * phase 25): a template's `reference:` names one page — the knee page, the
 * brain page (FORMATS §3i) — and every report made from it can open that
 * page in a side panel, read now, so changing the template's page changes
 * it for every report. Nothing is guessed from regions or words. A
 * template or a page the caller cannot read gives nothing (invariant 6).
 */
final class References
{
    private readonly TemplatePages $templates;

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly ?Render $render = null,
        ?TemplatePages $templates = null,
    ) {
        $this->templates = $templates ?? new TemplatePages($storage, $index);
    }

    /**
     * The distinct reference pages of the report's exams, in exam order,
     * each with the exams it serves and its text rendered for the panel.
     *
     * @param array<string, mixed> $frontmatter the report's
     *
     * @return list<array{path: string, title: string, exams: list<string>, html: string}>
     */
    public function forReport(array $frontmatter, ?User $principal, string $basePath = ''): array
    {
        $out = [];
        foreach (ExamTemplates::of($frontmatter) as $n => [$examTitle, $template]) {
            $path = $this->referenceOf($template, $principal);
            if ($path === null) {
                continue;
            }
            $label = $examTitle !== '' ? $examTitle : t('editor.check.exam', [$n + 1]);
            if (isset($out[$path])) {
                $out[$path]['exams'][] = $label;
                continue;
            }
            $row = $this->index->findByPath($path, $principal);
            if ($row === null) {
                continue;
            }
            try {
                $body = $this->storage->read($path)->body;
            } catch (Throwable) {
                continue;
            }
            $out[$path] = [
                'path' => $path,
                'title' => (string) ($row['title'] ?? '') !== '' ? (string) $row['title'] : $path,
                'exams' => [$label],
                // Its headings' ids dropped: they would collide with the report's own anchors
                'html' => $this->render !== null ? (string) preg_replace('/(<h[1-6][^>]*?)\sid="[^"]*"/', '$1', $this->render->toHtml($body, $basePath)->html) : '',
            ];
        }

        return array_values($out);
    }

    /**
     * A template's `reference:` as a clean page path, or null.
     */
    public static function parse(mixed $raw): ?string
    {
        $path = \is_string($raw) ? trim($raw, " \t:/") : '';

        return preg_match('/^[a-z0-9][a-z0-9_.-]*(:[a-z0-9][a-z0-9_.-]*)+$/', $path) === 1 ? $path : null;
    }

    private function referenceOf(string $template, ?User $principal): ?string
    {
        return self::parse($this->templates->read($template, $principal)?->frontmatter['reference'] ?? null);
    }
}
