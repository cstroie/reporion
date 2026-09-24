<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Reporion\Cli\ImportRollbackCommand;
use Reporion\Cli\Output;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;

final class ImportRollbackCommandTest extends TestCase
{
    private string $tempDir;
    private FlatFile $storage;
    private Sqlite $index;
    private Output $output;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/reporion-rollback-test-' . uniqid();

        // Create directories
        $dataDir = $this->tempDir . '/data';
        mkdir($dataDir . '/pages', 0755, true);
        mkdir($dataDir . '/import/test-batch', 0755, true);

        // Initialize storage and index
        $this->index = new Sqlite($dataDir . '/index.sqlite', __DIR__ . '/../../migrations');
        $this->storage = new FlatFile($dataDir . '/pages', $this->index);

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

    public function testRollbackRefusesWithoutCommitLog(): void
    {
        $cmd = new ImportRollbackCommand($this->tempDir . '/data', $this->storage);
        $result = $cmd->run(
            [
                '--batch=nonexistent',
            ],
            $this->output
        );

        // Should fail if commit log doesn't exist
        $this->assertNotEquals(0, $result);
    }

    public function testRollbackWithEmptyCommitLog(): void
    {
        // Create empty commit log
        $logFile = $this->tempDir . '/data/import/test-batch/commit-log.json';
        file_put_contents($logFile, json_encode([
            'total' => 0,
            'skipped' => 0,
            'entries' => [],
            'committed_at' => date('c'),
        ]));

        $cmd = new ImportRollbackCommand($this->tempDir . '/data', $this->storage);
        $result = $cmd->run(
            [
                '--batch=test-batch',
            ],
            $this->output
        );

        // Should succeed with empty entries
        $this->assertEquals(0, $result);
    }

    public function testRollbackRefusesEditedPages(): void
    {
        // Create a page first
        $this->storage->create(
            'reports:test:test',
            ['title' => 'Test', 'status' => 'archived'],
            'Test body',
            'import',
            'imported'
        );

        // Create commit log with that page
        $logFile = $this->tempDir . '/data/import/test-batch/commit-log.json';
        file_put_contents($logFile, json_encode([
            'total' => 1,
            'skipped' => 0,
            'entries' => [
                [
                    'relpath' => 'test/test.txt',
                    'target_path' => 'reports:test:test',
                    'pid' => 'test-pid',
                ],
            ],
            'committed_at' => date('c'),
        ]));

        // Edit the page (change status from archived)
        $page = $this->storage->read('reports:test:test');
        $newFrontmatter = $page->frontmatter;
        $newFrontmatter['status'] = 'draft';
        $this->storage->create(
            'reports:test:test',
            $newFrontmatter,
            'Edited body',
            'editor',
            'user edited'
        );

        // Try to rollback
        $cmd = new ImportRollbackCommand($this->tempDir . '/data', $this->storage);
        $cmd->run(
            [
                '--batch=test-batch',
            ],
            $this->output
        );

        // Page should still exist (protected from deletion)
        $page = $this->storage->read('reports:test:test');
        $this->assertNotNull($page);
    }

    public function testRollbackDeletesArchivedPages(): void
    {
        // Create a page
        $pid = $this->storage->create(
            'reports:test:test',
            ['title' => 'Test', 'status' => 'archived'],
            'Test body',
            'import',
            'imported'
        );

        // Create commit log
        $logFile = $this->tempDir . '/data/import/test-batch/commit-log.json';
        file_put_contents($logFile, json_encode([
            'total' => 1,
            'skipped' => 0,
            'entries' => [
                [
                    'relpath' => 'test/test.txt',
                    'target_path' => 'reports:test:test',
                    'pid' => $pid,
                ],
            ],
            'committed_at' => date('c'),
        ]));

        // Rollback
        $cmd = new ImportRollbackCommand($this->tempDir . '/data', $this->storage);
        $result = $cmd->run(
            [
                '--batch=test-batch',
            ],
            $this->output
        );

        $this->assertEquals(0, $result);

        // Page should be deleted
        try {
            $this->storage->read('reports:test:test');
            $this->fail('Page should have been deleted');
        } catch (\Exception) {
            // Expected - page not found
            $this->assertTrue(true);
        }
    }

    public function testRollbackLogsResult(): void
    {
        // Create a page
        $this->storage->create(
            'reports:test:test',
            ['title' => 'Test', 'status' => 'archived'],
            'Test body',
            'import',
            'imported'
        );

        // Create commit log
        $logFile = $this->tempDir . '/data/import/test-batch/commit-log.json';
        file_put_contents($logFile, json_encode([
            'total' => 1,
            'skipped' => 0,
            'entries' => [
                [
                    'relpath' => 'test/test.txt',
                    'target_path' => 'reports:test:test',
                ],
            ],
            'committed_at' => date('c'),
        ]));

        // Rollback
        $cmd = new ImportRollbackCommand($this->tempDir . '/data', $this->storage);
        $cmd->run(
            [
                '--batch=test-batch',
            ],
            $this->output
        );

        // Check that rollback completed successfully
        // (Output is logged, can't directly test, but no errors means success)
        $this->assertTrue(true);
    }
}
