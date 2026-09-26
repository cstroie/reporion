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
        $this->createOwner();
    }

    public function testLoginFormRenders(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('GET', '/login'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('action="/login"', $response->body);
        self::assertStringContainsString('type="password"', $response->body);
        self::assertStringContainsString('name="username"', $response->body);
    }

    public function testCorrectCredentialsRedirectAndSetACookie(): void
    {
        $response = Kernel::boot($this->config)->handle(
            new Request('POST', '/login', body: 'username=owner&password=correct-horse')
        );

        self::assertSame(302, $response->status);
        self::assertSame('/', $response->headers['Location']);
        self::assertStringContainsString('HttpOnly', $response->headers['Set-Cookie']);

        // The cookie this response sets must actually authenticate the next
        // request: a signed-in caller gets the dashboard at /, an anonymous
        // one gets site:home in the chrome-free layout-public.php.
        $cookieValue = $this->cookieValueFrom($response->headers['Set-Cookie']);
        $followUp = Kernel::boot($this->config)->handle(
            new Request('GET', '/', cookies: ['reporion' => $cookieValue])
        );
        self::assertStringContainsString('<h1 class="wk-doc-title">' . t('dash.title') . '</h1>', $followUp->body);
    }

    public function testWrongPasswordIs401WithNoSetCookie(): void
    {
        $response = Kernel::boot($this->config)->handle(
            new Request('POST', '/login', body: 'username=owner&password=wrong-password')
        );

        self::assertSame(401, $response->status);
        self::assertArrayNotHasKey('Set-Cookie', $response->headers);
        self::assertStringContainsString('Incorrect', $response->body);
    }

    public function testUnknownUsernameIs401WithNoSetCookie(): void
    {
        $response = Kernel::boot($this->config)->handle(
            new Request('POST', '/login', body: 'username=nobody&password=correct-horse')
        );

        self::assertSame(401, $response->status);
        self::assertArrayNotHasKey('Set-Cookie', $response->headers);
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

    public function testTheSessionCookieIsSecureOnlyOverHttps(): void
    {
        $https = Kernel::boot($this->config)->handle(new Request('POST', '/login', body: 'username=owner&password=correct-horse', secure: true));
        $http = Kernel::boot($this->config)->handle(new Request('POST', '/login', body: 'username=owner&password=correct-horse'));

        self::assertStringEndsWith('; Secure', $https->headers['Set-Cookie']);
        self::assertStringNotContainsString('Secure', $http->headers['Set-Cookie']);
    }
}

