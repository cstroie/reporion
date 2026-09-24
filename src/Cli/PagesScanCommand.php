<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Import\PageImportMap;
use RuntimeException;

/**
 * bin/reporion pages:scan --from <dir> --batch <id> [--dry-run]
 *
 * Walks the source tree, inventories non-report pages, and writes
 * data/import/<batch>/manifest.json. Skips directories based on PageImportMap,
 * templates/, and attic/. Records resolved namespace per entry.
 */
final class PagesScanCommand implements CommandInterface
{
    public function __construct(
        private readonly string $dataRoot,
    ) {
    }

    public function run(array $args, Output $output): int
    {
        $options = self::parseOptions($args);

        $sourceDir = $options['from'] ?? null;
        $batchId = $options['batch'] ?? null;
        $dryRun = \in_array('--dry-run', $args, true);

        if ($sourceDir === null || $batchId === null) {
            $output->error('Usage: bin/reporion pages:scan --from <dir> --batch <id> [--dry-run]');

            return 1;
        }

        if (!is_dir($sourceDir)) {
            $output->error("Source directory not found: {$sourceDir}");

            return 1;
        }

        // Load page import map from conf/ (instance-specific)
        $mapFile = $this->dataRoot . '/../conf/page-import-map.json';
        if (!is_file($mapFile)) {
            $output->error("Page import map not found: {$mapFile}");

            return 1;
        }

        try {
            $pageMap = new PageImportMap((array) json_decode((string) file_get_contents($mapFile), true));
        } catch (RuntimeException $e) {
            $output->error("Page import map error: " . $e->getMessage());

            return 1;
        }

        // Create batch directory
        $batchDir = $this->dataRoot . '/import/' . $batchId;
        if (!is_dir($batchDir) && !mkdir($batchDir, 0775, true) && !is_dir($batchDir)) {
            $output->error("Cannot create batch directory: {$batchDir}");

            return 1;
        }

        $manifest = [];
        $duplicates = [];
        $count = 0;

        // Walk source tree
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $relPath = substr($item->getPathname(), \strlen($sourceDir) + 1);

            // Skip non-.txt files
            if (!str_ends_with($item->getFilename(), '.txt')) {
                continue;
            }

            // Skip special files at any level
            if (\in_array($item->getFilename(), ['start.txt', 'sidebar.txt', '_template.txt'], true)) {
                continue;
            }

            // Skip templates and attic directories
            if (str_starts_with($relPath, 'templates/') || str_contains($relPath, '/templates/') ||
                str_starts_with($relPath, 'attic/') || str_contains($relPath, '/attic/')) {
                continue;
            }

            // Extract top-level directory
            $parts = explode('/', $relPath);
            $topDir = $parts[0] ?? null;

            // Skip directories not mapped by PageImportMap
            if ($topDir === null || $pageMap->namespaceFor($topDir) === null) {
                continue;
            }

            // Skip configured paths
            if ($pageMap->isSkippedPath($relPath)) {
                continue;
            }

            // Inventory this file
            $size = filesize($item->getPathname());
            $hash = hash_file('sha256', $item->getPathname());

            // Detect duplicate (same content hash)
            if (isset($duplicates[$hash])) {
                $output->line("Duplicate: {$relPath} (same as {$duplicates[$hash]})");
            } else {
                $duplicates[$hash] = $relPath;
            }

            $namespace = $pageMap->namespaceFor($topDir);

            $manifest[] = [
                'relpath' => $relPath,
                'namespace' => $namespace,
                'size' => $size,
                'sha256' => $hash,
            ];
            $count++;
        }

        if (!$dryRun) {
            $json = json_encode([
                'source_root' => $sourceDir,
                'files' => $manifest,
                'scanned_at' => date('c'),
            ], JSON_PRETTY_PRINT);
            if ($json === false) {
                $output->error('Cannot encode manifest');

                return 1;
            }
            file_put_contents($batchDir . '/manifest.json', $json . "\n");
        }

        $output->line(sprintf('%s: %d file(s)', $dryRun ? 'Would scan' : 'Scanned', $count));

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
