<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Reporion\Cli\PagesCommitCommand;
use Reporion\Cli\PagesConvertCommand;
use Reporion\Cli\PagesScanCommand;
use Reporion\Cli\ImportRollbackCommand;
use Reporion\Cli\Output;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;

final class PagesCommitCommandTest extends TestCase
{
    private string $tempDir;
    private string $sourceDir;
    private string $mapFile;
    private FlatFile $storage;
    private Sqlite $index;
    private Output $output;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/reporion-pages-commit-test-' . uniqid();
        $this->sourceDir = $this->tempDir . '/source';
        $dataDir = $this->tempDir . '/data';

        mkdir($this->sourceDir . '/bookmarks/tech', 0755, true);
        mkdir($dataDir . '/import', 0755, true);
        mkdir($dataDir . '/pages', 0755, true);

        file_put_contents(
            $this->sourceDir . '/bookmarks/tech/link1.txt',
            $this->getSamplePage()
        );

        $pageImportMap = [
            'namespace_map' => ['documents' => 'docs'],
            'skip_dirs' => ['reports', 'wiki', 'playground'],
            'default_visibility' => 'private',
            'skip_paths' => [],
        ];
        $this->mapFile = $dataDir . '/page-import-map.json';
        file_put_contents($this->mapFile, json_encode($pageImportMap, JSON_PRETTY_PRINT));

        $this->index = new Sqlite($dataDir . '/index.sqlite', __DIR__ . '/../../migrations');
        $this->storage = new FlatFile($dataDir, $this->index);

        $this->output = new Output(fopen('php://memory', 'w'), fopen('php://memory', 'w'));
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
EOF;
    }

    private function prepareBatch(string $batch = 'test-batch'): void
    {
        $scanCmd = new PagesScanCommand($this->tempDir . '/data');
        $scanCmd->run(
            [
                '--from=' . $this->sourceDir,
                '--batch=' . $batch,
            ],
            $this->output
        );

        $convertCmd = new PagesConvertCommand($this->tempDir . '/data');
        $convertCmd->run(
            [
                '--batch=' . $batch,
                '--map=' . $this->mapFile,
            ],
            $this->output
        );
    }

    public function testCommitCreatesPages(): void
    {
        $this->prepareBatch();

        $cmd = new PagesCommitCommand($this->tempDir . '/data', $this->storage);
        $result = $cmd->run(
            [
                '--batch=test-batch',
            ],
            $this->output
        );

        self::assertEquals(0, $result);
        self::assertTrue(is_dir($this->tempDir . '/data/pages/bookmarks'));
    }

    public function testCommitPageHasArchivedStatus(): void
    {
        $this->prepareBatch();

        $cmd = new PagesCommitCommand($this->tempDir . '/data', $this->storage);
        $cmd->run(['--batch=test-batch'], $this->output);

        $page = $this->storage->read('bookmarks:tech:link1');
        self::assertSame('archived', $page->status);
        self::assertSame('private', $page->frontmatter['visibility']);
    }

    public function testCommitCreatesLog(): void
    {
        $this->prepareBatch();

        $cmd = new PagesCommitCommand($this->tempDir . '/data', $this->storage);
        $cmd->run(
            [
                '--batch=test-batch',
            ],
            $this->output
        );

        $logFile = $this->tempDir . '/data/import/test-batch/commit-log.json';
        self::assertFileExists($logFile);

        $log = json_decode(file_get_contents($logFile), true);
        self::assertArrayHasKey('total', $log);
        self::assertArrayHasKey('entries', $log);
        self::assertSame(1, $log['total']);
    }

    public function testCommitWithLimit(): void
    {
        $this->prepareBatch();

        $cmd = new PagesCommitCommand($this->tempDir . '/data', $this->storage);
        $result = $cmd->run(
            [
                '--batch=test-batch',
                '--limit=1',
            ],
            $this->output
        );

        self::assertEquals(0, $result);
    }

    public function testRollbackDeletesCommittedPages(): void
    {
        $this->prepareBatch();

        $commitCmd = new PagesCommitCommand($this->tempDir . '/data', $this->storage);
        $commitCmd->run(['--batch=test-batch'], $this->output);

        self::assertTrue(is_dir($this->tempDir . '/data/pages/bookmarks'));

        $rollbackCmd = new ImportRollbackCommand($this->tempDir . '/data', $this->storage);
        $result = $rollbackCmd->run(['--batch=test-batch'], $this->output);

        self::assertEquals(0, $result);

        $this->expectException(\Reporion\Exception\PageNotFoundException::class);
        $this->storage->read('bookmarks:tech:link1');
    }
}
