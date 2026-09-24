<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Reporion\Cli\PagesConvertCommand;
use Reporion\Cli\PagesScanCommand;
use Reporion\Cli\Output;

final class PagesConvertCommandTest extends TestCase
{
    private string $tempDir;
    private string $sourceDir;
    private Output $output;
    private string $mapFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/reporion-pages-convert-test-' . uniqid();
        $this->sourceDir = $this->tempDir . '/source';
        mkdir($this->sourceDir, 0755, true);
        mkdir($this->tempDir . '/data/import', 0755, true);

        $this->output = new Output(fopen('php://memory', 'w'), fopen('php://memory', 'w'));

        $pageImportMap = [
            'namespace_map' => ['documents' => 'docs'],
            'skip_dirs' => ['reports', 'wiki', 'playground'],
            'default_visibility' => 'private',
            'skip_paths' => [],
        ];
        $this->mapFile = $this->tempDir . '/data/page-import-map.json';
        file_put_contents($this->mapFile, json_encode($pageImportMap, JSON_PRETTY_PRINT));

        // Create a real page with a heading
        $testDir = $this->sourceDir . '/bookmarks/tech';
        mkdir($testDir, 0755, true);
        file_put_contents($testDir . '/link1.txt', $this->getSamplePage());

        // Create a page with no heading (title must be derived from filename)
        file_put_contents($testDir . '/no-heading-page.txt', "Just some plain text.\n\nMore text.\n");

        // Create a page that becomes empty after macro extraction (pure template-macro stub)
        file_put_contents($testDir . '/empty-stub.txt', "~~LLM_TEMPLATE:playground:something~~\n");
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function getSamplePage(): string
    {
        return <<<'EOF'
====== My Bookmark Page ======

Some //italic// content here.

===== Section =====

More text.
EOF;
    }

    private function runScan(string $batch = 'test-batch'): void
    {
        $scanCmd = new PagesScanCommand($this->tempDir . '/data');
        $scanCmd->run(
            [
                '--from=' . $this->sourceDir,
                '--batch=' . $batch,
            ],
            $this->output
        );
    }

    public function testConversionCreatesFiles(): void
    {
        $this->runScan();

        $convertCmd = new PagesConvertCommand($this->tempDir . '/data');
        $result = $convertCmd->run(
            [
                '--batch=test-batch',
                '--map=' . $this->mapFile,
            ],
            $this->output
        );

        self::assertEquals(0, $result);

        $convertedFile = $this->tempDir . '/data/import/test-batch/converted/bookmarks/tech/link1.txt';
        self::assertFileExists($convertedFile);
    }

    public function testConversionWritesGenericFrontmatter(): void
    {
        $this->runScan();

        $convertCmd = new PagesConvertCommand($this->tempDir . '/data');
        $convertCmd->run(
            [
                '--batch=test-batch',
                '--map=' . $this->mapFile,
            ],
            $this->output
        );

        $convertedFile = $this->tempDir . '/data/import/test-batch/converted/bookmarks/tech/link1.txt';
        $content = file_get_contents($convertedFile);

        self::assertStringStartsWith('---', $content);
        self::assertStringContainsString('status: archived', $content);
        self::assertStringContainsString('visibility: private', $content);
        self::assertStringContainsString('My Bookmark Page', $content);
        self::assertStringContainsString('bookmarks', $content);
    }

    public function testConversionExtractsTitleFromHeading(): void
    {
        $this->runScan();

        $convertCmd = new PagesConvertCommand($this->tempDir . '/data');
        $convertCmd->run(
            [
                '--batch=test-batch',
                '--map=' . $this->mapFile,
            ],
            $this->output
        );

        $convertedFile = $this->tempDir . '/data/import/test-batch/converted/bookmarks/tech/link1.txt';
        $content = file_get_contents($convertedFile);

        self::assertStringContainsString('title: \'My Bookmark Page\'', $content);
    }

    public function testConversionDerivesTitleFromFilenameWhenNoHeading(): void
    {
        $this->runScan();

        $convertCmd = new PagesConvertCommand($this->tempDir . '/data');
        $convertCmd->run(
            [
                '--batch=test-batch',
                '--map=' . $this->mapFile,
            ],
            $this->output
        );

        $convertedFile = $this->tempDir . '/data/import/test-batch/converted/bookmarks/tech/no-heading-page.txt';
        $content = file_get_contents($convertedFile);

        self::assertStringContainsString('No Heading Page', $content);

        // Check review.json has the derived-title entry
        $review = json_decode(
            file_get_contents($this->tempDir . '/data/import/test-batch/review.json'),
            true
        );
        $relpaths = array_column($review['review_items'], 'relpath');
        self::assertContains('bookmarks/tech/no-heading-page.txt', $relpaths);
    }

    public function testConversionSkipsEmptyFilesAfterMacroExtraction(): void
    {
        $this->runScan();

        $convertCmd = new PagesConvertCommand($this->tempDir . '/data');
        $convertCmd->run(
            [
                '--batch=test-batch',
                '--map=' . $this->mapFile,
            ],
            $this->output
        );

        $convertedFile = $this->tempDir . '/data/import/test-batch/converted/bookmarks/tech/empty-stub.txt';
        self::assertFileDoesNotExist($convertedFile);

        $skippedFile = $this->tempDir . '/data/import/test-batch/skipped-empty.json';
        self::assertFileExists($skippedFile);

        $skipped = json_decode(file_get_contents($skippedFile), true);
        self::assertContains('bookmarks/tech/empty-stub.txt', $skipped['files']);
    }

    public function testConversionCreatesPathmap(): void
    {
        $this->runScan();

        $convertCmd = new PagesConvertCommand($this->tempDir . '/data');
        $convertCmd->run(
            [
                '--batch=test-batch',
                '--map=' . $this->mapFile,
            ],
            $this->output
        );

        $pathMapFile = $this->tempDir . '/data/import/test-batch/pathmap.json';
        self::assertFileExists($pathMapFile);

        $pathMap = json_decode(file_get_contents($pathMapFile), true);
        self::assertIsArray($pathMap);
        self::assertArrayHasKey('bookmarks/tech/link1.txt', $pathMap);
        self::assertSame('bookmarks:tech:link1', $pathMap['bookmarks/tech/link1.txt']['target_path']);
    }

    public function testConversionWithLimit(): void
    {
        $this->runScan();

        $convertCmd = new PagesConvertCommand($this->tempDir . '/data');
        $result = $convertCmd->run(
            [
                '--batch=test-batch',
                '--map=' . $this->mapFile,
                '--limit=1',
            ],
            $this->output
        );

        self::assertEquals(0, $result);
    }

    public function testConversionCreatesConversionReport(): void
    {
        $this->runScan();

        $convertCmd = new PagesConvertCommand($this->tempDir . '/data');
        $convertCmd->run(
            [
                '--batch=test-batch',
                '--map=' . $this->mapFile,
            ],
            $this->output
        );

        $reportFile = $this->tempDir . '/data/import/test-batch/conversion-report.json';
        self::assertFileExists($reportFile);

        $report = json_decode(file_get_contents($reportFile), true);
        self::assertArrayHasKey('total', $report);
        self::assertArrayHasKey('skipped_empty', $report);
    }
}
