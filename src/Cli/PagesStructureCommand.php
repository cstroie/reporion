<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Audit\AuditLog;
use Reporion\Exception\PageNotFoundException;
use Reporion\Service\RawReportParser;
use Reporion\Storage\StorageInterface;

/**
 * bin/reporion pages:structure [--dry-run] [--apply] [--actor=<username>]
 *   [--limit=<n>] [--namespace=<ns>] [--json]
 *
 * One-time migration of old-format (pre-2026) radiology report pages
 * to the structured format (D30: # name / ## exam / ### sections).
 *
 * Old body:
 *   # Patient Name
 *   **indication**
 *   *date*
 *   body paragraphs...
 *
 * New body:
 *   ## Exam Title
 *
 *   ### Descriere
 *   description text
 *
 *   ### Concluzii
 *   conclusion text
 *
 * Frontmatter is merged with: indication, exam_title, summary (from
 * conclusion), and modality/region fixes when they were "other"/
 * "whole-body".
 *
 * Idempotent: pages that already have ### Descriere / ### Concluzii
 * are skipped on reruns. Reads and writes go through Storage
 * (invariant 5).
 */
final class PagesStructureCommand implements CommandInterface
{
    public function __construct(
        private readonly string $dataRoot,
        private readonly StorageInterface $storage,
        private readonly AuditLog $audit,
    ) {
    }

    public function run(array $args, Output $output): int
    {
        $options = self::parseOptions($args);
        $apply = \in_array('--apply', $args, true);
        $actor = $options['actor'] ?? 'cli';
        $limit = isset($options['limit']) ? (int) $options['limit'] : null;
        $namespace = $options['namespace'] ?? 'reports';
        $jsonOutput = \in_array('--json', $args, true);

        if ($apply && !isset($options['actor'])) {
            $output->line('--apply needs --actor=<username>: the new revisions are attributed to them');
        }

        $namespaceDir = $this->dataRoot . '/pages/' . str_replace(':', '/', $namespace);
        if (!is_dir($namespaceDir)) {
            $output->error("Namespace directory not found: {$namespaceDir}");

            return 1;
        }

        $pagesRoot = $this->dataRoot . '/pages';
        $manifest = [];
        $total = 0;
        $matched = 0;
        $skipped = 0;
        $applied = 0;
        $errors = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($namespaceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->getFilename() !== 'current.md') {
                continue;
            }

            if ($limit !== null && $total >= $limit) {
                break;
            }
            $total++;

            // Derive colon path from filesystem path
            $dir = (string) dirname($item->getPathname());
            $relativeDir = substr($dir, \strlen($pagesRoot) + 1);
            $path = str_replace('/', ':', $relativeDir);

            // Namespace filter (prefix match, mirrors D36)
            if ($path !== $namespace && !str_starts_with($path, $namespace . ':')) {
                continue;
            }

            // Read current page through Storage
            try {
                $record = $this->storage->read($path);
            } catch (PageNotFoundException) {
                $output->error("Page not found: {$path}");
                $errors++;

                continue;
            }

            // Idempotency: skip already-structured pages
            $body = $record->body;
            if (str_contains($body, '### Descriere') && str_contains($body, '### Concluzii')) {
                $skipped++;

                continue;
            }

            // Parse old-format body
            $parsed = RawReportParser::parse($body, $record->path, $record->frontmatter);

            if ($parsed === null) {
                $skipped++;

                continue;
            }

            $matched++;

            // Review notes: modality/region fixup flags
            $review = [];
            $modality = $record->frontmatter['modality'] ?? null;
            $region = $record->frontmatter['region'] ?? null;
            if (\is_array($modality) && \in_array('other', $modality, true)) {
                $review[] = 'modality is "other" — needs review';
            }
            if (\is_array($region) && \in_array('whole-body', $region, true)) {
                $review[] = 'region is "whole-body" — needs review';
            }
            if (\in_array('low', $parsed['confidence'], true)) {
                $review[] = 'exam title is a fallback — check the body';
            }
            if ($parsed['conclusion'] === '') {
                $review[] = 'no conclusion extracted — check the body';
            }

            $manifest[] = [
                'path' => $path,
                'indication' => $parsed['indication'],
                'examTitle' => $parsed['examTitle'],
                'confidence' => $parsed['confidence'],
                'review' => $review,
            ];

            if (!$apply) {
                continue;
            }

            // Apply mode: build new frontmatter and body, save through Storage
            try {
                $newFrontmatter = self::buildFrontmatter($record->frontmatter, $parsed);
                $newBody = self::buildBody($parsed);

                $newRecord = $this->storage->save(
                    $path,
                    $newFrontmatter,
                    $newBody,
                    $record->rev,
                    $actor,
                    'pages:structure migration'
                );

                $this->audit->record(
                    'page.edit',
                    $actor,
                    null,
                    $newRecord->pid,
                    $newRecord->path,
                    $newRecord->rev,
                    extra: ['migration' => 'pages:structure']
                );

                $applied++;
            } catch (\Exception $e) {
                $output->error("Cannot save {$path}: " . $e->getMessage());
                $errors++;

                continue;
            }

            if ($applied % 50 === 0) {
                $output->line("Applied to {$applied} page(s)...");
            }
        }

        // Output results
        if ($apply) {
            if ($jsonOutput) {
                $output->line(json_encode([
                    'total' => $total,
                    'matched' => $matched,
                    'applied' => $applied,
                    'skipped' => $skipped,
                    'errors' => $errors,
                    'pages' => $manifest,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $output->line("Applied to {$applied} page(s), skipped {$skipped}, errors {$errors}");
            }
        } else {
            $output->line(json_encode([
                'total' => $total,
                'matched' => $matched,
                'skipped' => $skipped,
                'pages' => $manifest,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return $errors > 0 ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @param array<string, mixed> $parsed
     *
     * @return array<string, mixed>
     */
    private static function buildFrontmatter(array $frontmatter, array $parsed): array
    {
        $fm = $frontmatter;

        // Merge parser's fmUpdates (indication, summary, exam_title, modality/region fixes)
        foreach ($parsed['fmUpdates'] as $key => $value) {
            $fm[$key] = $value;
        }

        return $fm;
    }

    /** @param array<string, mixed> $parsed */
    private static function buildBody(array $parsed): string
    {
        $examTitle = $parsed['examTitle'] ?? 'Exam';
        $description = $parsed['description'] ?? '';
        $conclusion = $parsed['conclusion'] ?? '';

        return "## {$examTitle}\n\n### Descriere\n{$description}\n\n### Concluzii\n{$conclusion}\n";
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, string>
     */
    private static function parseOptions(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
                continue;
            }
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $options[$key] = $value;
        }

        return $options;
    }
}
