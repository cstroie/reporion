<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

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

    public function testSignedInUsersGetTheWorklistDashboard(): void
    {
        $this->createOwner();
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/', cookies: ['reporion' => $this->cookieFor('owner')]));

        self::assertSame(200, $response->status);
        self::assertStringContainsString(t('dash.title'), $response->body);
        self::assertStringContainsString('Exam A', $response->body);
        self::assertStringContainsString('href="/?mod=MR"', $response->body);
    }

    public function testTheDashboardListsOnlyWhatTheCallerCanSee(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Visible MRI', 'body');
        $this->createPage('reports:ct:mioveni:b', 'private', 'Hidden CT', 'body');
        (new FlatFileUserStore($this->dataRoot))->create('ana', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)]);

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/', cookies: ['reporion' => $this->cookieFor('ana')]));

        self::assertStringContainsString('Visible MRI', $response->body);
        self::assertStringNotContainsString('Hidden CT', $response->body);
    }

    public function testMyDraftsAndTheMineFilterShowOnlyTheCallersOwnPages(): void
    {
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $this->createPage('reports:mri:mioveni:a', 'private', 'Owner draft', 'body');
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create('reports:mri:mioveni:b', ['title' => 'Mihai draft', 'visibility' => 'private'], 'body', 'mihai');

        $all = Kernel::boot($this->config)->handle(new Request('GET', '/', cookies: ['reporion' => $this->cookieFor('mihai')]));
        $mine = Kernel::boot($this->config)->handle(new Request('GET', '/', query: ['mine' => '1'], cookies: ['reporion' => $this->cookieFor('mihai')]));

        preg_match('/<div class="wk-panel">.*?<\/div>\s*<\/div>/s', $all->body, $panel);
        self::assertStringContainsString('Mihai draft', $panel[0] ?? '');
        self::assertStringNotContainsString('Owner draft', $panel[0] ?? '', 'My drafts is only the caller\'s own');
        self::assertStringContainsString('Owner draft', $all->body, 'the worklist itself shows every visible page');
        self::assertStringNotContainsString('Owner draft', $mine->body);
    }

    private function cookieFor(string $username): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);
    }
}
