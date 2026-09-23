<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use PHPUnit\Framework\TestCase;
use Reporion\Http\Request;
use Reporion\Http\Session;

final class SessionTest extends TestCase
{
    public function testAnIssuedCookieIsRecognisedAsOwner(): void
    {
        $session = new Session('secret', 'reporion', 3600);
        $cookie = $session->issue();

        $request = new Request('GET', '/x', cookies: ['reporion' => $cookie]);

        self::assertTrue($session->isOwner($request));
    }

    public function testNoCookieIsAnonymous(): void
    {
        $session = new Session('secret', 'reporion', 3600);

        self::assertFalse($session->isOwner(new Request('GET', '/x')));
    }

    public function testTamperedCookieIsRejected(): void
    {
        $session = new Session('secret', 'reporion', 3600);
        $cookie = $session->issue();
        $tampered = substr($cookie, 0, -1) . (str_ends_with($cookie, 'A') ? 'B' : 'A');

        $request = new Request('GET', '/x', cookies: ['reporion' => $tampered]);

        self::assertFalse($session->isOwner($request));
    }

    public function testCookieSignedWithADifferentSecretIsRejected(): void
    {
        $cookie = (new Session('secret-one', 'reporion', 3600))->issue();
        $session = new Session('secret-two', 'reporion', 3600);

        self::assertFalse($session->isOwner(new Request('GET', '/x', cookies: ['reporion' => $cookie])));
    }

    public function testExpiredCookieIsRejected(): void
    {
        $session = new Session('secret', 'reporion', -1);
        $cookie = $session->issue();

        self::assertFalse($session->isOwner(new Request('GET', '/x', cookies: ['reporion' => $cookie])));
    }

    public function testLoginCookieHeaderIsHttpOnlyAndSameSiteLax(): void
    {
        $header = (new Session('secret', 'reporion', 3600))->loginCookieHeader();

        self::assertStringContainsString('reporion=', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringContainsString('Max-Age=3600', $header);
    }

    public function testLoginCookieHeaderValueIsRecognisedAsOwnerOnceParsedBack(): void
    {
        $session = new Session('secret', 'reporion', 3600);
        $header = $session->loginCookieHeader();

        // Same parsing shape $_COOKIE would produce: "name=value; attr; attr".
        [$pair] = explode(';', $header, 2);
        [, $value] = explode('=', $pair, 2);

        $request = new Request('GET', '/x', cookies: ['reporion' => rawurldecode($value)]);

        self::assertTrue($session->isOwner($request));
    }

    public function testLogoutCookieHeaderClearsIt(): void
    {
        $header = (new Session('secret', 'reporion', 3600))->logoutCookieHeader();

        self::assertStringContainsString('reporion=;', $header);
        self::assertStringContainsString('Max-Age=0', $header);
    }
}
