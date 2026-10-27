<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Auth\UserStoreInterface;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Http\Throttle;
use Reporion\Http\View;
use Reporion\Support\Notes;

/**
 * GET/POST /login, POST /logout (docs/architecture-api.md §2). D35:
 * multiple accounts, username + argon2id password, a signed session
 * cookie: still no 2FA. A classic form POST + redirect, matching "a form.
 * Nothing else."; the notes app (D55), which talks JSON only, signs in
 * through /api/v1/session with the same check and the same cookie.
 */
final class AuthController
{
    /**
     * A fixed, never-matching argon2id hash, verified against on any
     * unknown username so that password_verify() runs the same cost
     * either way: otherwise a missing account returns 401 measurably
     * faster than a wrong password for a real one, which is a username
     * oracle. The hash itself is meaningless; only its cost parameters
     * matter.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function __construct(
        private readonly UserStoreInterface $users,
        private readonly Session $session,
        private readonly AuditLog $audit,
        // Failed sign-ins per address (D56 hardening): after FAILURES in WINDOW, the address waits
        private readonly ?Throttle $throttle = null,
    ) {
    }

    public const FAILURES = 5;
    public const WINDOW = 900;

    /** Too many failed sign-ins from this address lately: nothing is checked until the window passes */
    private function blocked(Request $request): bool
    {
        return $this->throttle !== null && $this->throttle->count('login:' . $request->remoteAddr, self::WINDOW) >= self::FAILURES;
    }

    /** A failed sign-in counted against its address, a good one clears them */
    private function counted(Request $request, ?User $user): ?User
    {
        if ($this->throttle !== null) {
            $user === null ? $this->throttle->hit('login:' . $request->remoteAddr, self::WINDOW) : $this->throttle->clear('login:' . $request->remoteAddr);
        }

        return $user;
    }

    public function form(Request $request): Response
    {
        return Response::html(View::render($this->templatePath(), ['error' => false, 'basePath' => $request->basePath, 'next' => self::next($request->query['next'] ?? null)]));
    }

    /**
     * Where to go after signing in: a path on this site only (`/oauth/authorize?…`, D56), never
     * another host (`//x`, `/\x`, a scheme), else the start page
     */
    public static function next(mixed $next): string
    {
        return \is_string($next) && preg_match('~^/(?![/\\\\])[^\s]*$~', $next) === 1 && \strlen($next) <= 2000 ? $next : '/';
    }

    public function login(Request $request): Response
    {
        parse_str($request->body, $fields);
        if ($this->blocked($request)) {
            $this->audit->record('auth.throttled', 'anonymous', $request, outcome: 'refused');

            return Response::html(View::render($this->templatePath(), ['error' => true, 'throttled' => true, 'basePath' => $request->basePath, 'next' => self::next($fields['next'] ?? null)]), 429)->withHeader('Retry-After', (string) self::WINDOW);
        }
        $user = $this->counted($request, $this->authenticate($request, $fields['username'] ?? null, $fields['password'] ?? null));
        if ($user === null) {
            return Response::html(View::render($this->templatePath(), ['error' => true, 'basePath' => $request->basePath, 'next' => self::next($fields['next'] ?? null)]), 401);
        }

        return Response::redirect($request->basePath . self::next($fields['next'] ?? null))->withHeader('Set-Cookie', $this->session->loginCookieHeader($user->username, $request->secure));
    }

    /**
     * POST /api/v1/session { username, password } -> 200 { data: account }
     * and the same session cookie as the form sign-in: the notes app's
     * sign-in (D55), which talks JSON only. Wrong credentials, an unknown or
     * a deactivated account: 401 invalid_credentials, all alike.
     */
    public function apiLogin(Request $request): Response
    {
        $fields = $request->json();
        if ($this->blocked($request)) {
            return ApiResponse::error(429, 'too_many_attempts', t('auth.throttled'))->withHeader('Retry-After', (string) self::WINDOW);
        }
        $user = $this->counted($request, $this->authenticate($request, $fields['username'] ?? null, $fields['password'] ?? null));
        if ($user === null) {
            return ApiResponse::error(401, 'invalid_credentials', t('auth.invalid'));
        }

        return ApiResponse::json(['data' => self::account($user)])
            ->withHeader('Set-Cookie', $this->session->loginCookieHeader($user->username, $request->secure));
    }

    /** GET /api/v1/session -> 200 { data: account } for the signed-in caller, else 401 not_signed_in */
    public function apiShow(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            return ApiResponse::error(401, 'not_signed_in', t('auth.signed_out'));
        }

        return ApiResponse::json(['data' => self::account($principal)]);
    }

    /** DELETE /api/v1/session -> 204, the session cookie cleared */
    public function apiLogout(Request $request): Response
    {
        return (new Response(204, '', []))->withHeader('Set-Cookie', $this->session->logoutCookieHeader($request->secure));
    }

    public function logout(Request $request): Response
    {
        return Response::redirect($request->basePath . '/login')->withHeader('Set-Cookie', $this->session->logoutCookieHeader($request->secure));
    }

    /**
     * The account a username and password name, or null: every failure
     * alike, and the same password_verify() cost for an unknown username.
     */
    private function authenticate(Request $request, mixed $username, mixed $password): ?User
    {
        $username = \is_string($username) ? trim($username) : '';
        $password = \is_string($password) ? $password : '';

        $user = null;
        if ($username !== '') {
            try {
                $user = $this->users->find($username);
            } catch (InvalidArgumentException) {
                $user = null;
            }
        }

        $hash = $user?->passwordHash ?? self::DUMMY_HASH;
        $valid = password_verify($password, $hash) && $user !== null && $user->active;

        if (!$valid) {
            // The attempted username only when it is username-shaped: a
            // password typed into the wrong field must never be logged
            $attempted = preg_match('/^[a-z0-9](?:[a-z0-9_.-]{0,62}[a-z0-9])?$/', $username) === 1 ? $username : '(invalid)';
            $this->audit->record('login.fail', $attempted, $request, outcome: 'denied');

            return null;
        }

        $this->audit->record('login', $user->username, $request);

        return $user;
    }

    /**
     * @return array{username: string, display_name: string, owner: bool, notes: ?string}
     */
    private static function account(User $user): array
    {
        return [
            'username' => $user->username,
            'display_name' => $user->signatureName(),
            'owner' => $user->isOwner,
            'notes' => $user->personalNamespace() !== null ? Notes::namespaceFor($user->username) : null,
        ];
    }

    private function templatePath(): string
    {
        return \dirname(__DIR__, 2) . '/templates/login.php';
    }
}
