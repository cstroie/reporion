<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Http\View;

/**
 * GET/POST /login, POST /logout (docs/architecture-api.md §2). D13: one
 * owner account, argon2id, a signed session cookie — no username, no 2FA,
 * no user table. A classic form POST + redirect (never a JSON fetch),
 * matching "a form. Nothing else."
 */
final class AuthController
{
    public function __construct(
        private readonly string $ownerPasswordHash,
        private readonly Session $session,
    ) {
    }

    public function form(Request $request): Response
    {
        return Response::html(View::render($this->templatePath(), ['error' => false, 'basePath' => $request->basePath]));
    }

    public function login(Request $request): Response
    {
        parse_str($request->body, $fields);
        $password = \is_string($fields['password'] ?? null) ? $fields['password'] : '';

        if ($this->ownerPasswordHash === '' || !password_verify($password, $this->ownerPasswordHash)) {
            return Response::html(View::render($this->templatePath(), ['error' => true, 'basePath' => $request->basePath]), 401);
        }

        return Response::redirect($request->basePath . '/')->withHeader('Set-Cookie', $this->session->loginCookieHeader());
    }

    public function logout(Request $request): Response
    {
        return Response::redirect($request->basePath . '/login')->withHeader('Set-Cookie', $this->session->logoutCookieHeader());
    }

    private function templatePath(): string
    {
        return \dirname(__DIR__, 2) . '/templates/login.php';
    }
}
