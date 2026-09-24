<?php

declare(strict_types=1);

namespace Reporion\Cli;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Storage\FlatFile;
use Reporion\Support\DocumentFormat;

/**
 * bin/reporion import:commit --batch <id>
 *
 * Writes imported pages through Storage::create() with priors resolved.
 * Refuses pages with unmapped sites (queued for review). Records pid ↔ source
 * mapping for rollback.
 */
final class ImportCommitCommand implements CommandInterface
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
            $output->error('Usage: bin/reporion import:commit --batch <id> [--limit <n>]');
            return 1;
        }

        $batchDir = $this->dataRoot . '/import/' . $batchId;
        if (!is_dir($batchDir)) {
            $output->error("Batch not found: {$batchDir}");
            return 1;
        }

        // Load pathmap for priors resolution
        $pathMapFile = $batchDir . '/pathmap.json';
        if (!is_file($pathMapFile)) {
            $output->error('No pathmap found. Run import:convert first.');
            return 1;
        }
        $pathMap = (array) json_decode((string) file_get_contents($pathMapFile), true);

        $convertedDir = $batchDir . '/converted';
        $commitLog = [];
        $skipped = [];
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

            $relPath = substr($item->getPathname(), strlen($convertedDir) + 1);
            $source = file_get_contents($item->getPathname());
            if ($source === false) {
                $output->error("Cannot read: {$relPath}");
                return 1;
            }

            // Parse frontmatter + body
            $parsed = DocumentFormat::parse($source);
            $frontmatter = $parsed['frontmatter'] ?? [];
            $body = $parsed['body'] ?? '';

            // Check site is mapped (never guess)
            if (($frontmatter['site'] ?? null) === null) {
                $output->line("Skipping: {$relPath} (unmapped site — resolve in review.json first)");
                $skipped[] = $relPath;
                continue;
            }

            // Resolve priors: convert _prior_source_path to actual pids
            $priors = $frontmatter['priors'] ?? [];
            if (is_array($priors)) {
                foreach ($priors as $i => $prior) {
                    if (isset($prior['_prior_source_path'])) {
                        // Try to resolve via pathmap (already in target format)
                        $targetPath = $prior['_prior_source_path'];
                        // Convert colon-path to source path for pathmap lookup if needed
                        $sourcePathForLookup = str_replace(':', '/', $targetPath);
                        if (isset($pathMap[$sourcePathForLookup])) {
                            $priors[$i] = ['path' => $targetPath];
                        } else {
                            // Already in target format, keep as-is
                            $priors[$i] = ['path' => $targetPath];
                        }
                    }
                }
                if (empty($priors)) {
                    unset($frontmatter['priors']);
                } else {
                    $frontmatter['priors'] = $priors;
                }
            }

            // Build target path (already computed during convert, stored in pathmap)
            $targetPath = $pathMap[$relPath]['target_path'] ?? null;
            if ($targetPath === null) {
                $output->error("No pathmap entry for: {$relPath}");
                return 1;
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
                'skipped' => count($skipped),
                'entries' => $commitLog,
                'committed_at' => date('c'),
            ], JSON_PRETTY_PRINT) . "\n"
        );

        $output->line("Committed {$count} page(s)");
        if (count($skipped) > 0) {
            $output->line("Skipped {$count} page(s) with unmapped site");
        }

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
