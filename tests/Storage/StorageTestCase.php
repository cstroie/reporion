<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

abstract class StorageTestCase extends TestCase
{
    protected string $dataRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataRoot = sys_get_temp_dir() . '/reporion-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataRoot);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
