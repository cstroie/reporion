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

        self::assertStringContainsString('<body class="wk wk-read">', $dark->body);
        self::assertStringContainsString('<body class="wk wk-read theme-light">', $light->body);
    }

    public function testSettingAPaletteSetsTheCookieAndRedirectsToReturnTo(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/palette',
            body: 'palette=lime&return_to=' . urlencode('/reports:mri:mioveni:a')
        ));

        self::assertSame(302, $response->status);
        self::assertSame('/reports:mri:mioveni:a', $response->headers['Location']);
        self::assertStringContainsString('reporion_palette=lime', $response->headers['Set-Cookie']);
    }

    public function testAPaletteOutsideTheAllowlistIsRoyalBlue(): void
    {
        foreach (['teal', 'rose', '../x', 'lime; Path=/evil', ''] as $value) {
            $response = Kernel::boot($this->config)->handle(new Request(
                'POST',
                '/palette',
                body: http_build_query(['palette' => $value, 'return_to' => '/'])
            ));

            self::assertStringStartsWith('reporion_palette=royal-blue;', $response->headers['Set-Cookie'], $value);
        }
    }

    public function testPaletteProtocolRelativeReturnToIsRejected(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/palette',
            body: 'palette=amber&return_to=' . urlencode('//evil.example.com/phish')
        ));

        self::assertSame('/', $response->headers['Location']);
    }

    public function testPageViewReflectsThePaletteCookie(): void
    {
        $this->createOwner();
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');
        $cookie = (new Session(
            (string) $this->config['auth']['session_secret'],
            (string) $this->config['auth']['session_name'],
            (int) $this->config['auth']['session_lifetime'],
            new FlatFileUserStore($this->dataRoot),
        ))->issue('owner');

        $amberLight = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a',
            cookies: ['reporion' => $cookie, 'reporion_theme' => 'light', 'reporion_palette' => 'amber']
        ));
        $bogus = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a',
            cookies: ['reporion' => $cookie, 'reporion_palette' => '"><script>']
        ));

        preg_match('/<body class="([^"]*)"/', $amberLight->body, $amberBody);
        preg_match('/<body class="([^"]*)"/', $bogus->body, $bogusBody);
        self::assertStringEndsWith('theme-light palette-amber', $amberBody[1] ?? '');
        self::assertStringNotContainsString('palette-', $bogusBody[1] ?? '');
        self::assertStringNotContainsString('<script>"', $bogus->body);
    }
}
