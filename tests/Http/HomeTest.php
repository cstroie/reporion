<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Http\Request;
use Reporion\Kernel;

/**
 * GET / (docs/architecture-api.md §6): anonymous gets site:home (or a
 * built-in stub if it does not exist), and the public layout — not the
 * owner one — is what a page-view route uses for an anonymous caller (A4).
 */
final class HomeTest extends HttpTestCase
{
    public function testRendersSiteHomeForAnonymousInThePublicLayout(): void
    {
        $this->createPage('site:home', 'public', 'Welcome', "## Bine ati venit\n\nText.\n");

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Welcome', $response->body);
        // The public layout, unlike page-view.php, carries no data-path/
        // data-rev attributes and no visibility/status chrome.
        self::assertStringNotContainsString('data-path=', $response->body);
        self::assertStringNotContainsString('data-rev=', $response->body);
    }

    public function testFallsBackToABuiltInStubWhenSiteHomeDoesNotExist(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('GET', '/'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('exists yet', $response->body);
    }

    /**
     * pageAccessClause() (what findByPath() enforces) allows unlisted for
     * anonymous, because it assumes the caller already has the exact path.
     * "/" is the landing page, not knowledge of site:home's path — an
     * unlisted site:home must NOT become the public landing page.
     */
    public function testUnlistedSiteHomeIsNotShownToAnonymousEitherByStayingOnTheStub(): void
    {
        $this->createPage('site:home', 'unlisted', 'Unlisted Title', 'Text.');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/'));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('Unlisted Title', $response->body);
    }

    public function testPrivateSiteHomeIsNotShownToAnonymousEitherByStayingOnTheStub(): void
    {
        $this->createPage('site:home', 'private', 'Secret Dashboard Title', 'Text.');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/'));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('Secret Dashboard Title', $response->body);
    }
}
