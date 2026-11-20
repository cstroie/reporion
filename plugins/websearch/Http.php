<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Websearch;

use Closure;
use RuntimeException;

/**
 * GET over HTTPS for the web search and the pages it reads. Only https, only
 * a host that resolves to public addresses (no loopback, private or reserved
 * range: the server must not be made to read its own network), the address
 * pinned for the request, redirects followed by hand (each one checked again),
 * at most MAX_BYTES read. A generic user agent: nothing about the instance or
 * the caller. The transport is injectable for tests:
 * fn (string $url, array $headers, int $timeout): array{0: int, 1: string, 2: array<string, string>}
 * (status, body, lower-case response headers).
 */
final class Http
{
    public const MAX_BYTES = 1_000_000;

    private const REDIRECTS = 3;

    private const USER_AGENT = 'Mozilla/5.0 (compatible; Reporion)';

    private readonly Closure $transport;

    public function __construct(private readonly int $timeout, ?Closure $transport = null)
    {
        $this->transport = $transport ?? self::curl(...);
    }

    /**
     * @param list<string> $headers
     *
     * @return array{0: int, 1: string, 2: array<string, string>, 3: string} status, body, headers, the final URL
     *
     * @throws RuntimeException a URL refused or the request failed
     */
    public function get(string $url, array $headers = []): array
    {
        for ($hop = 0; $hop <= self::REDIRECTS; $hop++) {
            self::check($url);
            [$status, $body, $response] = ($this->transport)($url, $headers, $this->timeout);
            if ($status >= 300 && $status < 400 && isset($response['location'])) {
                $url = self::resolve($url, $response['location']);
                continue;
            }

            return [$status, $body, $response, $url];
        }
        throw new RuntimeException('too many redirects');
    }

    /** @throws RuntimeException not https, or no host */
    public static function check(string $url): void
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || (string) ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('refused address');
        }
    }

    /** Whether every address in $ips is a public one */
    public static function publicAddresses(array $ips): bool
    {
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /** A redirect's target, made absolute against the URL that sent it */
    public static function resolve(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base) ?: [];
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', (string) ($parts['path'] ?? '/'));

        return $origin . $dir . $location;
    }

    /**
     * @param list<string> $headers
     *
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private static function curl(string $url, array $headers, int $timeout): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
        $bare = trim($host, '[]');
        $ips = filter_var($bare, FILTER_VALIDATE_IP) !== false ? [$bare] : self::lookup($bare);
        if (!self::publicAddresses($ips)) {
            throw new RuntimeException('refused address');
        }
        $body = '';
        $response = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => [$bare . ':' . $port . ':' . $ips[0]],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$response): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $response[strtolower(trim($name))] = trim($value);
                }

                return \strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body): int {
                $body .= $chunk;

                // Returning less than given stops the transfer: enough was read
                return \strlen($body) > self::MAX_BYTES ? 0 : \strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($ok === false && $errno !== CURLE_WRITE_ERROR) {
            throw new RuntimeException('no answer');
        }

        return [$status, substr($body, 0, self::MAX_BYTES), $response];
    }

    /** @return list<string> */
    private static function lookup(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (\is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }
}
