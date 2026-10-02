<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ExamTemplates;
use Reporion\Support\Templates;
use Throwable;

/**
 * The reference pages a report's exams bring from their templates (roadmap
 * phase 25): a template's `references:` lists pages — classifications,
 * norms, protocols (FORMATS §3i) — and every report made from it shows
 * them, read now, so changing the template's list changes it for every
 * report. Nothing is guessed from regions or words. A template or a page
 * the caller cannot read is left out silently (invariant 6).
 */
final class References
{
    /** A sane bound for one template's list */
    public const MAX = 30;

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
    ) {
    }

    /**
     * One entry per exam whose template lists readable references, in exam
     * order.
     *
     * @param array<string, mixed> $frontmatter the report's
     *
     * @return list<array{exam: int, title: string, template: string, pages: list<array{path: string, title: string, summary: string}>}>
     */
    public function forReport(array $frontmatter, ?User $principal): array
    {
        $out = [];
        foreach (ExamTemplates::of($frontmatter) as $n => [$title, $template]) {
            $pages = [];
            foreach ($this->listed($template, $principal) as $path) {
                $row = $this->index->findByPath($path, $principal);
                if ($row !== null) {
                    $pages[] = ['path' => $path, 'title' => (string) ($row['title'] ?? '') !== '' ? (string) $row['title'] : $path, 'summary' => (string) ($row['summary'] ?? '')];
                }
            }
            if ($pages !== []) {
                $out[] = ['exam' => $n, 'title' => $title, 'template' => $template, 'pages' => $pages];
            }
        }

        return $out;
    }

    /**
     * A template's `references:` as clean page paths: strings, colon paths,
     * no repeats, at most MAX.
     *
     * @return list<string>
     */
    public static function parse(mixed $raw): array
    {
        $paths = [];
        foreach (\is_array($raw) ? $raw : (\is_string($raw) ? [$raw] : []) as $item) {
            $path = \is_string($item) ? trim($item, " \t:/") : '';
            if (preg_match('/^[a-z0-9][a-z0-9_.-]*(:[a-z0-9][a-z0-9_.-]*)+$/', $path) === 1 && !\in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return \array_slice($paths, 0, self::MAX);
    }

    /** @return list<string> */
    private function listed(string $template, ?User $principal): array
    {
        if ($template === '' || !str_starts_with($template, Templates::NS . ':') || $this->index->findByPath($template, $principal) === null) {
            return [];
        }
        try {
            return self::parse($this->storage->read($template)->frontmatter['references'] ?? null);
        } catch (Throwable) {
            return [];
        }
    }
}
