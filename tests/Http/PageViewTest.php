<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Http\Request;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * End to end through real objects — Kernel::boot() → Router → PageController
 * → Storage\FlatFile + Index\Sqlite + Service\Render — for the one route
 * this build-order step ships: GET /{path}. This is where the visibility
 * matrix (tests/Visibility) actually meets an HTTP request.
 */
final class PageViewTest extends HttpTestCase
{
    public function testPublicPageRendersForAnonymousVisitor(): void
    {
        $this->createPage('reports:mri:mioveni:public-x', 'public', 'Titlu Public', "## Concluzie\n\nText liber.\n");

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:public-x'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Titlu Public', $response->body);
        self::assertStringContainsString('<h2', $response->body);
        self::assertStringContainsString('Concluzie', $response->body);
    }

    public function testPrivatePageIs404ForAnonymousVisitor(): void
    {
        $this->createPage('reports:mri:mioveni:private-x', 'private', 'Titlu Privat', 'Text.');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:private-x'));

        self::assertSame(404, $response->status);
        self::assertStringNotContainsString('Titlu Privat', $response->body);
    }

    public function testPrivatePageRendersForOwner(): void
    {
        $this->createPage('reports:mri:mioveni:private-y', 'private', 'Titlu Privat', 'Text.');

        $session = new Session('test-secret', 'reporion', 3600);
        $ownerCookie = $session->issue();

        $response = Kernel::boot($this->config)->handle(
            new Request('GET', '/reports:mri:mioveni:private-y', cookies: ['reporion' => $ownerCookie])
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Titlu Privat', $response->body);
    }

    public function testUnlistedPageIsReachableByDirectPathForAnonymous(): void
    {
        $this->createPage('reports:mri:mioveni:unlisted-x', 'unlisted', 'Titlu Nelistat', 'Text.');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:unlisted-x'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Titlu Nelistat', $response->body);
    }

    public function testUnknownPathIs404(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:does-not-exist'));

        self::assertSame(404, $response->status);
    }
}
