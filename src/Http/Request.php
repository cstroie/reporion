<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

/**
 * Immutable, so a controller can be handed one and a test can construct one
 * by hand without touching superglobals (fromGlobals() is the only place
 * that does).
 */
final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $cookies
     * @param array<string, list<string>> $queryLists the query's `name[]=` parameters — kept apart, so
     *                                                $query stays strings for every reader
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $cookies = [],
        public readonly string $body = '',
        public readonly string $basePath = '',
        // For the audit trail only (docs/FORMATS.md §6)
        public readonly string $remoteAddr = '',
        public readonly string $userAgent = '',
        // Arrived over HTTPS (directly or via the TLS proxy in front) — the
        // session cookie is then marked Secure
        public readonly bool $secure = false,
        // The Authorization header: an API bearer token (roadmap phase 13)
        public readonly string $authorization = '',
        public readonly array $queryLists = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: self::pathFromGlobals(),
            query: array_map(strval(...), array_filter($_GET, static fn (mixed $v): bool => !\is_array($v))),
            cookies: array_map(strval(...), $_COOKIE),
            body: (string) file_get_contents('php://input'),
            basePath: self::basePathFromGlobals(),
            remoteAddr: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            userAgent: (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            secure: (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
            // Some FastCGI setups pass it only under the REDIRECT_ name
            authorization: (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''),
            // The timeline's ticked studies (`paths[]`) for /{path}/compare
            queryLists: array_map(
                static fn (array $values): array => array_values(array_filter($values, 'is_string')),
                array_filter($_GET, 'is_array'),
            ),
        );
    }

    /**
     * Confirmed live: this app is not always mounted at the web server's
     * root (this box serves it at /reporion/). Every absolute link a
     * template emits (assets, forms, page URLs) must be prefixed with this
     * so it resolves correctly regardless of where the app is mounted.
     * Derived from SCRIPT_NAME rather than config, so it is always correct
     * for wherever the app actually is right now, in either deployment
     * mode: lighttpd's rewrite sets SCRIPT_NAME to the front controller's
     * own mounted path ("/reporion/index.php"); PHP's built-in dev server
     * does the same at the root ("/index.php").
     */
    public static function basePathFromGlobals(): string
    {
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $basePath = rtrim(\dirname($scriptName), '/');

        return $basePath === '.' ? '' : $basePath;
    }

    /**
     * The app is not always mounted at the web server's root (confirmed
     * live: this box serves it at /reporion/ via a rewrite to
     * index.php/$path). PATH_INFO — what a front-controller rewrite target
     * of the form index.php/$1 produces — is already the clean, prefix-free
     * logical path in that case. Confirmed empirically that PHP's built-in
     * dev server (php -S ... public/router.php) populates it too, the same
     * way, for any request that does not match a real file — so this
     * preference holds in both deployment modes; REQUEST_URI is only a
     * fallback for the rare case PATH_INFO is genuinely absent (a request
     * for a real file with no extra path segments).
     */
    private static function pathFromGlobals(): string
    {
        $pathInfo = $_SERVER['PATH_INFO'] ?? null;
        if (\is_string($pathInfo) && $pathInfo !== '') {
            return $pathInfo;
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        return \is_string($path) && $path !== '' ? $path : '/';
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * Decoded JSON body, or an empty array if it is missing or not a JSON object.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $decoded = json_decode($this->body, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
