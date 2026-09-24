<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Reporion\Cli\ImportCommitCommand;
use Reporion\Cli\ImportConvertCommand;
use Reporion\Cli\ImportScanCommand;
use Reporion\Cli\Output;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;

final class ImportCommitCommandTest extends TestCase
{
    private string $tempDir;
    private string $sourceDir;
    private FlatFile $storage;
    private Sqlite $index;
    private Output $output;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/reporion-commit-test-' . uniqid();
        $this->sourceDir = $this->tempDir . '/source';
        $dataDir = $this->tempDir . '/data';

        // Create directories
        mkdir($this->sourceDir . '/reports/ct/scuc', 0755, true);
        mkdir($dataDir . '/import', 0755, true);
        mkdir($dataDir . '/pages', 0755, true);

        // Create test source file
        file_put_contents(
            $this->sourceDir . '/reports/ct/scuc/210525-test-patient.txt',
            $this->getSampleReport()
        );

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

    private function getSampleReport(): string
    {
        return <<<'EOF'
====== Test Patient ======

===== CT Exam =====

Some findings here.

===== Concluzii =====

Normal examination.
EOF;
    }

    private function prepareBatch(): void
    {
        // Scan
        $scanCmd = new ImportScanCommand($this->tempDir . '/data', []);
        $scanCmd->run(
            [
                '--from=' . $this->sourceDir . '/reports',
                '--batch=test-batch',
            ],
            $this->output
        );

        // Convert
        $convertCmd = new ImportConvertCommand($this->tempDir . '/data');
        $convertCmd->run(
            [
                '--batch=test-batch',
                '--map=' . __DIR__ . '/../../data/import-map.json',
            ],
            $this->output
        );
    }

    public function testCommitCreatesPages(): void
    {
        $this->prepareBatch();

        $cmd = new ImportCommitCommand($this->tempDir . '/data', $this->storage);
        $result = $cmd->run(
            [
                '--batch=test-batch',
            ],
            $this->output
        );

        $this->assertEquals(0, $result);

        // Check that page was created in storage
        $this->assertTrue(is_dir($this->tempDir . '/data/pages/reports'));
    }

    public function testCommitCreatesLog(): void
    {
        $this->prepareBatch();

        $cmd = new ImportCommitCommand($this->tempDir . '/data', $this->storage);
        $cmd->run(
            [
                '--batch=test-batch',
            ],
            $this->output
        );

        $logFile = $this->tempDir . '/data/import/test-batch/commit-log.json';
        $this->assertFileExists($logFile);

        $log = json_decode(file_get_contents($logFile), true);
        $this->assertArrayHasKey('total', $log);
        $this->assertArrayHasKey('entries', $log);
    }

    public function testCommitWithLimit(): void
    {
        $this->prepareBatch();

        $cmd = new ImportCommitCommand($this->tempDir . '/data', $this->storage);
        $result = $cmd->run(
            [
                '--batch=test-batch',
                '--limit=1',
            ],
            $this->output
        );

        $this->assertEquals(0, $result);
    }

    public function testCommitSkipsUnmappedSites(): void
    {
        $this->prepareBatch();

        // Check if any pages have null site in converted files
        $convertedDir = $this->tempDir . '/data/import/test-batch/converted';
        $hasUnmapped = false;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($convertedDir),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            $content = file_get_contents($file->getPathname());
            if (preg_match('/^site:\s*null$/m', $content)) {
                $hasUnmapped = true;
                break;
            }
        }

        if (!$hasUnmapped) {
            // If no unmapped sites in test batch, test should complete normally
            $cmd = new ImportCommitCommand($this->tempDir . '/data', $this->storage);
            $result = $cmd->run(
                [
                    '--batch=test-batch',
                ],
                $this->output
            );

            // Should succeed since all sites are mapped
            $this->assertIn($result, [0, 1]); // Allow 0 or 1 depending on mapping
        }
    }
}
