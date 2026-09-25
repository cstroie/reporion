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
 * GET /{path}/compare, end to end through the real Kernel.
 */
final class CompareControllerTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
    }

    public function testOwnerSeesDiffBetweenTwoRevisions(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'line one',
        ]);
        $this->ownerRequest('PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'line one changed',
            'base_rev' => 1,
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/compare',
            query: ['from' => '1', 'to' => '2'],
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('wk-difftext', $response->body);
        self::assertStringContainsString('line one changed', $response->body);
    }

    public function testDefaultsToPreviousAndCurrent(): void
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

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/compare',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('wk-difftext', $response->body);
    }

    public function testNoDiffPanelForSingleRevision(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/compare',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('wk-difftext', $response->body);
    }

    public function testCompareOfUnknownPathIs404(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:does-not-exist/compare',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCannotComparePrivatePage(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/compare',
        ));

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCanComparePublicPage(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'public'],
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/compare',
        ));

        self::assertSame(200, $response->status);
    }

    public function testEditorWithGrantCanComparePrivatePageInNamespace(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->createEditor('mihai', 'reports:mri');

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/compare',
            cookies: ['reporion' => $this->issueCookie('mihai')]
        ));

        self::assertSame(200, $response->status);
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

    /**
     * @param array<string, mixed> $body
     */
    private function ownerRequest(string $method, string $path, array $body): \Reporion\Http\Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            $method,
            $path,
            cookies: ['reporion' => $this->issueCookie('owner')],
            body: (string) json_encode($body),
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
