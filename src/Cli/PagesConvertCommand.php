<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Import\MacroExtractor;
use Reporion\Import\PageImportMap;
use Reporion\Import\PathMap;
use Reporion\Import\SyntaxConverter;
use Reporion\Support\DocumentFormat;
use Reporion\Support\Slug;
use RuntimeException;

/**
 * bin/reporion pages:convert --batch <id> --map <path> [--limit <n>]
 *
 * Single-pass extraction for generic pages: read source → extract macros →
 * convert syntax (headingOffset=0) → extract title → write output.
 *
 * Skips files that become empty after macro extraction (e.g., pure <nspages>).
 * Output file contains YAML frontmatter + markdown body, with original mtime.
 * Generic frontmatter: title, visibility, status:archived, tags, imported_from, import_batch.
 *
 * --map <path>: conf/page-import-map.json (instance-specific namespace mapping).
 */
final class PagesConvertCommand implements CommandInterface
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
        $limit = isset($options['limit']) ? (int) $options['limit'] : null;

        if ($batchId === null || $mapFile === null) {
            $output->error('Usage: bin/reporion pages:convert --batch <id> --map <path> [--limit <n>]');

            return 1;
        }

        $batchDir = $this->dataRoot . '/import/' . $batchId;
        $manifestFile = $batchDir . '/manifest.json';
        if (!is_file($manifestFile)) {
            $output->error("Manifest not found: {$manifestFile}");

            return 1;
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        if (!\is_array($manifest)) {
            $output->error('Invalid or missing manifest.json');

            return 1;
        }

        // Load page import map
        try {
            $pageMap = new PageImportMap(json_decode((string) file_get_contents($mapFile), true) ?? []);
        } catch (RuntimeException $e) {
            $output->error("Page import map error: " . $e->getMessage());

            return 1;
        }

        $sourceRoot = $manifest['source_root'] ?? '/tmp/dokuwiki/data/pages';
        $pathMap = new PathMap();
        $review = [];
        $unknown = [];
        $skippedEmpty = [];
        $count = 0;
        $processed = 0;

        $files = $manifest['files'] ?? [];

        foreach ($files as $entry) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }
            $processed++;

            $relpath = $entry['relpath'];
            $namespace = $entry['namespace'] ?? null;
            $sha256 = $entry['sha256'];

            if ($namespace === null) {
                $output->error("No namespace for: {$relpath}");

                return 1;
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

            // 2. Convert syntax with headingOffset=0 for generic pages
            $converted = SyntaxConverter::convert($body, 0);
            $markdown = $converted['markdown'];

            // 3. Skip if body is now empty
            if (trim($markdown) === '') {
                $skippedEmpty[] = $relpath;
                continue;
            }

            // 4. Extract title: first heading or derived from filename
            $title = $this->extractTitle($markdown, $relpath);
            if ($title === null) {
                // If no heading found, derive from filename and add review note
                $title = $this->deriveTitleFromFilename($relpath);
                $review[] = [
                    'relpath' => $relpath,
                    'kind' => 'title-derived',
                    'message' => 'No heading found — title derived from filename',
                ];
            }

            // 5. Build frontmatter
            $frontmatter = [
                'title' => $title,
                'visibility' => $pageMap->defaultVisibility(),
                'status' => 'archived',
                'tags' => [$namespace],
                'imported_from' => $relpath,
                'import_batch' => $batchId,
            ];

            if ($template !== null) {
                $frontmatter['template'] = $template;
            }
            if (!empty($priors)) {
                $frontmatter['priors'] = $priors;
            }

            // 6. Build target path: namespace:path:slug
            $parts = explode('/', $relpath);
            array_shift($parts);  // Remove top-level dir (already in namespace)
            $last = array_pop($parts);
            $slug = Slug::normalize(preg_replace('/\.(txt)$/', '', $last) ?? '');

            $pathSegments = array_merge([$namespace], $parts);
            if ($slug !== '') {
                $pathSegments[] = $slug;
            }
            $targetPath = implode(':', array_filter($pathSegments));

            // 7. Write output file
            $convertedDir = $batchDir . '/converted/' . implode('/', $parts);
            if (!is_dir($convertedDir)) {
                mkdir($convertedDir, 0775, true);
            }
            $convertedFile = $convertedDir . '/' . basename($last);

            $fullMarkdown = DocumentFormat::encode($frontmatter, $markdown);
            file_put_contents($convertedFile, $fullMarkdown);

            // Preserve original file's modification time
            $sourceTime = filemtime($sourceFile);
            if ($sourceTime !== false) {
                touch($convertedFile, $sourceTime);
            }

            // Record in path map and review queue
            $pathMap->record($relpath, $targetPath, $sha256);
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
            'skipped_empty' => count($skippedEmpty),
            'unknown_constructs' => array_unique($unknown),
        ], JSON_PRETTY_PRINT) . "\n");
        file_put_contents($batchDir . '/review.json', json_encode([
            'total' => $count,
            'review_items' => $review,
            'generated_at' => date('c'),
        ], JSON_PRETTY_PRINT) . "\n");

        if (!empty($skippedEmpty)) {
            file_put_contents($batchDir . '/skipped-empty.json', json_encode([
                'count' => count($skippedEmpty),
                'files' => $skippedEmpty,
            ], JSON_PRETTY_PRINT) . "\n");
        }

        $output->line("Converted {$count} file(s)");
        if (count($skippedEmpty) > 0) {
            $output->line("Skipped {" . count($skippedEmpty) . "} empty file(s)");
        }
        $output->line("Review queue: " . count($review) . " item(s)");

        return 0;
    }

    /**
     * Extract title from first markdown heading, or return null if none found.
     */
    private function extractTitle(string $markdown, string $relpath): ?string
    {
        $lines = explode("\n", $markdown);
        foreach ($lines as $line) {
            if (preg_match('/^#+\s+(.+)$/', $line, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /**
     * Derive a title from the filename (last segment of relpath, minus .txt, dehyphenated).
     */
    private function deriveTitleFromFilename(string $relpath): string
    {
        $parts = explode('/', $relpath);
        $filename = array_pop($parts) ?? 'page';
        $filename = preg_replace('/\.(txt)$/', '', $filename) ?? '';
        // Dehyphenate and title-case
        $title = ucwords(str_replace(['-', '_'], ' ', $filename));

        return $title ?: 'Untitled';
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
