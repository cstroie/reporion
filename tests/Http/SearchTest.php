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
 * GET /search (docs/architecture-api.md §1 Table 1): the first, shareable
 * result page. Visibility is whatever Index\Sqlite::search() already
 * enforces (tests/Visibility) — this suite is about the HTTP route and the
 * snippet-escaping path, not re-proving the visibility matrix.
 */
final class SearchTest extends TestCase
{
    private string $dataRoot;

    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataRoot = sys_get_temp_dir() . '/reporion-search-test-' . bin2hex(random_bytes(6));
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

    public function testEmptyQueryShowsThePromptNotAllResults(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'RM cerebral', 'text');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/search'));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('RM cerebral', $response->body);
    }

    public function testFindsAMatchingPublicPageForAnonymous(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'RM cerebral', 'fara leziuni demielinizante');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/search', query: ['q' => 'demielinizante']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('RM cerebral', $response->body);
        self::assertStringContainsString('<mark>demielinizante</mark>', $response->body);
    }

    public function testPrivatePageNeverAppearsInAnonymousResults(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Secret Title', 'unieuword12345');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/search', query: ['q' => 'unieuword12345']));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('Secret Title', $response->body);
    }

    /**
     * The bug this guards against: snippet() returns raw markdown body
     * text, not HTML. Echoing it directly around genuinely-trusted <mark>
     * tags would let report text containing "<"/"&" break out as live
     * markup — caught in review before it shipped.
     */
    public function testReportBodyWithHtmlLookingTextIsEscapedInTheSnippet(): void
    {
        $this->createPage(
            'reports:mri:mioveni:a',
            'public',
            'RM cerebral',
            'valoare presiune <script>alert(1)</script> sub 10mmHg si peste 5mmHg'
        );

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/search', query: ['q' => 'presiune']));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body);
        self::assertStringContainsString('&lt;script&gt;', $response->body);
    }

    /**
     * A raw, unescaped query term is FTS5 query syntax (quotes, AND/OR/NOT,
     * column filters) — a caller-supplied string must not be able to throw
     * a 500 by sending malformed syntax.
     */
    public function testMalformedQuerySyntaxDoesNotCrash(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'RM cerebral', 'unieuword12345');
        $kernel = Kernel::boot($this->config);

        $response = $kernel->handle(new Request('GET', '/search', query: ['q' => '"unterminated AND (']));
        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('RM cerebral', $response->body);

        // The malformed query must not have wedged anything — an ordinary
        // query right after still finds the page, proving the FTS engine
        // ran rather than the route swallowing every result silently.
        $followUp = $kernel->handle(new Request('GET', '/search', query: ['q' => 'unieuword12345']));
        self::assertStringContainsString('RM cerebral', $followUp->body);
    }

    /**
     * Bug this guards against: an earlier ftsPhrase() quoted the whole term
     * as one FTS5 phrase, which only matches tokens adjacent and in order —
     * "leziuni demielinizante" would not find "leziuni multiple
     * demielinizante", breaking ordinary multi-word clinical queries.
     */
    public function testMultiWordQueryMatchesWordsThatAreNotAdjacent(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'RM cerebral', 'leziuni multiple demielinizante active');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/search', query: ['q' => 'leziuni demielinizante']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('RM cerebral', $response->body);
    }

    private function createPage(string $path, string $visibility, string $title, string $body): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create($path, ['title' => $title, 'visibility' => $visibility], $body, 'owner');
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
