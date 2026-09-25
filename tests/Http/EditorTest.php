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
 * GET/POST /{path}/edit, end to end through the real Kernel
 * (Controller\EditorController) — the write UI gap this closes.
 */
final class EditorTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $this->createPage('reports:mri:mioveni:a', 'private', 'v1 title', 'v1 body');
    }

    public function testOwnerSeesTheRawDocumentInTheTextarea(): void
    {
        $response = $this->ownerRequest('GET', '/reports:mri:mioveni:a/edit');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('v1 title', $response->body);
        self::assertStringContainsString('v1 body', $response->body);
        self::assertStringContainsString('name="base_rev" value="1"', $response->body);
    }

    /**
     * The Edit tab must be lit ("Report" and "History & diff" must not),
     * and the old standalone Cancel link — redundant with the tab strip's
     * Report tab — must not appear in the tab strip (docs/BUILD_LOG.md).
     * Cancel is present in the save bar per the mockup.
     */
    public function testTabStripShowsEditActiveAndCancelLinkIsGone(): void
    {
        $response = $this->ownerRequest('GET', '/reports:mri:mioveni:a/edit');

        self::assertStringContainsString('wk-tab" data-on="1" href="/reports:mri:mioveni:a/edit"', $response->body);
        self::assertStringContainsString('wk-tab" data-on="" href="/reports:mri:mioveni:a"', $response->body);
        // Cancel lives in the save bar, not the tab strip — assert against the tab strip only.
        preg_match('/<div class="wk-dtabs">.*?<\/div>/s', $response->body, $m);
        self::assertStringNotContainsString(t('editor.cancel'), $m[0] ?? '');
    }

    public function testEditingAnUnknownPathIs404(): void
    {
        $response = $this->ownerRequest('GET', '/reports:mri:mioveni:does-not-exist/edit');

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCannotSeeTheEditForm(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:a/edit'));

        self::assertSame(404, $response->status);
    }

    public function testViewerWithGrantCannotSeeTheEditForm(): void
    {
        $this->createViewer('ana', 'reports:mri');

        $response = $this->authenticatedGet('ana', '/reports:mri:mioveni:a/edit');

        self::assertSame(404, $response->status);
    }

    public function testSavingANewDocumentWritesANewRevisionAndRedirects(): void
    {
        $document = "---\ntitle: v2\nvisibility: private\n---\n\nv2 body\n";

        $response = $this->ownerSubmit('/reports:mri:mioveni:a/edit', ['document' => $document, 'base_rev' => 1, 'note' => 'correction']);

        self::assertSame(302, $response->status);
        self::assertSame('/reports:mri:mioveni:a', $response->headers['Location']);

        $followUp = $this->ownerRequest('GET', '/reports:mri:mioveni:a');
        self::assertStringContainsString('v2 body', $followUp->body);
    }

    /**
     * Same CRLF bug as tests/Http/NewPageTest.php's — DocumentFormat::parse()
     * is shared by both controllers, so every real browser save through the
     * editor was hitting it too, not just page creation.
     * http_build_query() in the other tests here produces LF, which is why
     * the suite didn't catch this before — the raw body here keeps the CRLF.
     */
    public function testSavingWithCrlfLineEndingsFromABrowserSucceeds(): void
    {
        $document = "---\r\ntitle: v2\r\nvisibility: private\r\n---\r\n## v2";

        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/reports:mri:mioveni:a/edit',
            cookies: ['reporion' => $this->issueCookie('owner')],
            body: 'document=' . rawurlencode($document) . '&base_rev=1',
        ));

        self::assertSame(302, $response->status, 'expected a redirect, not the form re-rendered with an error');
        self::assertSame('/reports:mri:mioveni:a', $response->headers['Location']);
    }

    public function testEditorWithGrantCanSaveInTheirNamespace(): void
    {
        $this->createEditor('mihai', 'reports:mri');
        $document = "---\ntitle: v2\nvisibility: private\n---\n\nv2 body\n";

        $response = $this->authenticatedSubmit('mihai', '/reports:mri:mioveni:a/edit', ['document' => $document, 'base_rev' => 1]);

        self::assertSame(302, $response->status);
    }

    public function testEditorWithoutGrantCannotSave(): void
    {
        $this->createEditor('mihai', 'reports:ct');
        $document = "---\ntitle: v2\nvisibility: private\n---\n\nv2 body\n";

        $response = $this->authenticatedSubmit('mihai', '/reports:mri:mioveni:a/edit', ['document' => $document, 'base_rev' => 1]);

        self::assertSame(404, $response->status);
    }

    public function testViewerWithGrantCannotSave(): void
    {
        $this->createViewer('ana', 'reports:mri');
        $document = "---\ntitle: v2\nvisibility: private\n---\n\nv2 body\n";

        $response = $this->authenticatedSubmit('ana', '/reports:mri:mioveni:a/edit', ['document' => $document, 'base_rev' => 1]);

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCannotSave(): void
    {
        $document = "---\ntitle: v2\nvisibility: private\n---\n\nv2 body\n";

        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/reports:mri:mioveni:a/edit',
            body: http_build_query(['document' => $document, 'base_rev' => 1])
        ));

        self::assertSame(404, $response->status);

        // Anonymous denial must not have written anything either.
        $unchanged = $this->ownerRequest('GET', '/reports:mri:mioveni:a');
        self::assertStringContainsString('v1 body', $unchanged->body);
    }

    public function testMalformedDocumentReRendersWithAnErrorAndKeepsTheTypedText(): void
    {
        $response = $this->ownerSubmit('/reports:mri:mioveni:a/edit', ['document' => 'no frontmatter block at all', 'base_rev' => 1]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('no frontmatter block at all', $response->body, 'the invalid text must not be lost');

        $unchanged = $this->ownerRequest('GET', '/reports:mri:mioveni:a');
        self::assertStringContainsString('v1 body', $unchanged->body, 'a parse failure must not write anything');
    }

    public function testInvalidYamlFrontmatterReRendersWithAnErrorAndKeepsTheTypedText(): void
    {
        $document = "---\ntitle: [unterminated\n---\n\nbody\n";

        $response = $this->ownerSubmit('/reports:mri:mioveni:a/edit', ['document' => $document, 'base_rev' => 1]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('unterminated', $response->body);
    }

    public function testFrontmatterThatIsNotAMappingReRendersWithAnError(): void
    {
        $document = "---\n- just\n- a\n- list\n---\n\nbody\n";

        $response = $this->ownerSubmit('/reports:mri:mioveni:a/edit', ['document' => $document, 'base_rev' => 1]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('YAML mapping', $response->body);
    }

    /**
     * The no-JavaScript conflict path: the submitted text must not be
     * lost, and the server's actual current document must be shown for
     * comparison — without either, a stale save is just a silent no-op
     * from the editor's point of view.
     */
    public function testStaleBaseRevReRendersWithTheTypedTextAndTheCurrentServerDocument(): void
    {
        // Someone else's edit lands first.
        $this->ownerSubmit('/reports:mri:mioveni:a/edit', [
            'document' => "---\ntitle: v2\nvisibility: private\n---\n\nv2 server body\n",
            'base_rev' => 1,
        ]);

        $stale = $this->ownerSubmit('/reports:mri:mioveni:a/edit', [
            'document' => "---\ntitle: my edit\nvisibility: private\n---\n\nmy typed body\n",
            'base_rev' => 1,
        ]);

        self::assertSame(200, $stale->status);
        self::assertStringContainsString('my typed body', $stale->body, 'the conflicting submission must not be lost');
        self::assertStringContainsString('v2 server body', $stale->body, 'the current server document must be shown for comparison');
        self::assertStringContainsString('name="base_rev" value="2"', $stale->body, 'a resubmit must target the now-current revision');

        // And the stale attempt must not have overwritten the real save.
        $unchanged = $this->ownerRequest('GET', '/reports:mri:mioveni:a');
        self::assertStringContainsString('v2 server body', $unchanged->body);
    }

    public function testEditLinkAppearsOnThePageViewForACallerWhoCanWrite(): void
    {
        $response = $this->ownerRequest('GET', '/reports:mri:mioveni:a');

        self::assertStringContainsString('/reports:mri:mioveni:a/edit', $response->body);
    }

    public function testEditLinkIsAbsentForAViewer(): void
    {
        $this->createViewer('ana', 'reports:mri');

        $response = $this->authenticatedGet('ana', '/reports:mri:mioveni:a');

        self::assertStringNotContainsString('/reports:mri:mioveni:a/edit', $response->body);
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
    private function ownerSubmit(string $path, array $fields): Response
    {
        return $this->authenticatedSubmit('owner', $path, $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function authenticatedSubmit(string $username, string $path, array $fields): Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            'POST',
            $path,
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
