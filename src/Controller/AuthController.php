<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\UserStoreInterface;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Http\View;

/**
 * GET/POST /login, POST /logout (docs/architecture-api.md §2). D35:
 * multiple accounts, username + argon2id password, a signed session
 * cookie — still no 2FA. A classic form POST + redirect (never a JSON
 * fetch), matching "a form. Nothing else."
 */
final class AuthController
{
    /**
     * A fixed, never-matching argon2id hash, verified against on any
     * unknown username so that password_verify() runs the same cost
     * either way — otherwise a missing account returns 401 measurably
     * faster than a wrong password for a real one, which is a username
     * oracle. The hash itself is meaningless; only its cost parameters
     * matter.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function __construct(
        private readonly UserStoreInterface $users,
        private readonly Session $session,
        private readonly AuditLog $audit,
    ) {
    }

    public function form(Request $request): Response
    {
        return Response::html(View::render($this->templatePath(), ['error' => false, 'basePath' => $request->basePath]));
    }

    public function login(Request $request): Response
    {
        parse_str($request->body, $fields);
        $username = \is_string($fields['username'] ?? null) ? trim($fields['username']) : '';
        $password = \is_string($fields['password'] ?? null) ? $fields['password'] : '';

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

            return Response::html(View::render($this->templatePath(), ['error' => true, 'basePath' => $request->basePath]), 401);
        }

        $this->audit->record('login', $user->username, $request);

        return Response::redirect($request->basePath . '/')->withHeader('Set-Cookie', $this->session->loginCookieHeader($user->username, $request->secure));
    }

    public function logout(Request $request): Response
    {
        return Response::redirect($request->basePath . '/login')->withHeader('Set-Cookie', $this->session->logoutCookieHeader($request->secure));
    }

    private function templatePath(): string
    {
        return \dirname(__DIR__, 2) . '/templates/login.php';
    }
}
