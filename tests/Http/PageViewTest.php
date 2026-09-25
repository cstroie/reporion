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

    /**
     * The Workbench icon rail (templates/rail.php): Admin is a permission
     * gate (hidden entirely for a non-owner), Tags/Integrations/Account are
     * unbuilt features (visible but .wk-ib-inert for everyone) — those are
     * different situations and this test pins the distinction down.
     */
    public function testTopNavShowsAdminForOwnerAndHidesItForAnEditor(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Titlu', 'Text.');
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create(
            'mihai',
            password_hash('x', PASSWORD_ARGON2ID),
            false,
            [new Grant('reports:mri', GrantRole::Editor)]
        );

        $ownerSession = new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot));
        $ownerResponse = Kernel::boot($this->config)->handle(
            new Request('GET', '/reports:mri:mioveni:a', cookies: ['reporion' => $ownerSession->issue('owner')])
        );
        $editorResponse = Kernel::boot($this->config)->handle(
            new Request('GET', '/reports:mri:mioveni:a', cookies: ['reporion' => $ownerSession->issue('mihai')])
        );

        self::assertStringContainsString('href="/admin/users"', $ownerResponse->body);
        self::assertStringNotContainsString('href="/admin/users"', $editorResponse->body);
    }



    /**
     * The ☰ drawer (templates/drawer.php) is a listing surface: it uses
     * Index\Sqlite::listWorklist() and listSubnamespaces(), which share the
     * one visibility predicate (invariant 6). Pinned at the HTTP layer, with
     * the currently-open page highlighted.
     */
    public function testDrawerShowsOnlyVisiblePagesAndHighlightsTheCurrentOne(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'Exam A', 'body a');
        $this->createPage('reports:mri:mioveni:b', 'private', 'Exam B', 'body b');
        (new FlatFileUserStore($this->dataRoot))->create(
            'ana',
            password_hash('x', PASSWORD_ARGON2ID),
            false,
            [new Grant('reports:ct', GrantRole::Editor)]
        );
        $session = new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot));

        $response = Kernel::boot($this->config)->handle(
            new Request('GET', '/reports:mri:mioveni:a', cookies: ['reporion' => $session->issue('ana')])
        );

        self::assertStringContainsString('wk-row wk-sel" href="/reports:mri:mioveni:a"', $response->body);
        self::assertStringContainsString('Exam A', $response->body);
        self::assertStringNotContainsString('Exam B', $response->body, 'ana has no grant on reports:mri and Exam B is private');
    }


    /**
     * A caller who cannot write here gets no Edit tab and no page actions
     * (⋯ menu: revert, delete) — the gate the old rail icon and tab strip
     * enforced, now on the page header (templates/page-header.php).
     */
    public function testViewerGetsNoEditTabAndNoPageActions(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Titlu', 'Text.');
        (new FlatFileUserStore($this->dataRoot))->create(
            'ana',
            password_hash('x', PASSWORD_ARGON2ID),
            false,
            [new Grant('reports:mri', GrantRole::Viewer)]
        );
        $session = new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot));

        $response = Kernel::boot($this->config)->handle(
            new Request('GET', '/reports:mri:mioveni:a', cookies: ['reporion' => $session->issue('ana')])
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('href="/reports:mri:mioveni:a/history"', $response->body);
        self::assertStringNotContainsString('href="/reports:mri:mioveni:a/edit"', $response->body);
        self::assertStringNotContainsString('href="/reports:mri:mioveni:a/delete"', $response->body);
        self::assertStringNotContainsString('href="/new"', $response->body);
    }

    public function testEditorGetsTheEditTabAndPageActions(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Titlu', 'Text.');
        (new FlatFileUserStore($this->dataRoot))->create(
            'mihai',
            password_hash('x', PASSWORD_ARGON2ID),
            false,
            [new Grant('reports:mri', GrantRole::Editor)]
        );
        $session = new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot));

        $response = Kernel::boot($this->config)->handle(
            new Request('GET', '/reports:mri:mioveni:a', cookies: ['reporion' => $session->issue('mihai')])
        );

        self::assertStringContainsString('class="wk-tab" data-on="1" aria-current="page" href="/reports:mri:mioveni:a"', $response->body);
        self::assertStringContainsString('href="/reports:mri:mioveni:a/edit"', $response->body);
        self::assertStringContainsString('href="/reports:mri:mioveni:a/delete"', $response->body);
    }

    public function testDrawerNeverListsASubnamespaceTheCallerCannotSee(): void
    {
        $this->createPage('reports:mri:pub', 'public', 'Public here', 'body');
        $this->createPage('reports:mri:mioveni:a', 'public', 'Public below', 'body');
        $this->createPage('reports:mri:secretsite:x', 'private', 'Private below', 'body');
        (new FlatFileUserStore($this->dataRoot))->create(
            'ana',
            password_hash('x', PASSWORD_ARGON2ID),
            false,
            [new Grant('reports:ct', GrantRole::Editor)]
        );
        $session = new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot));

        $response = Kernel::boot($this->config)->handle(
            new Request('GET', '/reports:mri:pub', cookies: ['reporion' => $session->issue('ana')])
        );

        self::assertStringContainsString('href="/reports:mri:mioveni:"', $response->body);
        self::assertStringNotContainsString('secretsite', $response->body);
    }

    /**
     * The live instance is served under a sub-path: every link and form in
     * the shell must carry the request's basePath, or it works in dev and
     * breaks in production.
     */
    public function testEveryShellLinkCarriesTheBasePath(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Titlu', 'Text.');
        $this->createOwner();
        $session = new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot));

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a',
            cookies: ['reporion' => $session->issue('owner')],
            basePath: '/reporion',
        ));

        self::assertSame(200, $response->status);
        preg_match_all('/\b(?:href|src|action)="([^"]*)"/', $response->body, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $url) {
            if (str_starts_with($url, '#')) {
                continue;
            }
            self::assertStringStartsWith('/reporion/', $url, "unprefixed link: {$url}");
        }
    }

    /**
     * Frontmatter is hand-edited YAML: `born: 1970` is an int and an
     * unquoted date becomes a Unix timestamp. Both used to 500 the page
     * (TypeError under strict_types) — and ship the half-rendered page,
     * patient block included, in front of the error text.
     */
    public function testNonStringFrontmatterValuesRenderInsteadOf500(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create('reports:mri:mioveni:typed', [
            'title' => 'Typed',
            'visibility' => 'private',
            'patient' => ['name' => 'TEST PATIENT', 'born' => 1970, 'sex' => 'F'],
            'study_date' => 1788220800,
            'modality' => 'MR',
            'priors' => [['path' => 'reports:mri:mioveni:older']],
            'tags' => ['a', 7],
        ], 'Body.', 'owner');
        $this->createOwner();
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        $response = Kernel::boot($this->config)->handle(
            new Request('GET', '/reports:mri:mioveni:typed', cookies: ['reporion' => $cookie])
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('TEST PATIENT · 1970 · F', $response->body);
        self::assertStringContainsString('01 Sep 2026', $response->body);
        self::assertStringContainsString('reports:mri:mioveni:older', $response->body);
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
