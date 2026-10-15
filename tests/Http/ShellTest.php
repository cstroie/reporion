<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * Every signed-in screen renders inside the one shell (templates/layout.php,
 * A6), and, because the live instance is served under a sub-path: every
 * link, script and form in it carries the request's basePath.
 */
final class ShellTest extends HttpTestCase
{
    public function testEveryScreenIsInTheShellWithSubPathSafeLinks(): void
    {
        $this->createOwner();
        $this->createPage('reports:mri:mioveni:260101-test-a', 'private', 'Exam A', "## Descriere\n\nText.");
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        $screens = [
            '/reports:mri:mioveni:260101-test-a' => true,
            '/reports:mri:mioveni:260101-test-a/edit' => false, // full-bleed editor: its own crumbs line, no page header
            '/reports:mri:mioveni:260101-test-a/revisions' => true,
            '/reports:mri:mioveni:260101-test-a/timeline' => true,
            '/reports:mri:mioveni:260101-test-a/delete' => true,
            '/reports:mri:mioveni:260101-test-a/move' => true,
            '/reports:mri:' => false,
            '/:' => false,
            '/search' => false,
            '/new' => false,
            '/admin/users' => false,
            '/admin/index' => false,
            '/admin/trash' => false,
            '/admin/tags' => false,
            '/admin/maintenance' => false,
            '/admin/settings' => false,
            '/profile' => false,
            '/' => false,
        ];
        foreach ($screens as $route => $hasPageHeader) {
            $response = Kernel::boot($this->config)->handle(new Request(
                'GET',
                $route,
                cookies: ['reporion' => $cookie],
                basePath: '/reporion',
            ));

            self::assertSame(200, $response->status, $route);
            self::assertSame(1, substr_count($response->body, '<header class="wk-top wk-topnav">'), "{$route}: one top nav");
            self::assertSame($hasPageHeader, str_contains($response->body, 'wk-pagehead'), "{$route}: page header");
            self::assertStringNotContainsString('wk-irail', $response->body, "{$route}: no Workbench rail");

            preg_match_all('/\b(?:href|src|action)="([^"]*)"/', $response->body, $m);
            foreach ($m[1] as $url) {
                if (!str_starts_with($url, '#')) {
                    self::assertStringStartsWith('/reporion/', $url, "{$route}: unprefixed {$url}");
                }
            }
        }
    }

    public function testAnonymousOnAPublicListingGetsSignInNotTheAccountMenu(): void
    {
        $this->createPage('docs:guide', 'public', 'Guide', 'Text.');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/docs:'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('href="/login"', $response->body);
        self::assertStringNotContainsString('action="/logout"', $response->body);
        self::assertStringNotContainsString('href="/new?', $response->body);
        self::assertStringNotContainsString('href="/admin/users"', $response->body);
    }

    /**
     * TODO 13: the account menu shows the signed-in account's name and
     * title directly, not "Signed in as {username}".
     */
    public function testAccountMenuShowsNameAndTitleNotBareUsername(): void
    {
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->save(
            (new FlatFileUserStore($this->dataRoot))->find('owner')->with(displayName: 'Dr. Ana Popescu', title: 'Medic primar radiologie')
        );
        $this->createPage('docs:guide', 'private', 'Guide', 'Text.');
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/docs:guide', cookies: ['reporion' => $cookie]));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<b>Dr. Ana Popescu</b>', $response->body);
        self::assertStringContainsString('<span class="wk-dim">Medic primar radiologie</span>', $response->body);
        self::assertStringNotContainsString('Signed in as', $response->body);
    }
    /**
     * The top nav's ↑ (2026-10-15): from a page its own namespace, from a
     * namespace index the parent, from a top-level namespace the root, and
     * inert where nothing is above (the root index, a screen with no
     * namespace). No ☰ drawer and no 📂 button any more.
     */
    public function testUpGoesOneNamespaceUp(): void
    {
        $this->createOwner();
        $this->createPage('reports:mri:mioveni:260101-test-a', 'private', 'Exam A', "## Descriere\n\nText.");
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        $expected = [
            '/reports:mri:mioveni:260101-test-a' => '/reports:mri:mioveni:',
            '/reports:mri:mioveni:260101-test-a/revisions' => '/reports:mri:mioveni:',
            '/reports:mri:mioveni:' => '/reports:mri:',
            '/reports:' => '/:',
            '/:' => null,
            '/profile' => null,
        ];
        foreach ($expected as $route => $up) {
            $response = Kernel::boot($this->config)->handle(new Request('GET', $route, cookies: ['reporion' => $cookie]));

            self::assertSame(200, $response->status, $route);
            self::assertSame(1, preg_match('/<(a|span) class="wk-tbtn"([^>]*)data-nav-up>/', $response->body, $m), "{$route}: one ↑");
            if ($up === null) {
                self::assertSame('span', $m[1], "{$route}: ↑ inert");
                self::assertStringContainsString('aria-disabled="true"', $m[2], $route);
            } else {
                self::assertSame('a', $m[1], "{$route}: ↑ is a link");
                self::assertStringContainsString('href="' . $up . '"', $m[2], $route);
            }
            self::assertStringNotContainsString('data-drawer-open', $response->body, $route);
            self::assertStringNotContainsString('ph-folder-open', $response->body, $route);
        }
    }

    /** Admin is an item of the account menu, for an owner only, not a top-nav button */
    public function testAdminSitsInTheAccountMenu(): void
    {
        $this->createOwner();
        $this->createPage('docs:guide', 'private', 'Guide', 'Text.');
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/docs:guide', cookies: ['reporion' => $cookie]));

        self::assertSame(1, preg_match('/<summary class="wk-who".*?<\/details>/s', $response->body, $menu));
        self::assertStringContainsString('<a class="wk-mi" href="/admin/users">', $menu[0]);
        self::assertStringNotContainsString('<a class="wk-tbtn" href="/admin/users"', $response->body);
    }
}
