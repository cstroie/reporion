<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Http\Request;
use Reporion\Kernel;

/**
 * GET/POST /login, POST /logout, end to end through the real Kernel — the
 * one place tests/Http/SessionTest.php's pure-logic cookie handling meets
 * an actual HTTP flow.
 */
final class AuthTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->config['auth']['owner_password_hash'] = password_hash('correct-horse', PASSWORD_ARGON2ID);
    }

    public function testLoginFormRenders(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('GET', '/login'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('action="/login"', $response->body);
        self::assertStringContainsString('type="password"', $response->body);
    }

    public function testCorrectPasswordRedirectsAndSetsAnOwnerCookie(): void
    {
        $response = Kernel::boot($this->config)->handle(
            new Request('POST', '/login', body: 'password=correct-horse')
        );

        self::assertSame(302, $response->status);
        self::assertSame('/', $response->headers['Location']);
        self::assertStringContainsString('HttpOnly', $response->headers['Set-Cookie']);

        // The cookie this response sets must actually authenticate the next
        // request: an owner-recognised caller gets page-view.php (which
        // carries a data-path attribute), an anonymous one gets the
        // chrome-free layout-public.php (which never does).
        $cookieValue = $this->cookieValueFrom($response->headers['Set-Cookie']);
        $followUp = Kernel::boot($this->config)->handle(
            new Request('GET', '/', cookies: ['reporion' => $cookieValue])
        );
        self::assertStringContainsString('data-path=', $followUp->body);
    }

    public function testWrongPasswordIs401WithNoSetCookie(): void
    {
        $response = Kernel::boot($this->config)->handle(
            new Request('POST', '/login', body: 'password=wrong-password')
        );

        self::assertSame(401, $response->status);
        self::assertArrayNotHasKey('Set-Cookie', $response->headers);
        self::assertStringContainsString('Incorrect password', $response->body);
    }

    public function testLogoutClearsTheCookieAndRedirectsToLogin(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('POST', '/logout'));

        self::assertSame(302, $response->status);
        self::assertSame('/login', $response->headers['Location']);
        self::assertStringContainsString('Max-Age=0', $response->headers['Set-Cookie']);
    }

    private function cookieValueFrom(string $setCookieHeader): string
    {
        [$pair] = explode(';', $setCookieHeader, 2);
        [, $value] = explode('=', $pair, 2);

        return rawurldecode($value);
    }
}
