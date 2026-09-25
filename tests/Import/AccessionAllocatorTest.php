<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Import;

use DateTime;
use PHPUnit\Framework\TestCase;
use Reporion\Import\AccessionAllocator;

final class AccessionAllocatorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/reporion-accession-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
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

    public function testAllocationPattern(): void
    {
        $allocator = new AccessionAllocator($this->tempDir, [], 'Europe/Bucharest');
        $date = new DateTime('2021-05-25', new \DateTimeZone('Europe/Bucharest'));

        $accession = $allocator->allocate('scuc', ['CT'], $date);

        // Pattern: {SITE}-{MOD}-{yy}-{seq}
        // Should be: SCUC-CT-21-0001
        $this->assertMatchesRegularExpression('/^[A-Z]+-[A-Z]+-\d{2}-\d{4}$/', $accession);
        $this->assertStringStartsWith('SCUC-CT-21-', $accession);
    }

    public function testSequenceIncrement(): void
    {
        $allocator = new AccessionAllocator($this->tempDir, [], 'Europe/Bucharest');
        $date = new DateTime('2021-05-25', new \DateTimeZone('Europe/Bucharest'));

        $acc1 = $allocator->allocate('scuc', ['CT'], $date);
        $acc2 = $allocator->allocate('scuc', ['CT'], $date);
        $acc3 = $allocator->allocate('scuc', ['CT'], $date);

        // Sequences should increment
        $this->assertStringEndsWith('-0001', $acc1);
        $this->assertStringEndsWith('-0002', $acc2);
        $this->assertStringEndsWith('-0003', $acc3);
    }

    public function testPerSiteSequenceIndependence(): void
    {
        $allocator = new AccessionAllocator($this->tempDir, [], 'Europe/Bucharest');
        $date = new DateTime('2021-05-25', new \DateTimeZone('Europe/Bucharest'));

        $scuc1 = $allocator->allocate('scuc', ['CT'], $date);
        $medima1 = $allocator->allocate('medima', ['MR'], $date);
        $scuc2 = $allocator->allocate('scuc', ['CT'], $date);

        // Each site should have independent sequence
        $this->assertStringContainsString('SCUC-CT-21-0001', $scuc1);
        $this->assertStringContainsString('MEDIMA-MR-21-0001', $medima1);
        $this->assertStringContainsString('SCUC-CT-21-0002', $scuc2);
    }

    public function testPerModalitySequenceIndependence(): void
    {
        $allocator = new AccessionAllocator($this->tempDir, [], 'Europe/Bucharest');
        $date = new DateTime('2021-05-25', new \DateTimeZone('Europe/Bucharest'));

        $ct1 = $allocator->allocate('scuc', ['CT'], $date);
        $mr1 = $allocator->allocate('scuc', ['MR'], $date);
        $ct2 = $allocator->allocate('scuc', ['CT'], $date);

        // Each modality should have independent sequence
        $this->assertStringContainsString('CT-21-0001', $ct1);
        $this->assertStringContainsString('MR-21-0001', $mr1);
        $this->assertStringContainsString('CT-21-0002', $ct2);
    }

    public function testPersistenceAcrossInstances(): void
    {
        $allocator1 = new AccessionAllocator($this->tempDir, [], 'Europe/Bucharest');
        $date = new DateTime('2021-05-25', new \DateTimeZone('Europe/Bucharest'));

        $acc1 = $allocator1->allocate('scuc', ['CT'], $date);
        $this->assertStringEndsWith('-0001', $acc1);

        // Create new instance with same temp dir
        $allocator2 = new AccessionAllocator($this->tempDir, [], 'Europe/Bucharest');
        $acc2 = $allocator2->allocate('scuc', ['CT'], $date);

        // Should increment from previous value
        $this->assertStringEndsWith('-0002', $acc2);
    }

    public function testDifferentYears(): void
    {
        $allocator = new AccessionAllocator($this->tempDir, [], 'Europe/Bucharest');

        $date2021 = new DateTime('2021-05-25', new \DateTimeZone('Europe/Bucharest'));
        $date2022 = new DateTime('2022-05-25', new \DateTimeZone('Europe/Bucharest'));

        $acc2021 = $allocator->allocate('scuc', ['CT'], $date2021);
        $acc2022 = $allocator->allocate('scuc', ['CT'], $date2022);

        // Year component should be different
        $this->assertStringContainsString('21-', $acc2021);
        $this->assertStringContainsString('22-', $acc2022);

        // But both should have -0001 sequence (independent by year)
        $this->assertStringEndsWith('-0001', $acc2021);
        $this->assertStringEndsWith('-0001', $acc2022);
    }

    public function testMultipleModalities(): void
    {
        $allocator = new AccessionAllocator($this->tempDir, [], 'Europe/Bucharest');
        $date = new DateTime('2021-05-25', new \DateTimeZone('Europe/Bucharest'));

        // Combined study with multiple modalities
        $accession = $allocator->allocate('scuc', ['CT', 'MR'], $date);

        // Should use first modality in sequence
        $this->assertMatchesRegularExpression('/SCUC-(CT|MR)-21-/', $accession);
    }
}
