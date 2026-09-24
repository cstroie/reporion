<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Import\ImportMap;
use RuntimeException;

/**
 * bin/reporion import:scan --from <dir> --batch <id> [--dry-run]
 *
 * Walks the source tree, inventories files, and writes data/import/<batch>/manifest.json.
 * Skips templates/, attic/, and non-report files (.sh, .py, .awk, start.txt, sidebar.txt).
 */
final class ImportScanCommand implements CommandInterface
{
    public function __construct(
        private readonly string $dataRoot,
        private readonly array $config,
    ) {
    }

    public function run(array $args, Output $output): int
    {
        $options = self::parseOptions($args);

        $sourceDir = $options['from'] ?? null;
        $batchId = $options['batch'] ?? null;
        $dryRun = \in_array('--dry-run', $args, true);

        if ($sourceDir === null || $batchId === null) {
            $output->error('Usage: bin/reporion import:scan --from <dir> --batch <id> [--dry-run]');

            return 1;
        }

        if (!is_dir($sourceDir)) {
            $output->error("Source directory not found: {$sourceDir}");

            return 1;
        }

        // Load import map from data/ (instance-specific)
        $mapFile = $this->dataRoot . '/import-map.json';
        if (!is_file($mapFile)) {
            $output->error("Import map not found: {$mapFile}");

            return 1;
        }

        try {
            $importMap = new ImportMap((array) json_decode((string) file_get_contents($mapFile), true));
        } catch (RuntimeException $e) {
            $output->error("Import map error: " . $e->getMessage());

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

            // Skip non-.txt files and special files
            if (!str_ends_with($item->getFilename(), '.txt')) {
                continue;
            }
            if (\in_array($item->getFilename(), ['start.txt', 'sidebar.txt', '_template.txt'], true)) {
                continue;
            }

            // Skip templates and attic directories
            if (str_starts_with($relPath, 'templates/') || str_contains($relPath, '/templates/') ||
                str_starts_with($relPath, 'attic/') || str_contains($relPath, '/attic/')) {
                continue;
            }

            // Skip year-bundle files (e.g., "ct/2022.txt", "mri/2023.txt") — legacy files before per-site split
            if (preg_match('/^(ct|mri)\/\d{4}\.txt$/', $relPath)) {
                continue;
            }

            // Skip skip_paths
            $skip = false;
            foreach ($importMap->skipPaths() as $skipPath) {
                if (str_starts_with($relPath, $skipPath . '/')) {
                    $skip = true;

                    break;
                }
            }
            if ($skip) {
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

            $manifest[] = [
                'relpath' => $relPath,
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
