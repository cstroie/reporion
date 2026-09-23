<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use Reporion\Exception\PageNotFoundException;
use Throwable;

/**
 * Domain exceptions mapped to HTTP status in exactly one place (CLAUDE.md
 * "Errors"). Never a filesystem path in the body — that would leak the
 * patient path (invariant 8) to whoever is looking at the response.
 */
final class ErrorMapper
{
    public static function map(Throwable $e): Response
    {
        if ($e instanceof PageNotFoundException) {
            return Response::notFound();
        }

        return new Response(500, 'Internal error', ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
