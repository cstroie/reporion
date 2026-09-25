<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * GET /{ns}: end to end through the real Kernel (Controller\NamespaceController).
 */
final class NamespaceIndexTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
    }

    public function testOwnerSeesSubnamespacesAndPages(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body a');
        $this->createPage('reports:mri:campulung:b', 'private', 'Exam B', 'body b');

        $response = $this->ownerRequest('/reports:mri:');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('mioveni', $response->body);
        self::assertStringContainsString('campulung', $response->body);
    }

    /**
     * GET /: — the root namespace ($ns === ''): every top-level namespace
     * in the tree is one of its "sub-namespaces" (Index\Sqlite::
     * listSubnamespaces() special-cases $ns === '').
     */
    public function testRootShowsLevelOneNamespaces(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body a');
        $this->createPage('templates:mri:default', 'private', 'Template', 'body b');

        $response = $this->ownerRequest('/:');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('>reports<', $response->body);
        self::assertStringContainsString('>templates<', $response->body);
    }

    /**
     * A page with no namespace of its own (ns === '') is top-level, not a
     * sub-namespace of the root — it must show up in the root's own pages
     * table, never as a card.
     */
    public function testRootListsTopLevelPagesDirectly(): void
    {
        $this->createPage('home', 'private', 'Home', 'body');

        $response = $this->ownerRequest('/:');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('>home<', $response->body);
    }

    public function testOwnerSeesDirectChildPages(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body a');

        $response = $this->ownerRequest('/reports:mri:mioveni:');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('reports:mri:mioveni:a', $response->body);
        self::assertStringContainsString('Exam A', $response->body);
    }

    /**
     * WikiNsIndex mockup columns: page, title, region, status, updated, by.
     * region lives in the page_regions child table (D29); "by" is the
     * updated_by column that listNamespace() already selected but the
     * template never rendered.
     */
    public function testPagesTableShowsRegionAndUpdatedBy(): void
    {
        $this->createPageWithRegion('reports:mri:mioveni:a', 'private', 'Exam A', 'body a', ['neuro'], 'barbu');

        $response = $this->ownerRequest('/reports:mri:mioveni:');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('neuro', $response->body);
        self::assertStringContainsString('barbu', $response->body);
    }

    private function createPageWithRegion(string $path, string $visibility, string $title, string $body, array $region, string $author): void
    {
        $index = new \Reporion\Index\Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new \Reporion\Storage\FlatFile($this->dataRoot, $index))->create(
            $path,
            ['title' => $title, 'visibility' => $visibility, 'region' => $region],
            $body,
            $author
        );
    }

    public function testEmptyNamespaceForThisCaller404s(): void
    {
        $response = $this->ownerRequest('/reports:mri:');

        self::assertSame(404, $response->status);
    }

    public function testAnonymousOnlySeesPublicPages(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'Public exam', 'body');
        $this->createPage('reports:mri:mioveni:b', 'private', 'Private exam', 'body');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Public exam', $response->body);
        self::assertStringNotContainsString('Private exam', $response->body);
    }

    public function testAnonymousGetsNotFoundWhenNothingIsPublicHere(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Private exam', 'body');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:'));

        self::assertSame(404, $response->status);
    }

    /**
     * The leak-prevention requirement carried over from
     * Index\Sqlite::listSubnamespaces() itself: an editor granted only on
     * reports:mri must never see reports:ct mentioned anywhere on this page,
     * even indirectly via a sub-namespace card.
     */
    public function testEditorGrantedOnlyOnMriNeverSeesCtMentioned(): void
    {
        $this->createEditor('mihai', 'reports:mri');
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');
        $this->createPage('reports:ct:mioveni:b', 'private', 'Exam B', 'body');

        $response = $this->authenticatedGet('mihai', '/reports:');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('>mri<', $response->body);
        self::assertStringNotContainsString('reports:ct', $response->body);
        self::assertStringNotContainsString('>ct<', $response->body);
    }

    public function testViewerWithGrantCanSeeButNotCreate(): void
    {
        $this->createViewer('ana', 'reports:mri');
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');

        $response = $this->authenticatedGet('ana', '/reports:mri:mioveni:');

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('/new?ns=', $response->body);
    }

    public function testNewPageLinkPrefillsThePathFromTheNamespace(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');

        $response = $this->ownerRequest('/reports:mri:mioveni:');

        self::assertStringContainsString('/new?ns=reports%3Amri%3Amioveni', $response->body);
    }

    private function createEditor(string $username, string $namespace): void
    {
        (new FlatFileUserStore($this->dataRoot))->create(
            $username,
            password_hash('x', PASSWORD_ARGON2ID),
            false,
            [new Grant($namespace, GrantRole::Editor)]
        );
    }

    private function createViewer(string $username, string $namespace): void
    {
        (new FlatFileUserStore($this->dataRoot))->create(
            $username,
            password_hash('x', PASSWORD_ARGON2ID),
            false,
            [new Grant($namespace, GrantRole::Viewer)]
        );
    }

    private function ownerRequest(string $path): Response
    {
        return $this->authenticatedGet('owner', $path);
    }

    private function authenticatedGet(string $username, string $path): Response
    {
        return Kernel::boot($this->config)->handle(new Request('GET', $path, cookies: ['reporion' => $this->issueCookie($username)]));
    }

    private function issueCookie(string $username): string
    {
        return (new Session(
            (string) $this->config['auth']['session_secret'],
            (string) $this->config['auth']['session_name'],
            (int) $this->config['auth']['session_lifetime'],
            new FlatFileUserStore($this->dataRoot),
        ))->issue($username);
    }
}
