<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Http\Request;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * End to end through real objects — Kernel::boot() → Router → PageController
 * → Storage\FlatFile + Index\Sqlite + Service\Render — for the one route
 * this build-order step ships: GET /{path}. This is where the visibility
 * matrix (tests/Visibility) actually meets an HTTP request.
 */
final class PageViewTest extends TestCase
{
    private string $dataRoot;

    /** @var array<string, mixed> */
    private array $config;

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
        ];
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataRoot);
        parent::tearDown();
    }

    public function testPublicPageRendersForAnonymousVisitor(): void
    {
        $this->createPage('reports:mri:mioveni:public-x', 'public', 'Titlu Public', "## Concluzie\n\nText liber.\n");

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:public-x'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Titlu Public', $response->body);
        self::assertStringContainsString('<h2', $response->body);
        self::assertStringContainsString('Concluzie', $response->body);
    }

    public function testPrivatePageIs404ForAnonymousVisitor(): void
    {
        $this->createPage('reports:mri:mioveni:private-x', 'private', 'Titlu Privat', 'Text.');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:private-x'));

        self::assertSame(404, $response->status);
        self::assertStringNotContainsString('Titlu Privat', $response->body);
    }

    public function testPrivatePageRendersForOwner(): void
    {
        $this->createPage('reports:mri:mioveni:private-y', 'private', 'Titlu Privat', 'Text.');

        $session = new Session('test-secret', 'reporion', 3600);
        $ownerCookie = $session->issue();

        $response = Kernel::boot($this->config)->handle(
            new Request('GET', '/reports:mri:mioveni:private-y', cookies: ['reporion' => $ownerCookie])
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Titlu Privat', $response->body);
    }

    public function testUnlistedPageIsReachableByDirectPathForAnonymous(): void
    {
        $this->createPage('reports:mri:mioveni:unlisted-x', 'unlisted', 'Titlu Nelistat', 'Text.');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:unlisted-x'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Titlu Nelistat', $response->body);
    }

    public function testUnknownPathIs404(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:does-not-exist'));

        self::assertSame(404, $response->status);
    }

    private function createPage(string $path, string $visibility, string $title, string $body): void
    {
        // A separate Sqlite connection from the one Kernel::boot() will open
        // per request, deliberately: proves the write is durable and
        // re-readable through a fresh connection, not an artifact of a
        // shared in-process handle.
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        $storage = new FlatFile($this->dataRoot, $index);
        $storage->create($path, ['title' => $title, 'visibility' => $visibility], $body, 'owner');
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
