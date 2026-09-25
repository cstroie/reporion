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
 * A6), and — because the live instance is served under a sub-path — every
 * link, script and form in it carries the request's basePath.
 */
final class ShellTest extends HttpTestCase
{
    public function testEveryScreenIsInTheShellWithSubPathSafeLinks(): void
    {
        $this->createOwner();
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', "## Descriere\n\nText.");
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        $screens = [
            '/reports:mri:mioveni:a' => true,
            '/reports:mri:mioveni:a/edit' => true,
            '/reports:mri:mioveni:a/history' => true,
            '/reports:mri:mioveni:a/compare' => true,
            '/reports:mri:mioveni:a/timeline' => true,
            '/reports:mri:mioveni:a/delete' => true,
            '/reports:mri:' => false,
            '/:' => false,
            '/search' => false,
            '/new' => false,
            '/admin/users' => false,
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
        self::assertStringNotContainsString('href="/new"', $response->body);
        self::assertStringNotContainsString('href="/admin/users"', $response->body);
    }
}
