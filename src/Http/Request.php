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
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $cookies = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: \is_string($path) && $path !== '' ? $path : '/',
            query: array_map(strval(...), $_GET),
            cookies: array_map(strval(...), $_COOKIE),
        );
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }
}
