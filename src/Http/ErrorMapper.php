<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Index\IndexInterface;
use Throwable;

/**
 * Domain exceptions → HTTP responses, in one place (CLAUDE.md) — every
 * exception out of a route ends up in render() (Kernel::handle()). Pages
 * follow design/mockup/WikiErrors.dc.html.
 *
 * - 404 for a signed-in caller: inside the app shell, naming the path that
 *   was asked for, with a search link and — for someone who may write
 *   there — "Create this page".
 * - 404 for an anonymous caller: a bare page that says nothing beyond "not
 *   found", whether the path is private or does not exist (invariant 9).
 * - /api/… routes: the JSON error shape (docs/architecture-api.md §3).
 * - 500: the same generic page for everyone; the class and file:line go to
 *   the error log, never the message (it could carry page or patient data,
 *   invariant 8), and nothing reaches the response.
 */
final class ErrorMapper
{
    public function __construct(
        private readonly IndexInterface $index,
        private readonly Session $session,
    ) {
    }

    public function render(Throwable $e, Request $request): Response
    {
        $notFound = $e instanceof PageNotFoundException;
        if (!$notFound) {
            error_log(\sprintf('%s at %s:%d', $e::class, $e->getFile(), $e->getLine()));
        }

        if (str_starts_with($request->path, '/api/')) {
            return $notFound
                ? ApiResponse::error(404, 'not_found', 'Not found.')
                : ApiResponse::error(500, 'internal', 'Internal error.');
        }

        try {
            return $notFound ? $this->notFound($request) : $this->standalone($request, 500);
        } catch (Throwable) {
            // The error page itself failed — plain text, nothing else
            return self::plain($notFound);
        }
    }

    /**
     * The plain-text mapping, with no request context: 404 for
     * PageNotFoundException, a generic 500 (class + file:line logged, never
     * the message) for anything else.
     */
    public static function map(Throwable $e): Response
    {
        if ($e instanceof PageNotFoundException) {
            return self::plain(true);
        }
        error_log(\sprintf('%s at %s:%d', $e::class, $e->getFile(), $e->getLine()));

        return self::plain(false);
    }

    private static function plain(bool $notFound): Response
    {
        return new Response($notFound ? 404 : 500, $notFound ? 'Not found' : 'Internal error', ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function notFound(Request $request): Response
    {
        $principal = $this->principal($request);
        if ($principal === null) {
            return $this->standalone($request, 404);
        }

        $path = rawurldecode(ltrim($request->path, '/'));
        $isPagePath = $path !== '' && !str_contains($path, '/') && !str_ends_with($path, ':');

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/error.php', [
            'status' => 404,
            'path' => $isPagePath ? $path : null,
            'canCreate' => $isPagePath && $principal->canWrite($path),
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, $isPagePath ? ChromeVars::namespaceOf($path) : ''), t('err.404.title')), 404);
    }

    private function standalone(Request $request, int $status): Response
    {
        return Response::html(View::render(\dirname(__DIR__, 2) . '/templates/error-standalone.php', [
            'status' => $status,
            'basePath' => $request->basePath,
        ] + ChromeVars::theme($request)), $status);
    }

    private function principal(Request $request): ?User
    {
        try {
            return $this->session->principal($request);
        } catch (Throwable) {
            return null;
        }
    }
}
