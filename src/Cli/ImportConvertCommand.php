<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Import\AccessionAllocator;
use Reporion\Import\ImportMap;
use Reporion\Import\MacroExtractor;
use Reporion\Import\MetadataExtractor;
use Reporion\Import\PathMap;
use Reporion\Import\SyntaxConverter;
use Reporion\Support\DocumentFormat;
use Reporion\Support\Slug;
use RuntimeException;

/**
 * bin/reporion import:convert --batch <id> --map <path> [--limit <n>] [--only <prefix>]
 *
 * Single-pass extraction: read source → extract macros → convert syntax → extract metadata → write output.
 * Output file contains YAML frontmatter + markdown body, with original file's mtime preserved.
 * --map <path>: typically data/import-map.json (instance-specific site/device mappings).
 * --limit <n>: process only n randomly selected files (for faster testing).
 */
final class ImportConvertCommand implements CommandInterface
{
    public function __construct(
        private readonly string $dataRoot,
    ) {
    }

    public function run(array $args, Output $output): int
    {
        $options = self::parseOptions($args);
        $batchId = $options['batch'] ?? null;
        $mapFile = $options['map'] ?? null;
        $onlyPrefix = $options['only'] ?? null;
        $limit = isset($options['limit']) ? (int) $options['limit'] : null;

        if ($batchId === null || $mapFile === null) {
            $output->error('Usage: bin/reporion import:convert --batch <id> --map <path> [--limit <n>] [--only <prefix>]');

            return 1;
        }

        $batchDir = $this->dataRoot . '/import/' . $batchId;
        $manifest = json_decode((string) file_get_contents($batchDir . '/manifest.json'), true);

        if (!\is_array($manifest)) {
            $output->error('Invalid or missing manifest.json');

            return 1;
        }

        // Load dependencies
        $importMap = new ImportMap(json_decode((string) file_get_contents($mapFile), true) ?? []);
        $sourceRoot = $manifest['source_root'] ?? '/tmp/dokuwiki/data/pages';
        $pathMap = new PathMap();
        $accessionAllocator = new AccessionAllocator($batchDir, [], 'Europe/Bucharest');

        $review = [];
        $unknown = [];
        $count = 0;

        // If --limit is set, randomly select that many files
        $files = $manifest['files'] ?? [];
        if ($limit !== null && count($files) > $limit) {
            $keys = array_rand($files, $limit);
            // array_rand returns a single key if $limit is 1, or an array of keys otherwise
            if (!is_array($keys)) {
                $keys = [$keys];
            }
            $selected = [];
            foreach ($keys as $key) {
                $selected[] = $files[$key];
            }
            $files = $selected;
            $output->line("Testing with {$limit} randomly selected file(s)");
        }

        foreach ($files as $entry) {
            $relpath = $entry['relpath'];
            $sha256 = $entry['sha256'];

            if ($onlyPrefix !== null && !str_starts_with($relpath, $onlyPrefix)) {
                continue;
            }

            $output->line("Processing: {$relpath}");
            $sourceFile = $sourceRoot . '/' . $relpath;
            $source = file_get_contents($sourceFile);
            if ($source === false) {
                $output->error("Cannot read: {$relpath}");

                return 1;
            }

            // 1. Extract macros
            $extracted = MacroExtractor::extract($source);
            $body = $extracted['body'];
            $template = $extracted['template'] ?? null;
            $priors = $extracted['priors'] ?? [];

            // 2. Convert syntax
            $converted = SyntaxConverter::convert($body);
            $markdown = $converted['markdown'];

            // 3. Extract metadata
            $extractor = new MetadataExtractor(
                $importMap,
                $relpath,
                $sha256,
                $markdown,
                $template,
                $priors
            );
            $extracted = $extractor->extract($sourceRoot, $sourceFile, $accessionAllocator);
            $frontmatter = $extracted['frontmatter'];
            $itemReview = $extracted['review'];

            // 4. Build output file path and write frontmatter + body
            $parts = explode('/', $relpath);
            $last = array_pop($parts);
            $slug = Slug::normalize(preg_replace('/\.(txt)$/', '', $last) ?? '');
            $targetPath = implode(':', array_filter(array_merge(['reports'], $parts))) . ':' . $slug;

            $convertedDir = $batchDir . '/converted/' . implode('/', $parts);
            if (!is_dir($convertedDir)) {
                mkdir($convertedDir, 0775, true);
            }
            $convertedFile = $convertedDir . '/' . basename($last);

            // Encode frontmatter + body in one output file
            $fullMarkdown = DocumentFormat::encode($frontmatter, $markdown);
            file_put_contents($convertedFile, $fullMarkdown);

            // 5. Preserve original file's modification time (set once, after writing)
            $sourceTime = filemtime($sourceFile);
            if ($sourceTime !== false) {
                touch($convertedFile, $sourceTime);
            }

            // Record in path map and review queue
            $pathMap->record($relpath, $targetPath, $sha256);
            foreach ($itemReview as $item) {
                $review[] = array_merge(['relpath' => $relpath], $item);
            }

            $unknown = array_merge($unknown, $converted['unknown'] ?? []);
            $count++;
            if ($count % 500 === 0) {
                $output->line("Converted {$count}...");
            }
        }

        // Save artifacts
        file_put_contents($batchDir . '/pathmap.json', json_encode($pathMap->toArray(), JSON_PRETTY_PRINT) . "\n");
        file_put_contents($batchDir . '/conversion-report.json', json_encode([
            'total' => $count,
            'unknown_constructs' => array_unique($unknown),
        ], JSON_PRETTY_PRINT) . "\n");
        file_put_contents($batchDir . '/review.json', json_encode([
            'total' => $count,
            'review_items' => $review,
            'generated_at' => date('c'),
        ], JSON_PRETTY_PRINT) . "\n");

        $output->line("Converted {$count} file(s)");
        $output->line("Review queue: " . count($review) . " item(s)");

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
