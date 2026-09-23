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

        // A 500 must leave a trace somewhere, or the next one is
        // undiagnosable outside a debugger. error_log() reaches the SAPI's
        // own error stream (php -S's console, PHP-FPM's error_log) with no
        // config needed. Class + file:line only, never the message — a
        // domain exception's message could carry page/patient data
        // (invariant 8), and this path has no way to know it doesn't.
        error_log(\sprintf('%s at %s:%d', $e::class, $e->getFile(), $e->getLine()));

        return new Response(500, 'Internal error', ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
