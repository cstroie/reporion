<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * POST /api/v1/pages, PUT /api/v1/pages/{path} (docs/architecture-api.md
 * Table 2), end to end through the real Kernel — the write half of what
 * PageViewTest already proved for reads. Registered under /api/v1 (§3 JSON
 * API), not bare /pages — a mismatch caught and fixed while building this
 * step; see docs/BUILD_LOG.md.
 */
final class PagesApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
    }

    public function testOwnerCanCreateAPage(): void
    {
        $response = $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'RM cerebral', 'visibility' => 'private'],
            'body' => 'Text.',
        ]);

        self::assertSame(201, $response->status);
        $decoded = json_decode($response->body, true);
        self::assertSame('reports:mri:mioveni:a', $decoded['path']);
        self::assertSame(1, $decoded['rev']);
        self::assertNotSame('', $decoded['pid']);
    }

    public function testEditorWithGrantCanCreateAPageInTheirNamespace(): void
    {
        $this->createEditor('mihai', 'reports:mri');

        $response = $this->authenticatedRequest('mihai', 'POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'RM cerebral', 'visibility' => 'private'],
            'body' => 'Text.',
        ]);

        self::assertSame(201, $response->status);
    }

    /**
     * The write path must record who actually wrote it — meta.json's
     * revlog `by` field — not a fixed 'owner' string regardless of who is
     * signed in (a real gap the earlier Session rewrite step left open on
     * purpose; see docs/BUILD_LOG.md).
     */
    public function testCreatedPageRecordsTheRealUsernameAsActor(): void
    {
        $this->createEditor('mihai', 'reports:mri');
        $this->authenticatedRequest('mihai', 'POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'x', 'visibility' => 'private'],
            'body' => 'x',
        ]);

        $meta = json_decode(
            (string) file_get_contents($this->dataRoot . '/pages/reports/mri/mioveni/a/meta.json'),
            true
        );
        self::assertSame('mihai', $meta['revlog'][0]['by']);
    }

    public function testEditorWithoutGrantOnThisNamespaceCannotCreateAPageThere(): void
    {
        $this->createEditor('mihai', 'reports:ct');

        $response = $this->authenticatedRequest('mihai', 'POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'x', 'visibility' => 'private'],
            'body' => 'x',
        ]);

        self::assertSame(404, $response->status);
    }

    public function testViewerWithGrantCannotCreateAPage(): void
    {
        $this->createViewer('ana', 'reports:mri');

        $response = $this->authenticatedRequest('ana', 'POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'x', 'visibility' => 'private'],
            'body' => 'x',
        ]);

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCannotCreateAPage(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/api/v1/pages',
            body: json_encode(['path' => 'reports:mri:mioveni:a', 'meta' => ['title' => 'x'], 'body' => 'x'])
        ));

        self::assertSame(404, $response->status);
    }

    public function testCreateWithMissingPathIs422(): void
    {
        $response = $this->ownerRequest('POST', '/api/v1/pages', ['meta' => ['title' => 'x']]);

        self::assertSame(422, $response->status);
    }

    public function testOwnerCanSaveANewRevision(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = $this->ownerRequest('PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v2', 'visibility' => 'private'],
            'body' => 'v2 body',
            'base_rev' => 1,
        ]);

        self::assertSame(200, $response->status);
        $decoded = json_decode($response->body, true);
        self::assertSame(2, $decoded['rev']);
        self::assertSame("v2 body\n", $decoded['body']);
    }

    public function testStaleBaseRevIs409WithBothBodies(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->ownerRequest('PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v2', 'visibility' => 'private'],
            'body' => 'v2 body',
            'base_rev' => 1,
        ]);

        $response = $this->ownerRequest('PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v3-conflicting', 'visibility' => 'private'],
            'body' => 'v3 body',
            'base_rev' => 1,
        ]);

        self::assertSame(409, $response->status);
        $decoded = json_decode($response->body, true);
        self::assertSame('conflict', $decoded['error']['code']);
        self::assertSame(1, $decoded['submitted_base_rev']);
        self::assertSame(2, $decoded['current']['rev']);
        self::assertSame("v2 body\n", $decoded['current']['body']);
    }

    public function testSavingAnUnknownPathIs404(): void
    {
        $response = $this->ownerRequest('PUT', '/api/v1/pages/reports:mri:mioveni:does-not-exist', [
            'meta' => ['title' => 'x'],
            'body' => 'x',
            'base_rev' => 1,
        ]);

        self::assertSame(404, $response->status);
    }

    public function testEditorWithGrantCanSaveInTheirNamespace(): void
    {
        $this->createEditor('mihai', 'reports:mri');
        $this->authenticatedRequest('mihai', 'POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = $this->authenticatedRequest('mihai', 'PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v2', 'visibility' => 'private'],
            'body' => 'v2 body',
            'base_rev' => 1,
        ]);

        self::assertSame(200, $response->status);
    }

    public function testViewerWithGrantCannotSave(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->createViewer('ana', 'reports:mri');

        $response = $this->authenticatedRequest('ana', 'PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v2', 'visibility' => 'private'],
            'body' => 'v2 body',
            'base_rev' => 1,
        ]);

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCannotSave(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'PUT',
            '/api/v1/pages/reports:mri:mioveni:a',
            body: json_encode(['meta' => ['title' => 'x'], 'body' => 'x', 'base_rev' => 1])
        ));

        self::assertSame(404, $response->status);
    }

    public function testOwnerCanDeleteAPage(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = $this->ownerRequest('DELETE', '/api/v1/pages/reports:mri:mioveni:a', []);

        self::assertSame(200, $response->status);
        $decoded = json_decode($response->body, true);
        self::assertTrue($decoded['deleted']);

        // The page must actually be gone, not just report success.
        $followUp = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:a'));
        self::assertSame(404, $followUp->status);
    }

    public function testDeletingAnUnknownPathIs404(): void
    {
        $response = $this->ownerRequest('DELETE', '/api/v1/pages/reports:mri:mioveni:does-not-exist', []);

        self::assertSame(404, $response->status);
    }

    public function testEditorWithGrantCanDeleteInTheirNamespace(): void
    {
        $this->createEditor('mihai', 'reports:mri');
        $this->authenticatedRequest('mihai', 'POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = $this->authenticatedRequest('mihai', 'DELETE', '/api/v1/pages/reports:mri:mioveni:a', []);

        self::assertSame(200, $response->status);
    }

    public function testViewerWithGrantCannotDelete(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->createViewer('ana', 'reports:mri');

        $response = $this->authenticatedRequest('ana', 'DELETE', '/api/v1/pages/reports:mri:mioveni:a', []);

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCannotDelete(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request('DELETE', '/api/v1/pages/reports:mri:mioveni:a'));

        self::assertSame(404, $response->status);

        // Anonymous denial must not have deleted it either.
        $ownerCheck = $this->ownerRequest('DELETE', '/api/v1/pages/reports:mri:mioveni:a', []);
        self::assertSame(200, $ownerCheck->status);
    }

    public function testOwnerCanRevertToAnEarlierRevision(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->ownerRequest('PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v2', 'visibility' => 'private'],
            'body' => 'v2 body',
            'base_rev' => 1,
        ]);

        $response = $this->ownerRequest('POST', '/api/v1/pages/reports:mri:mioveni:a/revert', ['to' => 1]);

        self::assertSame(200, $response->status);
        $decoded = json_decode($response->body, true);
        // A2: a NEW revision (3), never rewrites revision 1 itself.
        self::assertSame(3, $decoded['rev']);
        self::assertSame("v1 body\n", $decoded['body']);
    }

    public function testRevertOfAnUnknownRevisionIs404(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = $this->ownerRequest('POST', '/api/v1/pages/reports:mri:mioveni:a/revert', ['to' => 99]);

        self::assertSame(404, $response->status);
    }

    public function testRevertWithMissingToIs422(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = $this->ownerRequest('POST', '/api/v1/pages/reports:mri:mioveni:a/revert', []);

        self::assertSame(422, $response->status);
    }

    public function testEditorWithGrantCanRevertInTheirNamespace(): void
    {
        $this->createEditor('mihai', 'reports:mri');
        $this->authenticatedRequest('mihai', 'POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->authenticatedRequest('mihai', 'PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v2', 'visibility' => 'private'],
            'body' => 'v2 body',
            'base_rev' => 1,
        ]);

        $response = $this->authenticatedRequest('mihai', 'POST', '/api/v1/pages/reports:mri:mioveni:a/revert', ['to' => 1]);

        self::assertSame(200, $response->status);
    }

    public function testViewerWithGrantCannotRevert(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->createViewer('ana', 'reports:mri');

        $response = $this->authenticatedRequest('ana', 'POST', '/api/v1/pages/reports:mri:mioveni:a/revert', ['to' => 1]);

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCannotRevert(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/api/v1/pages/reports:mri:mioveni:a/revert',
            body: (string) json_encode(['to' => 1])
        ));

        self::assertSame(404, $response->status);
    }

    public function testSigningACompletePageSucceedsAndSetsStatusSigned(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => $this->completeMrMeta(),
            'body' => 'v1 body',
        ]);

        $response = $this->ownerRequest('POST', '/api/v1/pages/reports:mri:mioveni:a/sign', ['parafa' => 'AG-04127']);

        self::assertSame(200, $response->status);
        $decoded = json_decode($response->body, true);
        self::assertSame('signed', $decoded['status']);
    }

    public function testSigningAnIncompletePageIs422WithTheMissingFieldsNamed(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private', 'modality' => ['MR']],
            'body' => 'v1 body',
        ]);

        $response = $this->ownerRequest('POST', '/api/v1/pages/reports:mri:mioveni:a/sign', []);

        self::assertSame(422, $response->status);
        $decoded = json_decode($response->body, true);
        self::assertSame('incomplete', $decoded['error']['code']);
        self::assertContains('summary', $decoded['error']['fields']['missing']);
        self::assertContains('indication', $decoded['error']['fields']['missing'], 'MR-specific required_for:sign field');
    }

    /**
     * The exact signatures[] count is a Storage-level concern, already
     * covered by FlatFileTest::testSigningTheSameRevisionTwiceLeavesExactlyOneSignature()
     * (the JSON payload doesn't expose signatures at all). At the HTTP
     * layer, the meaningful proxy is that a repeated sign never creates a
     * new revision.
     */
    public function testSigningTwiceDoesNotCreateANewRevision(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => $this->completeMrMeta(),
            'body' => 'v1 body',
        ]);
        $first = $this->ownerRequest('POST', '/api/v1/pages/reports:mri:mioveni:a/sign', []);

        $second = $this->ownerRequest('POST', '/api/v1/pages/reports:mri:mioveni:a/sign', []);

        self::assertSame(200, $second->status);
        $firstDecoded = json_decode($first->body, true);
        $secondDecoded = json_decode($second->body, true);
        self::assertSame($firstDecoded['rev'], $secondDecoded['rev']);
    }

    public function testEditorWithGrantCanSignInTheirNamespace(): void
    {
        $this->createEditor('mihai', 'reports:mri');
        $this->authenticatedRequest('mihai', 'POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => $this->completeMrMeta(),
            'body' => 'v1 body',
        ]);

        $response = $this->authenticatedRequest('mihai', 'POST', '/api/v1/pages/reports:mri:mioveni:a/sign', []);

        self::assertSame(200, $response->status);
    }

    /**
     * D37: signing follows the write grant, not merely being able to
     * read — a viewer must not be able to sign a page just because they
     * can see it.
     */
    public function testViewerWithGrantCannotSign(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => $this->completeMrMeta(),
            'body' => 'v1 body',
        ]);
        $this->createViewer('ana', 'reports:mri');

        $response = $this->authenticatedRequest('ana', 'POST', '/api/v1/pages/reports:mri:mioveni:a/sign', []);

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCannotSign(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => $this->completeMrMeta(),
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/pages/reports:mri:mioveni:a/sign'));

        self::assertSame(404, $response->status);
    }

    public function testSigningAnUnknownPathIs404(): void
    {
        $response = $this->ownerRequest('POST', '/api/v1/pages/reports:mri:mioveni:does-not-exist/sign', []);

        self::assertSame(404, $response->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeMrMeta(): array
    {
        return [
            'title' => 'RM cerebral nativ',
            'visibility' => 'private',
            'modality' => ['MR'],
            'region' => ['neuro'],
            'site' => 'mioveni',
            'study_date' => '2026-09-23',
            'summary' => 'Fara leziuni active.',
            'indication' => 'Cefalee cronica.',
            'patient' => ['name' => 'Ionescu Maria'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function ownerRequest(string $method, string $path, array $body): \Reporion\Http\Response
    {
        return $this->authenticatedRequest('owner', $method, $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function authenticatedRequest(string $username, string $method, string $path, array $body): \Reporion\Http\Response
    {
        $cookie = (new Session(
            (string) $this->config['auth']['session_secret'],
            (string) $this->config['auth']['session_name'],
            (int) $this->config['auth']['session_lifetime'],
            new FlatFileUserStore($this->dataRoot),
        ))->issue($username);

        return Kernel::boot($this->config)->handle(new Request(
            $method,
            $path,
            cookies: ['reporion' => $cookie],
            body: (string) json_encode($body),
        ));
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
}
