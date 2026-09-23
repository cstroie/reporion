<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Http\Request;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * GET / (docs/architecture-api.md §6): anonymous gets site:home (or a
 * built-in stub if it does not exist), and the public layout — not the
 * owner one — is what a page-view route uses for an anonymous caller (A4).
 */
final class HomeTest extends TestCase
{
    private string $dataRoot;

    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataRoot = sys_get_temp_dir() . '/reporion-home-test-' . bin2hex(random_bytes(6));
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
        ];
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataRoot);
        parent::tearDown();
    }

    public function testRendersSiteHomeForAnonymousInThePublicLayout(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create(
            'site:home',
            ['title' => 'Welcome', 'visibility' => 'public'],
            "## Bine ati venit\n\nText.\n",
            'owner'
        );

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Welcome', $response->body);
        // The public layout, unlike page-view.php, carries no data-path/
        // data-rev attributes and no visibility/status chrome.
        self::assertStringNotContainsString('data-path=', $response->body);
        self::assertStringNotContainsString('data-rev=', $response->body);
    }

    public function testFallsBackToABuiltInStubWhenSiteHomeDoesNotExist(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('GET', '/'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('exists yet', $response->body);
    }

    /**
     * pageAccessClause() (what findByPath() enforces) allows unlisted for
     * anonymous, because it assumes the caller already has the exact path.
     * "/" is the landing page, not knowledge of site:home's path — an
     * unlisted site:home must NOT become the public landing page.
     */
    public function testUnlistedSiteHomeIsNotShownToAnonymousEitherByStayingOnTheStub(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create(
            'site:home',
            ['title' => 'Unlisted Title', 'visibility' => 'unlisted'],
            'Text.',
            'owner'
        );

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/'));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('Unlisted Title', $response->body);
    }

    public function testPrivateSiteHomeIsNotShownToAnonymousEitherByStayingOnTheStub(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create(
            'site:home',
            ['title' => 'Secret Dashboard Title', 'visibility' => 'private'],
            'Text.',
            'owner'
        );

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/'));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('Secret Dashboard Title', $response->body);
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
