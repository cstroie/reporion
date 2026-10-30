<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Library;

use PHPUnit\Framework\TestCase;
use Reporion\Exception\LibraryException;
use Reporion\Service\Library\WebClip;

/** Web clip (docs/library.md, phase 7): what may be fetched, and from which address; no network */
final class WebClipTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: string}> what the fetch was asked */
    private array $asked = [];

    /**
     * @param array<string, list<string>>                                              $dns
     * @param array<string, array{status: int, headers: list<string>, body: string}> $pages by URL
     */
    private function clip(array $dns, array $pages): WebClip
    {
        return new WebClip(
            1000,
            static fn (string $host): array => $dns[$host] ?? [],
            function (string $url, string $address, string $host, int $max) use ($pages): array {
                $this->asked[] = [$url, $address, $host];

                return $pages[$url] ?? ['status' => 404, 'headers' => [], 'body' => ''];
            },
        );
    }

    public function testOnlyHttpOnItsPortWithNoUserInfo(): void
    {
        self::assertSame(['scheme' => 'https', 'host' => 'example.org', 'port' => 443], WebClip::parse('https://example.org/a?b=1'));
        self::assertNotNull(WebClip::parse('http://example.org:80/'));
        foreach (['ftp://example.org/', 'file:///etc/passwd', 'https://example.org:8443/', 'http://example.org:443/', 'https://user:pw@example.org/', 'https://example.org/a b', 'gopher://x/', 'https:///nohost'] as $url) {
            self::assertNull(WebClip::parse($url), $url);
        }
    }

    public function testOnlyPublicAddresses(): void
    {
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '100.64.0.1', '169.254.169.254', '0.0.0.0', '224.0.0.1', '255.255.255.255', '198.18.0.1', '::1', '::', 'fc00::1', 'fd12::1', 'fe80::1', 'ff02::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::7f00:1', '2001:db8::1'] as $ip) {
            self::assertFalse(WebClip::isPublic($ip), $ip);
        }
        foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '::ffff:8.8.8.8'] as $ip) {
            self::assertTrue(WebClip::isPublic($ip), $ip);
        }
    }

    public function testThePageIsFetchedFromTheAddressCheckedWithItsHost(): void
    {
        $page = ['status' => 200, 'headers' => ['HTTP/1.1 200 OK', 'Content-Type: text/html; charset=utf-8'], 'body' => '<h1>A page</h1>'];

        $got = $this->clip(['example.org' => ['2606:4700::1', '93.184.216.34']], ['https://example.org/a' => $page])->fetch('https://example.org/a');

        self::assertSame(['bytes' => '<h1>A page</h1>', 'ext' => 'html', 'url' => 'https://example.org/a', 'host' => 'example.org'], $got);
        self::assertSame([['https://example.org/a', '93.184.216.34', 'example.org']], $this->asked, 'pinned to the address checked, IPv4 first');
    }

    public function testEveryAddressOfAHostMustBePublic(): void
    {
        $this->expectExceptionObject(new LibraryException(LibraryException::INVALID));
        try {
            $this->clip(['mixed.example' => ['93.184.216.34', '127.0.0.1']], [])->fetch('https://mixed.example/');
        } finally {
            self::assertSame([], $this->asked, 'nothing fetched');
        }
    }

    public function testARedirectIsCheckedAgainAndFollowedAtMostThreeTimes(): void
    {
        $redirect = static fn (string $to): array => ['status' => 302, 'headers' => ['HTTP/1.1 302 Found', 'Location: ' . $to], 'body' => ''];
        $pdf = ['status' => 200, 'headers' => ['Content-Type: application/pdf'], 'body' => '%PDF-1.4'];
        $dns = ['a.example' => ['93.184.216.34'], 'b.example' => ['93.184.216.35'], 'inside.example' => ['10.0.0.5']];

        $got = $this->clip($dns, ['https://a.example/x/y' => $redirect('z.pdf'), 'https://a.example/x/z.pdf' => $redirect('https://b.example/f.pdf'), 'https://b.example/f.pdf' => $pdf])->fetch('https://a.example/x/y');
        self::assertSame(['pdf', 'https://b.example/f.pdf'], [$got['ext'], $got['url']]);

        $this->asked = [];
        try {
            $this->clip($dns, ['https://a.example/' => $redirect('http://inside.example/admin')])->fetch('https://a.example/');
            self::fail('a redirect to a private address is refused');
        } catch (LibraryException $e) {
            self::assertSame(LibraryException::INVALID, $e->reason);
            self::assertCount(1, $this->asked, 'the private address is never asked');
        }

        try {
            $this->clip($dns, ['https://a.example/' => $redirect('/')])->fetch('https://a.example/');
            self::fail('a redirect loop ends');
        } catch (LibraryException $e) {
            self::assertSame(LibraryException::FAILED, $e->reason);
        }
    }

    public function testOnlyHtmlPdfAndTextAreKept(): void
    {
        $this->expectExceptionObject(new LibraryException(LibraryException::UNSUPPORTED));

        $this->clip(['a.example' => ['93.184.216.34']], ['https://a.example/i' => ['status' => 200, 'headers' => ['Content-Type: image/png'], 'body' => 'PNG']])->fetch('https://a.example/i');
    }

    public function testTheRealFetchConnectsToTheAddressAndSendsTheHost(): void
    {
        $dir = sys_get_temp_dir() . '/webclip-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/router.php', '<?php header("Content-Type: text/plain"); if ($_SERVER["REQUEST_URI"] === "/big") { echo str_repeat("x", 5000); return; } echo "host=" . ($_SERVER["HTTP_HOST"] ?? "") . " uri=" . $_SERVER["REQUEST_URI"];');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; ++$i) {
                usleep(50000);
            }
            $http = new \ReflectionMethod(WebClip::class, 'http');
            $http->setAccessible(true);

            $answer = $http->invoke(null, 'http://site.example:' . $port . '/page?q=1', '127.0.0.1', 'site.example', 1000);

            self::assertSame(200, $answer['status']);
            self::assertSame('host=site.example:' . $port . ' uri=/page?q=1', $answer['body'], 'connected to the address given, the host in the Host header');
            $this->expectExceptionObject(new LibraryException(LibraryException::TOO_LARGE));
            $http->invoke(null, 'http://site.example:' . $port . '/big', '127.0.0.1', 'site.example', 1000);
        } finally {
            proc_terminate($server);
            proc_close($server);
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    public function testAnErrorStatusIsAFailure(): void
    {
        $this->expectExceptionObject(new LibraryException(LibraryException::FAILED));

        $this->clip(['a.example' => ['93.184.216.34']], [])->fetch('https://a.example/missing');
    }
}
