<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * POST /theme, end to end through the real Kernel (Controller\ThemeController)
 * — the rail's theme-toggle item (chrome slice 4, templates/rail.php).
 * Cookie-based, not localStorage: a plain form POST + redirect, so it
 * works with JavaScript off (docs/architecture-api.md's A5/"Why not
 * SPA-everything").
 */
final class ThemeTest extends HttpTestCase
{
    public function testSettingLightSetsTheCookieAndRedirectsToReturnTo(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/theme',
            body: 'theme=light&return_to=' . urlencode('/reports:mri:mioveni:a')
        ));

        self::assertSame(302, $response->status);
        self::assertSame('/reports:mri:mioveni:a', $response->headers['Location']);
        self::assertStringContainsString('reporion_theme=light', $response->headers['Set-Cookie']);
    }

    public function testAnyValueOtherThanLightIsTreatedAsDark(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/theme',
            body: 'theme=not-a-real-theme&return_to=' . urlencode('/')
        ));

        self::assertStringContainsString('reporion_theme=dark', $response->headers['Set-Cookie']);
    }

    public function testMissingReturnToRedirectsHome(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('POST', '/theme', body: 'theme=light'));

        self::assertSame('/', $response->headers['Location']);
    }

    /**
     * A return_to starting with "//" is parsed by browsers as
     * protocol-relative — the open-redirect shape this guards against.
     */
    public function testProtocolRelativeReturnToIsRejected(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/theme',
            body: 'theme=light&return_to=' . urlencode('//evil.example.com/phish')
        ));

        self::assertSame('/', $response->headers['Location']);
    }

    public function testAnonymousCanSetTheme(): void
    {
        // No principal required — a display preference isn't gated on
        // being signed in, even though only signed-in chrome has the
        // toggle at all today (templates/rail.php).
        $response = Kernel::boot($this->config)->handle(new Request('POST', '/theme', body: 'theme=light'));

        self::assertSame(302, $response->status);
    }

    public function testPageViewReflectsTheThemeCookie(): void
    {
        $this->createOwner();
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');
        $cookie = (new Session(
            (string) $this->config['auth']['session_secret'],
            (string) $this->config['auth']['session_name'],
            (int) $this->config['auth']['session_lifetime'],
            new FlatFileUserStore($this->dataRoot),
        ))->issue('owner');

        $dark = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a',
            cookies: ['reporion' => $cookie]
        ));
        $light = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a',
            cookies: ['reporion' => $cookie, 'reporion_theme' => 'light']
        ));

        self::assertStringContainsString('<body class="wk wk-shell">', $dark->body);
        self::assertStringContainsString('<body class="wk wk-shell theme-light">', $light->body);
    }
}
