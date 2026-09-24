<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Storage\FlatFile;
use Reporion\Support\DocumentFormat;

/**
 * bin/reporion pages:commit --batch <id> [--limit <n>]
 *
 * Writes converted generic pages through Storage::create() with resolved paths.
 * Records pid ↔ source mapping for rollback (import:rollback is batch-agnostic).
 *
 * Unlike import:commit, there is no report-specific "unmapped site" check —
 * every converted file is eligible since pages have no site concept.
 */
final class PagesCommitCommand implements CommandInterface
{
    public function __construct(
        private readonly string $dataRoot,
        private readonly FlatFile $storage,
    ) {}

    public function run(array $args, Output $output): int
    {
        $options = self::parseOptions($args);
        $batchId = $options['batch'] ?? null;
        $limit = isset($options['limit']) ? (int) $options['limit'] : null;

        if ($batchId === null) {
            $output->error('Usage: bin/reporion pages:commit --batch <id> [--limit <n>]');
            return 1;
        }

        $batchDir = $this->dataRoot . '/import/' . $batchId;
        if (!is_dir($batchDir)) {
            $output->error("Batch not found: {$batchDir}");
            return 1;
        }

        // Load pathmap for resolved paths
        $pathMapFile = $batchDir . '/pathmap.json';
        if (!is_file($pathMapFile)) {
            $output->error('No pathmap found. Run pages:convert first.');
            return 1;
        }
        $pathMap = (array) json_decode((string) file_get_contents($pathMapFile), true);

        $convertedDir = $batchDir . '/converted';
        $commitLog = [];
        $count = 0;
        $processed = 0;

        if ($limit !== null) {
            $output->line("Testing with {$limit} file(s)");
        }

        // Walk converted directory
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($convertedDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            if ($limit !== null && $processed >= $limit) {
                break;
            }
            $processed++;

            $relPath = substr($item->getPathname(), \strlen($convertedDir) + 1);
            $output->line("Committing: {$relPath}");
            $source = file_get_contents($item->getPathname());
            if ($source === false) {
                $output->error("Cannot read: {$relPath}");
                return 1;
            }

            // Parse frontmatter + body
            [$frontmatter, $body] = DocumentFormat::parse($source);

            // Build target path from pathmap
            $targetPath = $pathMap[$relPath]['target_path'] ?? null;
            if ($targetPath === null) {
                $output->error("No pathmap entry for: {$relPath}");
                return 1;
            }

            // Resolve priors if present
            $priors = $frontmatter['priors'] ?? [];
            if (is_array($priors)) {
                foreach ($priors as $i => $prior) {
                    if (isset($prior['_prior_source_path'])) {
                        $targetPath = $prior['_prior_source_path'];
                        $priors[$i] = ['path' => $targetPath];
                    }
                }
                if (empty($priors)) {
                    unset($frontmatter['priors']);
                } else {
                    $frontmatter['priors'] = $priors;
                }
            }

            // Create the page through Storage
            try {
                $pid = $this->storage->create($targetPath, $frontmatter, $body, 'import', "imported from {$relPath}");
                $commitLog[] = ['relpath' => $relPath, 'pid' => $pid, 'target_path' => $targetPath];
                $count++;
                if ($count % 500 === 0) {
                    $output->line("Committed {$count}...");
                }
            } catch (\Exception $e) {
                $output->error("Cannot create {$targetPath}: " . $e->getMessage());
                return 1;
            }
        }

        // Save commit log for rollback
        file_put_contents(
            $batchDir . '/commit-log.json',
            json_encode([
                'total' => $count,
                'entries' => $commitLog,
                'committed_at' => date('c'),
            ], JSON_PRETTY_PRINT) . "\n"
        );

        $output->line("Committed {$count} page(s)");

        return 0;
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
