<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;

/**
 * Shared Kernel::boot() config fixture. Kernel reads new config keys
 * unconditionally as routes get added (site.home_page, then auth.* each
 * broke every hand-rolled config array in this directory in turn) — one
 * shared fixture means a new key only needs adding here.
 */
abstract class HttpTestCase extends TestCase
{
    protected string $dataRoot;

    /** @var array<string, mixed> */
    protected array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataRoot = sys_get_temp_dir() . '/reporion-http-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataRoot, 0775, true);

        $this->config = [
            'paths' => [
                'data' => $this->dataRoot,
                'index' => $this->dataRoot . '/index.sqlite',
            ],
            'auth' => [
                'session_secret' => 'test-secret',
                'session_name' => 'reporion',
                'session_lifetime' => 3600,
            ],
            'site' => [
                'home_page' => 'site:home',
            ],
            'pages' => [
                'trash_purge_days' => 30,
            ],
        ];
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataRoot);
        parent::tearDown();
    }

    protected function createPage(string $path, string $visibility, string $title, string $body): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create($path, ['title' => $title, 'visibility' => $visibility], $body, 'owner');
    }

    /**
     * Seeds a real account in data/users/ — D35: login reads only that
     * store now, there is no config fallback to seed a caller instead.
     */
    protected function createOwner(string $username = 'owner', string $password = 'correct-horse'): void
    {
        (new FlatFileUserStore($this->dataRoot))->create($username, password_hash($password, PASSWORD_ARGON2ID), true);
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
