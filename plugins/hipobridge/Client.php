<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Hipobridge;

use Closure;

/**
 * HippoBridge's FHIR interface over HTTP: GET, Basic auth (one service
 * account, forwarded by HippoBridge to Hipocrate), JSON back. Read only —
 * the plugin never writes to the HIS.
 *
 * Queries carry a CNP or a name, so no URL, query or response text ever
 * reaches an exception message or a log line (invariant 8): a failure says
 * only what kind of failure it was.
 *
 * The transport is injectable for tests: fn (string $url, array $headers,
 * int $timeout): array{0: int, 1: string} — HTTP status and body.
 */
final class Client
{
    private readonly Closure $transport;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout,
        ?Closure $transport = null,
    ) {
        $this->transport = $transport ?? self::httpTransport(...);
    }

    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->username !== '';
    }

    /**
     * A FHIR resource (or Bundle, or OperationOutcome) as an array.
     *
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     *
     * @throws HisException
     */
    public function get(string $path, array $query = []): array
    {
        if (!$this->configured()) {
            throw new HisException('not-configured');
        }
        $url = $this->baseUrl . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        [$status, $body] = ($this->transport)($url, [
            'Accept: application/fhir+json, application/json',
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password),
            'User-Agent: Reporion-hipobridge/0.1',
        ], $this->timeout);
        if ($status === 0) {
            throw new HisException('unreachable');
        }
        if ($status === 401 || $status === 403) {
            throw new HisException('auth');
        }
        if ($status === 404) {
            return ['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'information', 'code' => 'not-found']]];
        }
        if ($status < 200 || $status >= 300) {
            throw new HisException('http-' . $status);
        }
        $data = json_decode($body, true);
        if (!\is_array($data)) {
            throw new HisException('bad-response');
        }

        return $data;
    }

    /**
     * @param list<string> $headers
     *
     * @return array{0: int, 1: string}
     */
    private static function httpTransport(string $url, array $headers, int $timeout): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $body = @file_get_contents($url, false, $context);
        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header ?? [];
        if ($body === false || $responseHeaders === []) {
            return [0, ''];
        }
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $m) === 1 ? (int) $m[1] : 0;

        return [$status, $body];
    }
}
