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
 * GET/POST /new, end to end through the real Kernel (Controller\NewPageController)
 * — the create half of the write UI; tests/Http/EditorTest.php covers the
 * edit half.
 */
final class NewPageTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
    }

    public function testOwnerSeesTheScaffoldForm(): void
    {
        $response = $this->ownerRequest('GET', '/new');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('visibility: private', $response->body);
    }

    public function testNsQueryParamPrefillsThePathField(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/new',
            query: ['ns' => 'reports:mri'],
            cookies: ['reporion' => $this->issueCookie('owner')],
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('value="reports:mri:"', $response->body);
    }

    public function testAnonymousCannotSeeTheForm(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('GET', '/new'));

        self::assertSame(404, $response->status);
    }

    public function testViewerWithGrantCannotSeeTheForm(): void
    {
        $this->createViewer('ana', 'reports:mri');

        $response = $this->authenticatedGet('ana', '/new');

        self::assertSame(404, $response->status);
    }

    public function testCreatingAPageRedirectsToTheNewPath(): void
    {
        $document = "---\ntitle: v1\nvisibility: private\n---\n\nfirst body\n";

        $response = $this->ownerSubmit(['path' => 'reports:mri:mioveni:a', 'document' => $document]);

        self::assertSame(302, $response->status);
        self::assertSame('/reports:mri:mioveni:a', $response->headers['Location']);

        $followUp = $this->ownerRequest('GET', '/reports:mri:mioveni:a');
        self::assertStringContainsString('first body', $followUp->body);
    }

    /**
     * The one behavior that would silently 404 a user if gotten wrong:
     * Storage::create() appends -2/-3 on a path collision and returns the
     * path it actually used — the redirect must follow that, never the
     * submitted path.
     */
    public function testCreatingAPageThatCollidesRedirectsToTheAllocatedPathNotTheSubmittedOne(): void
    {
        $document = "---\ntitle: v1\nvisibility: private\n---\n\nfirst\n";
        $this->ownerSubmit(['path' => 'reports:mri:mioveni:a', 'document' => $document]);

        $response = $this->ownerSubmit(['path' => 'reports:mri:mioveni:a', 'document' => "---\ntitle: v2\nvisibility: private\n---\n\nsecond\n"]);

        self::assertSame(302, $response->status);
        self::assertSame('/reports:mri:mioveni:a-2', $response->headers['Location']);
    }

    public function testEditorWithGrantCanCreateInTheirNamespace(): void
    {
        $this->createEditor('mihai', 'reports:mri');
        $document = "---\ntitle: v1\nvisibility: private\n---\n\nbody\n";

        $response = $this->authenticatedSubmit('mihai', ['path' => 'reports:mri:mioveni:a', 'document' => $document]);

        self::assertSame(302, $response->status);
    }

    public function testEditorWithoutGrantCannotCreateInAnUnrelatedNamespace(): void
    {
        $this->createEditor('mihai', 'reports:ct');
        $document = "---\ntitle: v1\nvisibility: private\n---\n\nbody\n";

        $response = $this->authenticatedSubmit('mihai', ['path' => 'reports:mri:mioveni:a', 'document' => $document]);

        self::assertSame(404, $response->status);
    }

    public function testViewerWithGrantCannotCreate(): void
    {
        $this->createViewer('ana', 'reports:mri');
        $document = "---\ntitle: v1\nvisibility: private\n---\n\nbody\n";

        $response = $this->authenticatedSubmit('ana', ['path' => 'reports:mri:mioveni:a', 'document' => $document]);

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCannotCreate(): void
    {
        $document = "---\ntitle: v1\nvisibility: private\n---\n\nbody\n";

        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/new',
            body: http_build_query(['path' => 'reports:mri:mioveni:a', 'document' => $document])
        ));

        self::assertSame(404, $response->status);
    }

    public function testMissingPathReRendersWithAnErrorAndKeepsTheTypedDocument(): void
    {
        $document = "---\ntitle: my draft\nvisibility: private\n---\n\nmy typed body\n";

        $response = $this->ownerSubmit(['path' => '', 'document' => $document]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('my typed body', $response->body, 'the typed document must not be lost');
    }

    public function testMalformedDocumentReRendersWithAnError(): void
    {
        $response = $this->ownerSubmit(['path' => 'reports:mri:mioveni:a', 'document' => 'no frontmatter at all']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('no frontmatter at all', $response->body);
    }

    public function testNewLinkAppearsOnThePageViewForACallerWithAnyWriteAccess(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'v1', 'body');

        $response = $this->ownerRequest('GET', '/reports:mri:mioveni:a');

        self::assertStringContainsString('href="/new"', $response->body);
    }

    public function testNewLinkIsAbsentForAViewer(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'v1', 'body');
        $this->createViewer('ana', 'reports:mri');

        $response = $this->authenticatedGet('ana', '/reports:mri:mioveni:a');

        self::assertStringNotContainsString('href="/new"', $response->body);
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

    private function ownerRequest(string $method, string $path): Response
    {
        return $this->authenticatedGet('owner', $path, $method);
    }

    private function authenticatedGet(string $username, string $path, string $method = 'GET'): Response
    {
        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $this->issueCookie($username)]));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function ownerSubmit(array $fields): Response
    {
        return $this->authenticatedSubmit('owner', $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function authenticatedSubmit(string $username, array $fields): Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/new',
            cookies: ['reporion' => $this->issueCookie($username)],
            body: http_build_query($fields),
        ));
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
