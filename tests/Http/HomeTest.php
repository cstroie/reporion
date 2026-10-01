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

    public function testSignedInUsersGetTheStartPage(): void
    {
        $this->createOwner();
        $this->createPage('reports:mri:mioveni:260101-test-a', 'private', 'Exam A', 'body');
        $this->createPage('templates:mri:cerebral', 'private', 'Cerebral', 'body');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/', cookies: ['reporion' => $this->cookieFor('owner')]));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('class="wk-doc wk-start"', $response->body);
        // The report waits for a signature, with its Sign link
        self::assertMatchesRegularExpression('#<section class="wk-panel" id="drafts">.*Exam A.*href="/reports:mri:mioveni:260101-test-a/sign"#s', $response->body);
        self::assertStringContainsString('<b>1</b><span>' . t('start.stat_drafts'), $response->body);
        self::assertStringContainsString('href="/?all=1"', $response->body);
    }

    public function testTheBrandTogglesBetweenTheStartPageAndTheSiteHomePage(): void
    {
        $this->createOwner();
        $this->createPage('site:home', 'public', 'Welcome', 'Hello.');
        $get = fn (string $path, array $query = []): string => Kernel::boot($this->config)->handle(new Request('GET', $path, query: $query, cookies: ['reporion' => $this->cookieFor('owner')]))->body;

        self::assertStringContainsString('<a class="wk-brand" href="/site:home"', $get('/'), 'start page → home page');
        self::assertStringContainsString('<a class="wk-brand" href="/"', $get('/site:home'), 'home page → start page');
        self::assertStringContainsString('<a class="wk-brand" href="/"', $get('/', ['all' => '1']), 'every other screen → start page');
    }

    public function testTheLastReportDrivesTheActionsAndTheQuickLinks(): void
    {
        $this->createOwner();
        $this->createPage('templates:mri:cerebral', 'private', 'Cerebral', 'body');
        sleep(1); // edit times have one-second resolution: the report must be the newer
        $this->createPage('reports:mri:mioveni:260101-test-a', 'private', 'Exam A', 'body');

        $body = Kernel::boot($this->config)->handle(new Request('GET', '/', cookies: ['reporion' => $this->cookieFor('owner')]))->body;

        preg_match('#<section class="wk-panel wk-start-continue">.*?</section>#s', $body, $continue);
        self::assertStringContainsString('Exam A', $continue[0] ?? '');
        self::assertStringContainsString('href="/reports:mri:mioveni:260101-test-a/sign"', $continue[0] ?? '');
        self::assertStringContainsString('href="/reports:mri:mioveni:260101-test-a/timeline"', $continue[0] ?? '');
        self::assertStringContainsString('href="/new?ns=reports%3Amri%3Amioveni"', $body);
        self::assertStringContainsString('href="/new?after=', $body);
        self::assertMatchesRegularExpression('#class="wk-start-links".*href="/templates:mri:"#s', $body, 'the modality\'s templates');
    }

    public function testTheTeamListStillShowsOthersWhenTheCallerWasBusy(): void
    {
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        $storage = new FlatFile($this->dataRoot, $index);
        $storage->create('reports:mri:mioveni:260101-test-a', ['title' => 'Mihai report', 'visibility' => 'private'], 'body', 'mihai');
        sleep(1);
        for ($i = 0; $i < 45; $i++) {
            $storage->create('docs:note-' . $i, ['title' => 'Owner note ' . $i, 'visibility' => 'private'], 'body', 'owner');
        }

        $start = Kernel::boot($this->config)->handle(new Request('GET', '/', cookies: ['reporion' => $this->cookieFor('owner')]))->body;

        preg_match('#<section class="wk-panel">\s*<header class="wk-panel-h"><h2 class="wk-eyebrow">' . preg_quote(t('start.team'), '#') . '.*?</section>#s', $start, $team);
        self::assertStringContainsString('Mihai report', $team[0] ?? '');
    }

    /**
     * strrpos() returns false for a path with no colon (a top-level page:
     * "reports", "templates", "ai"); (int) false is 0, so a bare "+ 1" on
     * it silently became substr($path, 1) and dropped the first character
     * ("reports" showed as "eports") instead of leaving the path alone.
     */
    public function testATopLevelPageWithNoColonKeepsItsFirstCharacter(): void
    {
        $this->createOwner();
        $this->createPage('reports', 'private', 'Reports', 'radiology reports');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/', query: ['all' => '1'], cookies: ['reporion' => $this->cookieFor('owner')]));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<td><a href="/reports">Reports</a></td>', $response->body);
        self::assertStringContainsString('href="/?all=1&amp;mod=MR"', $response->body);
    }

    public function testTheDashboardListsOnlyWhatTheCallerCanSee(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Visible MRI', 'body');
        $this->createPage('reports:ct:mioveni:b', 'private', 'Hidden CT', 'body');
        (new FlatFileUserStore($this->dataRoot))->create('ana', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)]);

        $start = Kernel::boot($this->config)->handle(new Request('GET', '/', cookies: ['reporion' => $this->cookieFor('ana')]));
        $all = Kernel::boot($this->config)->handle(new Request('GET', '/', query: ['all' => '1'], cookies: ['reporion' => $this->cookieFor('ana')]));

        self::assertStringContainsString('Visible MRI', $start->body, 'the team\'s week');
        self::assertStringNotContainsString('Hidden CT', $start->body);
        self::assertStringContainsString('Visible MRI', $all->body);
        self::assertStringNotContainsString('Hidden CT', $all->body);
    }

    public function testMyListsAreMineAndTheTeamListIsEveryoneElse(): void
    {
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $this->createPage('reports:mri:mioveni:260101-test-a', 'private', 'Owner draft', 'body');
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create('reports:mri:mioveni:260102-test-b', ['title' => 'Mihai draft', 'visibility' => 'private'], 'body', 'mihai');

        $start = Kernel::boot($this->config)->handle(new Request('GET', '/', cookies: ['reporion' => $this->cookieFor('mihai')]))->body;
        $mine = Kernel::boot($this->config)->handle(new Request('GET', '/', query: ['mine' => '1'], cookies: ['reporion' => $this->cookieFor('mihai')]))->body;

        preg_match('#<section class="wk-panel" id="drafts">.*?</section>#s', $start, $drafts);
        preg_match('#<section class="wk-panel" id="mine">.*?</section>#s', $start, $own);
        self::assertStringContainsString('Mihai draft', $drafts[0] ?? '');
        self::assertStringNotContainsString('Owner draft', $drafts[0] ?? '', 'only the caller\'s own drafts');
        self::assertStringNotContainsString('Owner draft', $own[0] ?? '');
        preg_match('#<section class="wk-panel">\s*<header class="wk-panel-h"><h2 class="wk-eyebrow">' . preg_quote(t('start.team'), '#') . '.*?</section>#s', $start, $team);
        self::assertStringContainsString('Owner draft', $team[0] ?? '', 'the team\'s week');
        self::assertStringNotContainsString('Mihai draft', $team[0] ?? '');
        self::assertStringNotContainsString('Owner draft', $mine);
    }

    private function cookieFor(string $username): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);
    }
}
