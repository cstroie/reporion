<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Reporion\Cli\ImportConvertCommand;
use Reporion\Cli\ImportScanCommand;
use Reporion\Cli\Output;

final class ImportConvertCommandTest extends TestCase
{
    private string $tempDir;
    private string $sourceDir;
    private Output $output;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/reporion-import-test-' . uniqid();
        $this->sourceDir = $this->tempDir . '/source';
        mkdir($this->sourceDir, 0755, true);
        mkdir($this->tempDir . '/data/import', 0755, true);

        $this->output = new Output(fopen('php://memory', 'w'), fopen('php://memory', 'w'));

        // Create import-map.json for the scan command
        $importMap = [
            'folder_to_site' => [
                'ct/scuc' => ['site' => 'scuc', 'modality' => ['CT']],
                'mri/scuc' => ['site' => 'scuc', 'modality' => ['MR']],
            ],
            'device_by_site_modality' => [
                'scuc:CT' => 'SCUC-CT-01',
                'scuc:MR' => 'SCUC-MR-01',
            ],
            'title_keywords' => [],
            'date_formats' => ['d.m.Y'],
            'timezone' => 'Europe/Bucharest',
            'skip_paths' => [],
        ];
        file_put_contents($this->tempDir . '/data/import-map.json', json_encode($importMap, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        // Create a test source file
        $testDir = $this->sourceDir . '/reports/ct/scuc';
        mkdir($testDir, 0755, true);
        file_put_contents($testDir . '/210525-test-patient.txt', $this->getSampleReport());
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

    public function testConversionCreatesFiles(): void
    {
        // First scan to create manifest
        $scanCmd = new ImportScanCommand($this->tempDir . '/data', []);
        $scanCmd->run(
            [
                '--from=' . $this->sourceDir . '/reports',
                '--batch=test-batch',
            ],
            $this->output
        );

        // Then convert
        $convertCmd = new ImportConvertCommand($this->tempDir . '/data');
        $result = $convertCmd->run(
            [
                '--batch=test-batch',
                '--map=' . __DIR__ . '/../../data/import-map.json',
            ],
            $this->output
        );

        $this->assertEquals(0, $result);

        // Check that converted file exists
        $convertedFile = $this->tempDir . '/data/import/test-batch/converted/ct/scuc/210525-test-patient.txt';
        $this->assertFileExists($convertedFile);
    }

    public function testConversionWithLimit(): void
    {
        // Scan first
        $scanCmd = new ImportScanCommand($this->tempDir . '/data', []);
        $scanCmd->run(
            [
                '--from=' . $this->sourceDir . '/reports',
                '--batch=test-batch',
            ],
            $this->output
        );

        // Convert with limit
        $convertCmd = new ImportConvertCommand($this->tempDir . '/data');
        $result = $convertCmd->run(
            [
                '--batch=test-batch',
                '--map=' . __DIR__ . '/../../data/import-map.json',
                '--limit=1',
            ],
            $this->output
        );

        $this->assertEquals(0, $result);
    }

    public function testConversionWritesFrontmatter(): void
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

        // Check converted file contains YAML frontmatter
        $convertedFile = $this->tempDir . '/data/import/test-batch/converted/ct/scuc/210525-test-patient.txt';
        $content = file_get_contents($convertedFile);

        $this->assertStringStartsWith('---', $content);
        $this->assertStringContainsString('status: archived', $content);
        $this->assertStringContainsString('visibility: private', $content);
    }

    public function testConversionCreatePathmap(): void
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

        // Check pathmap exists
        $pathMapFile = $this->tempDir . '/data/import/test-batch/pathmap.json';
        $this->assertFileExists($pathMapFile);

        $pathMap = json_decode(file_get_contents($pathMapFile), true);
        $this->assertIsArray($pathMap);
    }

    public function testConversionCreatesReview(): void
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

        // Check review.json exists
        $reviewFile = $this->tempDir . '/data/import/test-batch/review.json';
        $this->assertFileExists($reviewFile);

        $review = json_decode(file_get_contents($reviewFile), true);
        $this->assertIsArray($review);
        $this->assertArrayHasKey('total', $review);
        $this->assertArrayHasKey('review_items', $review);
    }
}
