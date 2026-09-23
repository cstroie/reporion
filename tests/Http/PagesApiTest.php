<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

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
        $this->config['auth']['owner_password_hash'] = password_hash('correct-horse', PASSWORD_ARGON2ID);
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

    /**
     * @param array<string, mixed> $body
     */
    private function ownerRequest(string $method, string $path, array $body): \Reporion\Http\Response
    {
        $cookie = (new Session(
            (string) $this->config['auth']['session_secret'],
            (string) $this->config['auth']['session_name'],
            (int) $this->config['auth']['session_lifetime'],
        ))->issue();

        return Kernel::boot($this->config)->handle(new Request(
            $method,
            $path,
            cookies: ['reporion' => $cookie],
            body: (string) json_encode($body),
        ));
    }
}
