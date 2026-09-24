<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Reporion\Cli\ImportScanCommand;
use Reporion\Cli\Output;

final class ImportScanCommandTest extends TestCase
{
    private string $tempDir;
    private string $sourceDir;
    private Output $output;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/reporion-scan-test-' . uniqid();
        $this->sourceDir = $this->tempDir . '/source/reports';
        mkdir($this->sourceDir, 0755, true);
        mkdir($this->tempDir . '/data/import', 0755, true);

        $this->output = new Output();

        // Create test structure
        $dirs = [
            'ct/scuc',
            'mri/scuc',
            'attic/old',
            'templates/standard',
        ];
        foreach ($dirs as $dir) {
            mkdir($this->sourceDir . '/' . $dir, 0755, true);
        }

        // Create test files
        file_put_contents($this->sourceDir . '/ct/scuc/210101-test1.txt', 'Test content 1');
        file_put_contents($this->sourceDir . '/mri/scuc/210102-test2.txt', 'Test content 2');
        file_put_contents($this->sourceDir . '/attic/old/ancient.txt', 'Old content');
        file_put_contents($this->sourceDir . '/templates/standard/default.txt', 'Template');
        file_put_contents($this->sourceDir . '/ct/2022.txt', 'Year bundle');
        file_put_contents($this->sourceDir . '/start.txt', 'Start');
        file_put_contents($this->sourceDir . '/sidebar.txt', 'Sidebar');
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

    public function testScanCreatesManifest(): void
    {
        $cmd = new ImportScanCommand($this->tempDir . '/data', []);
        $result = $cmd->run(
            [
                '--from=' . $this->sourceDir,
                '--batch=test-batch',
            ],
            $this->output
        );

        $this->assertEquals(0, $result);

        $manifestFile = $this->tempDir . '/data/import/test-batch/manifest.json';
        $this->assertFileExists($manifestFile);
    }

    public function testScanExcludesAttic(): void
    {
        $cmd = new ImportScanCommand($this->tempDir . '/data', []);
        $cmd->run(
            [
                '--from=' . $this->sourceDir,
                '--batch=test-batch',
            ],
            $this->output
        );

        $manifest = json_decode(
            file_get_contents($this->tempDir . '/data/import/test-batch/manifest.json'),
            true
        );
        $files = array_column($manifest['files'], 'relpath');

        $this->assertNotContains('attic/old/ancient.txt', $files);
    }

    public function testScanExcludesTemplates(): void
    {
        $cmd = new ImportScanCommand($this->tempDir . '/data', []);
        $cmd->run(
            [
                '--from=' . $this->sourceDir,
                '--batch=test-batch',
            ],
            $this->output
        );

        $manifest = json_decode(
            file_get_contents($this->tempDir . '/data/import/test-batch/manifest.json'),
            true
        );
        $files = array_column($manifest['files'], 'relpath');

        $this->assertNotContains('templates/standard/default.txt', $files);
    }

    public function testScanExcludesSpecialFiles(): void
    {
        $cmd = new ImportScanCommand($this->tempDir . '/data', []);
        $cmd->run(
            [
                '--from=' . $this->sourceDir,
                '--batch=test-batch',
            ],
            $this->output
        );

        $manifest = json_decode(
            file_get_contents($this->tempDir . '/data/import/test-batch/manifest.json'),
            true
        );
        $files = array_column($manifest['files'], 'relpath');

        $this->assertNotContains('start.txt', $files);
        $this->assertNotContains('sidebar.txt', $files);
    }

    public function testScanExcludesYearBundles(): void
    {
        $cmd = new ImportScanCommand($this->tempDir . '/data', []);
        $cmd->run(
            [
                '--from=' . $this->sourceDir,
                '--batch=test-batch',
            ],
            $this->output
        );

        $manifest = json_decode(
            file_get_contents($this->tempDir . '/data/import/test-batch/manifest.json'),
            true
        );
        $files = array_column($manifest['files'], 'relpath');

        $this->assertNotContains('ct/2022.txt', $files);
    }

    public function testScanIncludesValidFiles(): void
    {
        $cmd = new ImportScanCommand($this->tempDir . '/data', []);
        $cmd->run(
            [
                '--from=' . $this->sourceDir,
                '--batch=test-batch',
            ],
            $this->output
        );

        $manifest = json_decode(
            file_get_contents($this->tempDir . '/data/import/test-batch/manifest.json'),
            true
        );
        $files = array_column($manifest['files'], 'relpath');

        $this->assertContains('ct/scuc/210101-test1.txt', $files);
        $this->assertContains('mri/scuc/210102-test2.txt', $files);
    }

    public function testScanRecordsSHA256(): void
    {
        $cmd = new ImportScanCommand($this->tempDir . '/data', []);
        $cmd->run(
            [
                '--from=' . $this->sourceDir,
                '--batch=test-batch',
            ],
            $this->output
        );

        $manifest = json_decode(
            file_get_contents($this->tempDir . '/data/import/test-batch/manifest.json'),
            true
        );

        foreach ($manifest['files'] as $file) {
            $this->assertNotEmpty($file['sha256']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $file['sha256']);
        }
    }

    public function testScanDryRun(): void
    {
        $cmd = new ImportScanCommand($this->tempDir . '/data', []);
        $result = $cmd->run(
            [
                '--from=' . $this->sourceDir,
                '--batch=test-batch-dry',
                '--dry-run',
            ],
            $this->output
        );

        $this->assertEquals(0, $result);

        // Manifest should not be created in dry-run
        $manifestFile = $this->tempDir . '/data/import/test-batch-dry/manifest.json';
        $this->assertFileDoesNotExist($manifestFile);
    }
}
