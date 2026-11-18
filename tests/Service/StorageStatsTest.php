<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use Reporion\Service\StorageStats;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * The start page's Storage panel: sizes by part, kept for an hour, never a path (invariant 8).
 */
final class StorageStatsTest extends StorageTestCase
{
    public function testMeasuresPartsAndKeepsTheResult(): void
    {
        mkdir($this->dataRoot . '/pages/reports', 0775, true);
        mkdir($this->dataRoot . '/attic/reports', 0775, true);
        file_put_contents($this->dataRoot . '/pages/reports/a.md', str_repeat('x', 100));
        file_put_contents($this->dataRoot . '/pages/b.md', str_repeat('x', 50));
        file_put_contents($this->dataRoot . '/attic/reports/a.zip', str_repeat('x', 300));
        file_put_contents($this->dataRoot . '/index.sqlite', str_repeat('x', 40));

        $stats = (new StorageStats($this->dataRoot))->get(1000);

        self::assertSame(['bytes' => 150, 'files' => 2], $stats['parts']['pages']);
        self::assertSame(['bytes' => 300, 'files' => 1], $stats['parts']['attic']);
        self::assertSame(['bytes' => 40, 'files' => 1], $stats['parts']['index']);
        self::assertSame(['bytes' => 0, 'files' => 0], $stats['parts']['media']);
        self::assertSame(490, $stats['total']);
        self::assertSame(0, $stats['pages']);
        self::assertSame(0, $stats['backup']);
        self::assertIsInt($stats['free']);
        self::assertFileExists($this->dataRoot . '/' . StorageStats::CACHE);
        self::assertStringNotContainsString($this->dataRoot, (string) file_get_contents($this->dataRoot . '/' . StorageStats::CACHE));
        self::assertStringNotContainsString('reports', json_encode($stats, JSON_THROW_ON_ERROR));

        // Within the hour: the kept numbers, though the disk changed
        file_put_contents($this->dataRoot . '/pages/c.md', str_repeat('x', 1000));
        self::assertSame(490, (new StorageStats($this->dataRoot))->get(1000 + StorageStats::TTL - 1)['total']);
        // After it, or on a refresh: measured again
        self::assertSame(1490, (new StorageStats($this->dataRoot))->get(1000 + StorageStats::TTL)['total']);
        file_put_contents($this->dataRoot . '/pages/d.md', 'x');
        self::assertSame(1491, (new StorageStats($this->dataRoot))->get(1000 + StorageStats::TTL, true)['total']);
    }

    public function testNewestBackupArchiveCounts(): void
    {
        mkdir($this->dataRoot . '/backups', 0775, true);
        file_put_contents($this->dataRoot . '/backups/a.tar.gz', 'x');
        touch($this->dataRoot . '/backups/a.tar.gz', 123456);

        self::assertSame(123456, (new StorageStats($this->dataRoot))->get(1000)['backup']);
    }
}
