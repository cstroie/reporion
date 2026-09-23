<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
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

    public function testCrumbsLinkToEachAncestorNamespaceIndex(): void
    {
        // Uses an authenticated view, not the anonymous public layout — the
        // public layout (layout-public.php) is a separate, chromeless
        // template with no crumbs at all; the crumb trail only exists in
        // page-view.php, which anonymous visitors never see (invariant 9).
        $this->createPage('reports:mri:mioveni:private-x', 'private', 'Titlu Privat', 'Text.');
        $this->createOwner();

        $session = new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot));
        $ownerCookie = $session->issue('owner');

        $response = Kernel::boot($this->config)->handle(
            new Request('GET', '/reports:mri:mioveni:private-x', cookies: ['reporion' => $ownerCookie])
        );

        self::assertStringContainsString('href="/reports:"', $response->body);
        self::assertStringContainsString('href="/reports:mri:"', $response->body);
        self::assertStringContainsString('href="/reports:mri:mioveni:"', $response->body);
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
        $this->createOwner();

        $session = new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot));
        $ownerCookie = $session->issue('owner');

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

    /**
     * The bug this guards against: templates hardcoded absolute links,
     * which only resolve correctly when the app is mounted at the web
     * server's root — confirmed live under /reporion/ path-prefix mounting,
     * where the stylesheet, forms and page links all 404'd for a real
     * browser even though every curl/test check on the page's status code
     * kept passing.
     */
    public function testAssetLinksAreThreadedWithTheRequestsBasePath(): void
    {
        $this->createPage('reports:mri:mioveni:x', 'public', 'Titlu', 'Text.');

        $response = Kernel::boot($this->config)->handle(
            new Request('GET', '/reports:mri:mioveni:x', basePath: '/reporion')
        );

        self::assertStringContainsString('href="/reporion/assets/css/tokens.css"', $response->body);
    }
}
