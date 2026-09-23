<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

/**
 * The /api/v1 wire contract (docs/architecture-api.md §3): a bare JSON
 * object on success, {error: {code, message, fields?}} on failure, real
 * HTTP statuses either way.
 */
final class ApiResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): Response
    {
        return new Response(
            $status,
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    /**
     * @param array<string, mixed>|null $fields
     */
    public static function error(int $status, string $code, string $message, ?array $fields = null): Response
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        return self::json(['error' => $error], $status);
    }
}
