<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Websearch\Fetch;
use Reporion\Plugin\Websearch\Http;
use Reporion\Plugin\Websearch\Search;
use RuntimeException;

/**
 * The websearch plugin (D67): Brave's and DuckDuckGo's answers read, a page's
 * best passage, and the addresses the server refuses to fetch.
 */
final class WebsearchTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        foreach (['Http', 'Search', 'Fetch', 'Plugin'] as $class) {
            require_once \dirname(__DIR__, 2) . '/plugins/websearch/' . $class . '.php';
        }
    }

    public function testBraveResults(): void
    {
        $json = json_encode(['web' => ['results' => [
            ['title' => 'Meningioma <strong>MRI</strong>', 'url' => 'https://radiopaedia.org/articles/meningioma', 'description' => 'Extra-axial &amp; dural tail'],
            ['title' => 'Plain', 'url' => 'http://insecure.example/a', 'description' => 'left out: not https'],
            ['title' => 'Second', 'url' => 'https://example.org/b', 'description' => 'b'],
        ]]]);

        $results = Search::parseBrave((string) $json, 5);
        self::assertSame([
            ['title' => 'Meningioma MRI', 'url' => 'https://radiopaedia.org/articles/meningioma', 'snippet' => 'Extra-axial & dural tail'],
            ['title' => 'Second', 'url' => 'https://example.org/b', 'snippet' => 'b'],
        ], $results);
        self::assertCount(1, Search::parseBrave((string) $json, 1));
    }

    public function testDuckDuckGoResultsUnwrapTheirLinks(): void
    {
        $html = '<div class="results"><div class="result results_links"><h2><a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.org%2Fx&amp;rut=1">Title X</a></h2>'
            . '<a class="result__snippet" href="#">Snippet X</a></div>'
            . '<div class="result"><a class="result__a" href="https://example.com/y">Title Y</a></div></div>';

        self::assertSame([
            ['title' => 'Title X', 'url' => 'https://example.org/x', 'snippet' => 'Snippet X'],
            ['title' => 'Title Y', 'url' => 'https://example.com/y', 'snippet' => ''],
        ], Search::parseDdg($html, 5));
    }

    public function testSearchThroughTheTransportSendsOnlyTheQuery(): void
    {
        $asked = [];
        $http = new Http(5, static function (string $url, array $headers) use (&$asked): array {
            $asked[] = [$url, $headers];

            return [200, (string) json_encode(['web' => ['results' => [['title' => 'T', 'url' => 'https://e.org/', 'description' => 'd']]]]), []];
        });
        (new Search($http, 'brave', 'k'))->search('dural tail sign', 3);

        self::assertSame('https://api.search.brave.com/res/v1/web/search?q=dural%20tail%20sign&count=3', $asked[0][0]);
        self::assertContains('X-Subscription-Token: k', $asked[0][1]);
        $this->expectException(RuntimeException::class);
        (new Search($http, 'brave', ''))->search('x', 3);
    }

    public function testTheBestPassageOfAPage(): void
    {
        $html = '<html><head><script>var meningioma = 1;</script></head><body><nav><p>Meningioma meningioma navigation link text here and more</p></nav>'
            . '<p>An introduction that says nothing in particular about the subject at hand.</p>'
            . '<p>Meningioma shows a dural tail and homogeneous enhancement on post-contrast MRI.</p></body></html>';
        $paragraphs = Fetch::paragraphs($html);

        self::assertCount(2, $paragraphs, 'script and navigation are not text');
        self::assertStringContainsString('Meningioma shows', Fetch::best($paragraphs, 'meningioma dural tail'));
        self::assertSame('', Fetch::best($paragraphs, 'zzz'));
    }

    public function testFetchFollowsRedirectsAndReadsOnlyHtml(): void
    {
        $http = new Http(5, static fn (string $url): array => match ($url) {
            'https://a.example/' => [301, '', ['location' => '/page']],
            'https://a.example/page' => [200, '<p>The dural tail sign is common in meningioma of the convexity.</p>', ['content-type' => 'text/html; charset=utf-8']],
            default => [200, '%PDF', ['content-type' => 'application/pdf']],
        });
        $fetch = new Fetch($http);

        self::assertStringContainsString('dural tail', $fetch->passage('https://a.example/', 'dural tail'));
        self::assertSame('', $fetch->passage('https://b.example/x.pdf', 'dural'));
        self::assertSame('', $fetch->passage('http://a.example/', 'dural'), 'not https: refused');
    }

    public function testRefusedAddresses(): void
    {
        self::assertFalse(Http::publicAddresses(['127.0.0.1']));
        self::assertFalse(Http::publicAddresses(['10.1.2.3']));
        self::assertFalse(Http::publicAddresses(['192.168.3.50']));
        self::assertFalse(Http::publicAddresses(['::1']));
        self::assertFalse(Http::publicAddresses(['169.254.169.254']));
        self::assertFalse(Http::publicAddresses([]));
        self::assertTrue(Http::publicAddresses(['93.184.216.34']));
        foreach (['http://e.org/', 'file:///etc/passwd', 'https://user:pw@e.org/', 'gopher://e.org'] as $url) {
            try {
                Http::check($url);
                self::fail($url);
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('https://e.org/a/c', Http::resolve('https://e.org/a/b', 'c'));
        self::assertSame('https://e.org/c', Http::resolve('https://e.org/a/b', '/c'));
    }

    public function testPreferredDomainsComeFirstInTheEnginesOrder(): void
    {
        $r = static fn (string $url): array => ['title' => $url, 'url' => $url, 'snippet' => ''];
        $results = [$r('https://blog.example/a'), $r('https://pmc.ncbi.nlm.nih.gov/x'), $r('https://notradiopaedia.org/'), $r('https://radiopaedia.org/y')];

        self::assertSame(
            ['https://pmc.ncbi.nlm.nih.gov/x', 'https://radiopaedia.org/y', 'https://blog.example/a', 'https://notradiopaedia.org/'],
            array_column(\Reporion\Plugin\Websearch\Plugin::preferred($results, ['radiopaedia.org', 'ncbi.nlm.nih.gov']), 'url'),
            'a subdomain counts, a look-alike does not',
        );
        self::assertSame($results, \Reporion\Plugin\Websearch\Plugin::preferred($results, []));
    }
}
