<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Reporion\Cli\PagesScanCommand;
use Reporion\Cli\Output;

final class PagesScanCommandTest extends TestCase
{
    private string $tempDir;
    private string $sourceDir;
    private Output $output;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/reporion-pages-scan-test-' . uniqid();
        $this->sourceDir = $this->tempDir . '/source/pages';
        mkdir($this->sourceDir, 0755, true);
        mkdir($this->tempDir . '/data/import', 0755, true);
        mkdir($this->tempDir . '/conf', 0755, true);

        $this->output = new Output(fopen('php://memory', 'w'), fopen('php://memory', 'w'));

        // Create test structure
        $dirs = [
            'bookmarks/tech',
            'documents/poetry',
            'reports/mri/scuc',
            'wiki',
            'playground',
            'templates/standard',
        ];
        foreach ($dirs as $dir) {
            mkdir($this->sourceDir . '/' . $dir, 0755, true);
        }

        file_put_contents($this->sourceDir . '/bookmarks/tech/link1.txt', 'Content 1');
        file_put_contents($this->sourceDir . '/documents/poetry/poem1.txt', 'Poem content');
        file_put_contents($this->sourceDir . '/reports/mri/scuc/report.txt', 'Report content');
        file_put_contents($this->sourceDir . '/wiki/welcome.txt', 'Welcome');
        file_put_contents($this->sourceDir . '/playground/test.txt', 'Test');
        file_put_contents($this->sourceDir . '/templates/standard/default.txt', 'Template');
        file_put_contents($this->sourceDir . '/start.txt', 'Start');
        file_put_contents($this->sourceDir . '/sidebar.txt', 'Sidebar');

        $pageImportMap = [
            'namespace_map' => ['documents' => 'docs'],
            'skip_dirs' => ['reports', 'wiki', 'playground'],
            'default_visibility' => 'private',
            'skip_paths' => [],
        ];
        file_put_contents($this->tempDir . '/conf/page-import-map.json', json_encode($pageImportMap, JSON_PRETTY_PRINT));
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
        $cmd = new PagesScanCommand($this->tempDir . '/data');
        $result = $cmd->run(
            [
                '--from=' . $this->sourceDir,
                '--batch=test-batch',
            ],
            $this->output
        );

        self::assertEquals(0, $result);

        $manifestFile = $this->tempDir . '/data/import/test-batch/manifest.json';
        self::assertFileExists($manifestFile);
    }

    public function testScanExcludesReportsDirectory(): void
    {
        $cmd = new PagesScanCommand($this->tempDir . '/data');
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

        self::assertNotContains('reports/mri/scuc/report.txt', $files);
    }

    public function testScanExcludesWikiDirectory(): void
    {
        $cmd = new PagesScanCommand($this->tempDir . '/data');
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

        self::assertNotContains('wiki/welcome.txt', $files);
    }

    public function testScanExcludesPlaygroundDirectory(): void
    {
        $cmd = new PagesScanCommand($this->tempDir . '/data');
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

        self::assertNotContains('playground/test.txt', $files);
    }

    public function testScanExcludesTemplatesDirectory(): void
    {
        $cmd = new PagesScanCommand($this->tempDir . '/data');
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

        self::assertNotContains('templates/standard/default.txt', $files);
    }

    public function testScanExcludesSpecialFiles(): void
    {
        $cmd = new PagesScanCommand($this->tempDir . '/data');
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

        self::assertNotContains('start.txt', $files);
        self::assertNotContains('sidebar.txt', $files);
    }

    public function testScanIncludesValidFiles(): void
    {
        $cmd = new PagesScanCommand($this->tempDir . '/data');
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

        self::assertContains('bookmarks/tech/link1.txt', $files);
        self::assertContains('documents/poetry/poem1.txt', $files);
    }

    public function testScanRecordsResolvedNamespace(): void
    {
        $cmd = new PagesScanCommand($this->tempDir . '/data');
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

        $byPath = [];
        foreach ($manifest['files'] as $file) {
            $byPath[$file['relpath']] = $file['namespace'];
        }

        self::assertSame('bookmarks', $byPath['bookmarks/tech/link1.txt']);
        self::assertSame('docs', $byPath['documents/poetry/poem1.txt']);
    }

    public function testScanRecordsSHA256(): void
    {
        $cmd = new PagesScanCommand($this->tempDir . '/data');
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
            self::assertNotEmpty($file['sha256']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $file['sha256']);
        }
    }

    public function testScanDryRun(): void
    {
        $cmd = new PagesScanCommand($this->tempDir . '/data');
        $result = $cmd->run(
            [
                '--from=' . $this->sourceDir,
                '--batch=test-batch-dry',
                '--dry-run',
            ],
            $this->output
        );

        self::assertEquals(0, $result);

        $manifestFile = $this->tempDir . '/data/import/test-batch-dry/manifest.json';
        self::assertFileDoesNotExist($manifestFile);
    }
}
