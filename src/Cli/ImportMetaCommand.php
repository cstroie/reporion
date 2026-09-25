<?php

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
use Reporion\Support\DocumentFormat;

final class ImportMetaCommand implements CommandInterface
{
    public function __construct(private readonly string $dataRoot) {}

    public function run(array $args, Output $output): int
    {
        $options = self::parseOptions($args);
        $batchId = $options['batch'] ?? null;
        $mapFile = $options['map'] ?? null;

        if ($batchId === null || $mapFile === null) {
            $output->error('Usage: bin/reporion import:meta --batch <id> --map <path>');

            return 1;
        }

        $batchDir = $this->dataRoot . "/import/{$batchId}";

        // Load dependencies
        $importMap = new ImportMap(json_decode((string) file_get_contents($mapFile), true) ?? []);
        $manifest = json_decode((string) file_get_contents($batchDir . '/manifest.json'), true);
        $sourceRoot = $manifest['source_root'] ?? '/tmp/dokuwiki/data/pages';
        $pathMap = PathMap::fromArray(json_decode((string) file_get_contents($batchDir . '/pathmap.json'), true) ?? []);
        $accessionAllocator = new AccessionAllocator($batchDir, [], 'Europe/Bucharest', $this->dataRoot . '/pages');

        $review = [];
        $count = 0;

        // For each converted file, extract metadata and rewrite with frontmatter
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($batchDir . '/converted', FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.txt')) {
                continue;
            }
            $convertedFile = $file->getPathname();
            $relPath = substr($convertedFile, strlen($batchDir . '/converted/'));
            // Normalize path separators
            $sourcePath = str_replace('\\', '/', $relPath);

            // Resolve full source file path
            $sourceFile = $sourceRoot . '/' . $sourcePath;

            // For now, just read the converted markdown
            $markdown = file_get_contents($convertedFile);
            $extractor = MacroExtractor::extract($markdown);
            $body = $extractor['body'];

            // Get target path and hash from pathmap
            $targetPath = $pathMap->resolveTarget($sourcePath);
            if (!$targetPath) {
                $output->error("No target path for: {$sourcePath}");

                return 1;
            }
            $sha256 = $pathMap->hash($sourcePath) ?? '';

            // Extract metadata
            $extractor = new MetadataExtractor(
                $importMap,
                $sourcePath,
                $sha256,
                $body,
                $extractor['template'] ?? null,
                $extractor['priors'] ?? []
            );
            $extracted = $extractor->extract($sourceRoot, $sourceFile, $accessionAllocator);
            $frontmatter = $extracted['frontmatter'];
            $itemReview = $extracted['review'];

            // Rewrite converted file with frontmatter
            $fullMarkdown = DocumentFormat::encode($frontmatter, $body);
            file_put_contents($convertedFile, $fullMarkdown);

            // Preserve original file's modification time
            if (is_file($sourceFile)) {
                $sourceTime = filemtime($sourceFile);
                if ($sourceTime !== false) {
                    touch($convertedFile, $sourceTime);
                }
            }

            // Accumulate review items
            foreach ($itemReview as $item) {
                $review[] = array_merge(['relpath' => $sourcePath], $item);
            }

            $count++;
            if ($count % 500 === 0) {
                $output->line("Extracted {$count}/{$count}...");
            }
        }

        // Write review queue
        file_put_contents($batchDir . '/review.json', json_encode([
            'total' => $count,
            'review_items' => $review,
            'generated_at' => date('c'),
        ], JSON_PRETTY_PRINT) . "\n");

        $output->line("Extracted metadata for {$count} file(s)");
        $output->line("Review queue: " . count($review) . " item(s)");

        return 0;
    }

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
