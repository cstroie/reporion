<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Closure;
use Reporion\Exception\LibraryException;

/**
 * Web clip (docs/library.md, phase 7; opt-in, `library.web_clip`): a web page, a PDF or a text file
 * fetched by its address, for the library's upload to read as any file. The server fetches what a
 * writer typed, so the address is checked as an attacker's would be (server-side request
 * forgery):
 *
 * - http on port 80 or https on 443 only, no user name or password in it;
 * - the host resolved here, and every address it has must be public: no loopback, private,
 *   link-local, CGNAT, multicast, reserved, documentation, IPv6 unique-local or IPv4-mapped one;
 * - the request goes to the address checked (the URL holds the IP, the Host header and TLS's
 *   peer name and SNI the host, the certificate verified against the host), so a DNS answer
 *   that changes between the check and the connection (rebinding) cannot send it elsewhere;
 * - redirects are not followed by PHP: at most MAX_REDIRECTS, each address checked again;
 * - no proxy (PHP's streams read no proxy variable), a time limit, a size limit read as it
 *   streams, and only HTML, PDF and plain text kept.
 *
 * Only the address the writer typed leaves the box (invariant 8: never a page's text or path).
 * The resolver and the fetch are closures, so tests run without a network.
 */
final class WebClip
{
    /** Content types kept, and the extension the upload reads them as */
    public const TYPES = ['text/html' => 'html', 'application/xhtml+xml' => 'html', 'application/pdf' => 'pdf', 'text/plain' => 'txt'];

    public const MAX_REDIRECTS = 3;

    /** Seconds for the whole fetch */
    public const TIMEOUT = 15;

    /** @var Closure(string): list<string> */
    private readonly Closure $resolve;

    /** @var Closure(string, string, string, int): array{status: int, headers: list<string>, body: string} */
    private readonly Closure $fetch;

    /**
     * @param ?Closure(string): list<string> $resolve a host's addresses
     * @param ?Closure(string, string, string, int): array{status: int, headers: list<string>, body: string} $fetch
     *        (url, the address to connect to, the host, the most bytes): throws LibraryException TOO_LARGE, FAILED, TIMEOUT
     */
    public function __construct(private readonly int $maxBytes, ?Closure $resolve = null, ?Closure $fetch = null)
    {
        $this->resolve = $resolve ?? self::dns(...);
        $this->fetch = $fetch ?? self::http(...);
    }

    /**
     * @return array{bytes: string, ext: string, url: string, host: string}
     *
     * @throws LibraryException INVALID (an address refused), UNSUPPORTED (not HTML, PDF or text), TOO_LARGE, FAILED, TIMEOUT
     */
    public function fetch(string $url): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $parts = self::parse($url);
            if ($parts === null) {
                throw new LibraryException(LibraryException::INVALID);
            }
            $address = $this->address($parts['host']);
            $answer = ($this->fetch)($url, $address, $parts['host'], $this->maxBytes);
            if (\in_array($answer['status'], [301, 302, 303, 307, 308], true)) {
                $location = self::header($answer['headers'], 'location');
                if ($location === null) {
                    throw new LibraryException(LibraryException::FAILED);
                }
                $url = self::resolveUrl($url, $location);
                continue;
            }
            if ($answer['status'] < 200 || $answer['status'] >= 300) {
                throw new LibraryException(LibraryException::FAILED);
            }
            $type = strtolower(trim(explode(';', (string) self::header($answer['headers'], 'content-type'))[0]));
            if (!isset(self::TYPES[$type])) {
                throw new LibraryException(LibraryException::UNSUPPORTED);
            }
            if (\strlen($answer['body']) > $this->maxBytes) {
                throw new LibraryException(LibraryException::TOO_LARGE);
            }

            return ['bytes' => $answer['body'], 'ext' => self::TYPES[$type], 'url' => $url, 'host' => $parts['host']];
        }
        throw new LibraryException(LibraryException::FAILED);
    }

    /**
     * An address the clip may fetch: http(s), the default port, no user info, a host
     *
     * @return ?array{scheme: string, host: string, port: int}
     */
    public static function parse(string $url): ?array
    {
        if (\strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return null;
        }
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
        $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);
        if (!\in_array($scheme, ['http', 'https'], true) || $host === '' || isset($p['user']) || isset($p['pass'])
            || $port !== ($scheme === 'https' ? 443 : 80)) {
            return null;
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port];
    }

    /** Whether $ip is an address on the public internet */
    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        // An IPv6 address carrying an IPv4 one (mapped, compatible, NAT64) is that IPv4 address
        if (\strlen($bin) === 16 && (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($bin, str_repeat("\0", 12)) || str_starts_with($bin, "\x00\x64\xff\x9b" . str_repeat("\0", 8)))) {
            $v4 = inet_ntop(substr($bin, 12));

            return $v4 !== false && self::isPublic($v4);
        }
        foreach (\strlen($bin) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6 as $cidr) {
            if (self::within($bin, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /** Ranges no clip goes to, beyond PHP's private and reserved ones */
    private const BLOCKED_V4 = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
    private const BLOCKED_V6 = ['::/128', '::1/128', '100::/64', '2001:db8::/32', '2001::/23', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8'];

    private static function within(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = inet_pton($net);
        if ($netBin === false || \strlen($netBin) !== \strlen($bin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;

        return (\ord($bin[$bytes]) & $mask) === (\ord($netBin[$bytes]) & $mask);
    }

    /**
     * The address to connect to: the host's own when it is one, else what it resolves to, every
     * one of them public (an IPv4 one first)
     *
     * @throws LibraryException INVALID
     */
    private function address(string $host): string
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);
        if ($ips === []) {
            throw new LibraryException(LibraryException::FAILED);
        }
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                throw new LibraryException(LibraryException::INVALID);
            }
        }
        usort($ips, static fn (string $a, string $b): int => (str_contains($a, ':') ? 1 : 0) <=> (str_contains($b, ':') ? 1 : 0));

        return $ips[0];
    }

    /** @param list<string> $headers */
    private static function header(array $headers, string $name): ?string
    {
        $found = null;
        foreach ($headers as $line) {
            if (stripos($line, $name . ':') === 0) {
                $found = trim(substr($line, \strlen($name) + 1));
            }
        }

        return $found;
    }

    /** $location (a redirect's) made absolute against $base */
    public static function resolveUrl(string $base, string $location): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $location) === 1) {
            return $location;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'https') . '://' . (str_contains((string) ($p['host'] ?? ''), ':') ? '[' . $p['host'] . ']' : ($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($p['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('~/[^/]*$~', '/', (string) ($p['path'] ?? '/'));

        return $origin . ($dir === '' ? '/' : $dir) . $location;
    }

    /** @return list<string> the host's IPv4 and IPv6 addresses */
    private static function dns(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (\is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return $ips !== [] ? $ips : (@gethostbynamel($host) ?: []);
    }

    /**
     * The fetch itself: to $address, the host in the Host header and as TLS's peer name, read up
     * to $max bytes
     *
     * @return array{status: int, headers: list<string>, body: string}
     *
     * @throws LibraryException TOO_LARGE, FAILED, TIMEOUT
     */
    private static function http(string $url, string $address, string $host, int $max): array
    {
        $p = parse_url($url);
        $port = isset($p['port']) ? ':' . (int) $p['port'] : '';
        $target = $p['scheme'] . '://' . (str_contains($address, ':') ? '[' . $address . ']' : $address) . $port . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        $context = stream_context_create([
            'http' => [
                'method' => 'GET', 'timeout' => self::TIMEOUT, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
                'header' => 'Host: ' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . $port . "\r\nUser-Agent: Reporion library web clip\r\nAccept: text/html, application/pdf, text/plain;q=0.8\r\n",
            ],
            'ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
        ]);
        $deadline = microtime(true) + self::TIMEOUT;
        $stream = @fopen($target, 'r', false, $context);
        if ($stream === false) {
            throw new LibraryException(microtime(true) >= $deadline ? LibraryException::TIMEOUT : LibraryException::FAILED);
        }
        $meta = stream_get_meta_data($stream);
        $headers = array_values(array_filter((array) ($meta['wrapper_data'] ?? []), 'is_string'));
        // The last status line: there is no redirect followed, so the only one
        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('~^HTTP/\S+ (\d{3})~', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        $body = '';
        while (!feof($stream)) {
            if (microtime(true) >= $deadline) {
                fclose($stream);
                throw new LibraryException(LibraryException::TIMEOUT);
            }
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            $body .= $chunk;
            if (\strlen($body) > $max) {
                fclose($stream);
                throw new LibraryException(LibraryException::TOO_LARGE);
            }
        }
        fclose($stream);

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
