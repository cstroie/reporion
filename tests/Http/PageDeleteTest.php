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
 * GET /{path}/delete (confirmation) and POST /{path}/delete (the actual
 * delete), end to end through the real Kernel (Controller\PageController)
 * — the one live item in the page-view kebab menu (templates/page-view.php).
 */
final class PageDeleteTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
    }

    public function testOwnerDeletesAndIsRedirectedToTheParentNamespace(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');

        $response = $this->ownerSubmit('/reports:mri:mioveni:a/delete');

        self::assertSame(302, $response->status);
        self::assertSame('/reports:mri:mioveni:', $response->headers['Location']);
    }

    public function testDeletedPageIsGoneFromTheIndexImmediately(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');
        $this->ownerSubmit('/reports:mri:mioveni:a/delete');

        $followUp = $this->ownerRequest('/reports:mri:mioveni:a');

        self::assertSame(404, $followUp->status);
    }

    public function testDeletedPageIsMovedToTrashNotErased(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body one two three');
        $this->ownerSubmit('/reports:mri:mioveni:a/delete');

        $trashRoot = $this->dataRoot . '/trash';
        self::assertDirectoryExists($trashRoot);
        $entries = array_values(array_diff(scandir($trashRoot) ?: [], ['.', '..']));
        self::assertNotEmpty($entries, 'the page directory must have been moved to trash, not erased');
    }

    public function testDeletingATopLevelPageWithNoNamespaceRedirectsHome(): void
    {
        $this->createPage('standalone', 'private', 'Standalone', 'body');

        $response = $this->ownerSubmit('/standalone/delete');

        self::assertSame(302, $response->status);
        self::assertSame('/', $response->headers['Location']);
    }

    public function testDeletingAnUnknownPathIs404(): void
    {
        $response = $this->ownerSubmit('/reports:mri:mioveni:nope/delete');

        self::assertSame(404, $response->status);
    }

    public function testEditorWithGrantCanDeleteInTheirNamespace(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');
        $this->createEditor('mihai', 'reports:mri');

        $response = $this->authenticatedSubmit('mihai', '/reports:mri:mioveni:a/delete');

        self::assertSame(302, $response->status);
    }

    public function testEditorWithoutGrantCannotDeleteInAnUnrelatedNamespace(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');
        $this->createEditor('mihai', 'reports:ct');

        $response = $this->authenticatedSubmit('mihai', '/reports:mri:mioveni:a/delete');

        self::assertSame(404, $response->status);
    }

    public function testViewerWithGrantCannotDelete(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');
        $this->createViewer('ana', 'reports:mri');

        $response = $this->authenticatedSubmit('ana', '/reports:mri:mioveni:a/delete');

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCannotDelete(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');

        $response = Kernel::boot($this->config)->handle(new Request('POST', '/reports:mri:mioveni:a/delete'));

        self::assertSame(404, $response->status);
    }

    public function testDeleteMenuAppearsForACallerWithWriteAccess(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');

        $response = $this->ownerRequest('/reports:mri:mioveni:a');

        self::assertStringContainsString('href="/reports:mri:mioveni:a/delete"', $response->body);
    }

    public function testDeleteMenuIsAbsentForAViewer(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');
        $this->createViewer('ana', 'reports:mri');

        $response = $this->authenticatedGet('ana', '/reports:mri:mioveni:a');

        self::assertStringNotContainsString('href="/reports:mri:mioveni:a/delete"', $response->body);
    }

    /**
     * GET /{path}/delete must be a confirmation, never the delete itself —
     * this is the whole reason it exists (docs/BUILD_LOG.md): no restore UI
     * elsewhere in the app, so a stray click reaching this route must not
     * remove anything.
     */
    public function testGetOnDeleteRouteShowsConfirmationAndDoesNotDelete(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');

        $response = $this->ownerRequest('/reports:mri:mioveni:a/delete');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Exam A', $response->body);
        self::assertStringContainsString('action="/reports:mri:mioveni:a/delete"', $response->body);

        $stillThere = $this->ownerRequest('/reports:mri:mioveni:a');
        self::assertSame(200, $stillThere->status, 'the GET confirmation page must not have deleted the page');
    }

    public function testGetOnDeleteRouteForAnUnknownPathIs404(): void
    {
        $response = $this->ownerRequest('/reports:mri:mioveni:nope/delete');

        self::assertSame(404, $response->status);
    }

    public function testGetOnDeleteRouteIs404ForACallerWithoutWriteAccess(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');
        $this->createViewer('ana', 'reports:mri');

        $response = $this->authenticatedGet('ana', '/reports:mri:mioveni:a/delete');

        self::assertSame(404, $response->status);
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

    private function ownerSubmit(string $path): Response
    {
        return $this->authenticatedSubmit('owner', $path);
    }

    private function authenticatedGet(string $username, string $path): Response
    {
        return Kernel::boot($this->config)->handle(new Request('GET', $path, cookies: ['reporion' => $this->issueCookie($username)]));
    }

    private function authenticatedSubmit(string $username, string $path): Response
    {
        return Kernel::boot($this->config)->handle(new Request('POST', $path, cookies: ['reporion' => $this->issueCookie($username)]));
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
