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
 * int $timeout): array{0: int, 1: string} — HTTP status and body. getMany()
 * sends its requests side by side through curl_multi when the curl extension
 * is there and no transport was injected, else one after another.
 */
final class Client
{
    private readonly Closure $transport;

    /** No injected transport and curl there: getMany() sends side by side */
    private readonly bool $parallel;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout,
        ?Closure $transport = null,
    ) {
        $this->transport = $transport ?? self::httpTransport(...);
        $this->parallel = $transport === null && \function_exists('curl_multi_init');
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
        [$status, $body] = ($this->transport)($this->url($path, $query), $this->headers(), $this->timeout);

        return self::decode($status, $body);
    }

    /**
     * get() for several requests at once, keyed as given: each answer, or the
     * HisException that request ended in — the caller decides, in its own
     * order, where a failure stops it.
     *
     * @param array<array-key, array{0: string, 1?: array<string, string>}> $requests path and query
     *
     * @return array<array-key, array<string, mixed>|HisException>
     *
     * @throws HisException when the client is not configured
     */
    public function getMany(array $requests): array
    {
        if (!$this->configured()) {
            throw new HisException('not-configured');
        }
        if (!$this->parallel || \count($requests) < 2) {
            $out = [];
            foreach ($requests as $key => $request) {
                try {
                    $out[$key] = $this->get($request[0], $request[1] ?? []);
                } catch (HisException $e) {
                    $out[$key] = $e;
                }
            }

            return $out;
        }

        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $key => $request) {
            $handle = curl_init($this->url($request[0], $request[1] ?? []));
            curl_setopt_array($handle, [
                CURLOPT_HTTPHEADER => $this->headers(),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                // As the stream transport's timeout: no byte for $timeout seconds ends it
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => max(1, $this->timeout),
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$key] = $handle;
        }
        do {
            $code = curl_multi_exec($multi, $active);
            if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                usleep(10000);
            }
        } while ($active > 0 && $code === CURLM_OK);

        $out = [];
        foreach ($handles as $key => $handle) {
            $body = curl_multi_getcontent($handle);
            $status = curl_errno($handle) === 0 ? (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) : 0;
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
            try {
                $out[$key] = self::decode($status, \is_string($body) ? $body : '');
            } catch (HisException $e) {
                $out[$key] = $e;
            }
        }
        curl_multi_close($multi);

        return $out;
    }

    /** @param array<string, string> $query */
    private function url(string $path, array $query): string
    {
        return $this->baseUrl . $path . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            'Accept: application/fhir+json, application/json',
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password),
            'User-Agent: Reporion-hipobridge/0.1',
        ];
    }

    /**
     * An HTTP status and body as get() answers them
     *
     * @return array<string, mixed>
     *
     * @throws HisException
     */
    private static function decode(int $status, string $body): array
    {
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
