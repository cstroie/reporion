<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use PHPUnit\Framework\TestCase;
use Reporion\Auth\User;
use Reporion\Http\Request;
use Reporion\Http\Session;

final class SessionTest extends TestCase
{
    public function testAnIssuedCookieResolvesToItsPrincipal(): void
    {
        $users = new InMemoryUserStore();
        $users->put(new User('alice', 'x', false, [], true, 'now', 'now'));
        $session = new Session('secret', 'reporion', 3600, $users);
        $cookie = $session->issue('alice');

        $request = new Request('GET', '/x', cookies: ['reporion' => $cookie]);

        self::assertSame('alice', $session->principal($request)?->username);
    }

    public function testAnOwnerAccountIsRecognisedAsOwner(): void
    {
        $users = new InMemoryUserStore();
        $users->put(new User('root', 'x', true, [], true, 'now', 'now'));
        $session = new Session('secret', 'reporion', 3600, $users);
        $cookie = $session->issue('root');

        self::assertTrue($session->isOwner(new Request('GET', '/x', cookies: ['reporion' => $cookie])));
    }

    public function testANonOwnerAccountIsNotRecognisedAsOwner(): void
    {
        $users = new InMemoryUserStore();
        $users->put(new User('alice', 'x', false, [], true, 'now', 'now'));
        $session = new Session('secret', 'reporion', 3600, $users);
        $cookie = $session->issue('alice');

        self::assertFalse($session->isOwner(new Request('GET', '/x', cookies: ['reporion' => $cookie])));
    }

    public function testNoCookieIsAnonymous(): void
    {
        $session = new Session('secret', 'reporion', 3600, new InMemoryUserStore());

        self::assertNull($session->principal(new Request('GET', '/x')));
        self::assertFalse($session->isOwner(new Request('GET', '/x')));
    }

    public function testTamperedCookieIsRejected(): void
    {
        $users = new InMemoryUserStore();
        $users->put(new User('alice', 'x', true, [], true, 'now', 'now'));
        $session = new Session('secret', 'reporion', 3600, $users);
        $cookie = $session->issue('alice');
        $tampered = substr($cookie, 0, -1) . (str_ends_with($cookie, 'A') ? 'B' : 'A');

        $request = new Request('GET', '/x', cookies: ['reporion' => $tampered]);

        self::assertNull($session->principal($request));
    }

    public function testCookieSignedWithADifferentSecretIsRejected(): void
    {
        $users = new InMemoryUserStore();
        $users->put(new User('alice', 'x', true, [], true, 'now', 'now'));
        $cookie = (new Session('secret-one', 'reporion', 3600, $users))->issue('alice');
        $session = new Session('secret-two', 'reporion', 3600, $users);

        self::assertNull($session->principal(new Request('GET', '/x', cookies: ['reporion' => $cookie])));
    }

    public function testExpiredCookieIsRejected(): void
    {
        $users = new InMemoryUserStore();
        $users->put(new User('alice', 'x', true, [], true, 'now', 'now'));
        $session = new Session('secret', 'reporion', -1, $users);
        $cookie = $session->issue('alice');

        self::assertNull($session->principal(new Request('GET', '/x', cookies: ['reporion' => $cookie])));
    }

    /**
     * A deleted account must stop authenticating immediately, even though
     * its cookie is still validly signed and unexpired — otherwise
     * removing someone's access does nothing until their 30-day cookie
     * happens to expire on its own.
     */
    public function testCookieForAnUnknownUsernameIsRejected(): void
    {
        $session = new Session('secret', 'reporion', 3600, new InMemoryUserStore());
        $cookie = $session->issue('nobody');

        self::assertNull($session->principal(new Request('GET', '/x', cookies: ['reporion' => $cookie])));
    }

    /**
     * Same durability requirement (D35) as the unknown-username case, but
     * for an account that still exists and was explicitly deactivated
     * rather than deleted.
     */
    public function testCookieForADeactivatedAccountIsRejected(): void
    {
        $users = new InMemoryUserStore();
        $users->put(new User('alice', 'x', false, [], false, 'now', 'now'));
        $session = new Session('secret', 'reporion', 3600, $users);
        $cookie = $session->issue('alice');

        self::assertNull($session->principal(new Request('GET', '/x', cookies: ['reporion' => $cookie])));
    }

    public function testLoginCookieHeaderIsHttpOnlyAndSameSiteLax(): void
    {
        $header = (new Session('secret', 'reporion', 3600, new InMemoryUserStore()))->loginCookieHeader('alice');

        self::assertStringContainsString('reporion=', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringContainsString('Max-Age=3600', $header);
    }

    public function testLoginCookieHeaderValueIsRecognisedOnceParsedBack(): void
    {
        $users = new InMemoryUserStore();
        $users->put(new User('alice', 'x', false, [], true, 'now', 'now'));
        $session = new Session('secret', 'reporion', 3600, $users);
        $header = $session->loginCookieHeader('alice');

        // Same parsing shape $_COOKIE would produce: "name=value; attr; attr".
        [$pair] = explode(';', $header, 2);
        [, $value] = explode('=', $pair, 2);

        $request = new Request('GET', '/x', cookies: ['reporion' => rawurldecode($value)]);

        self::assertSame('alice', $session->principal($request)?->username);
    }

    public function testLogoutCookieHeaderClearsIt(): void
    {
        $header = (new Session('secret', 'reporion', 3600, new InMemoryUserStore()))->logoutCookieHeader();

        self::assertStringContainsString('reporion=;', $header);
        self::assertStringContainsString('Max-Age=0', $header);
    }
}
