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
 * GET /{path}/history, POST /{path}/history/revert, end to end through the
 * real Kernel (Controller\HistoryController).
 */
final class HistoryTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
    }

    public function testOwnerSeesTheRevisionList(): void
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
            '/reports:mri:mioveni:a/history',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('2 revision(s)', $response->body);
        self::assertStringContainsString('current', $response->body);
    }

    /**
     * The page header's History tab must be lit, and there is no
     * standalone "Back to page" button — the Report tab is that link.
     */
    public function testPageTabsShowHistoryActiveAndBackButtonIsGone(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/history',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertStringContainsString('wk-tab" data-on="1" aria-current="page" href="/reports:mri:mioveni:a/history"', $response->body);
        self::assertStringContainsString('wk-tab" data-on="" href="/reports:mri:mioveni:a"', $response->body);
        self::assertStringNotContainsString(t('page.back'), $response->body);
    }

    public function testDiffPanelRendersWhenFromAndToAreGiven(): void
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
            '/reports:mri:mioveni:a/history',
            query: ['from' => '1', 'to' => '2'],
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('wk-difftext', $response->body);
        self::assertStringContainsString('line one changed', $response->body);
    }

    public function testNoDiffPanelWithoutFromAndTo(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/history',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertStringNotContainsString('wk-difftext', $response->body);
    }

    public function testHistoryOfAnUnknownPathIs404(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:does-not-exist/history',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCannotSeeHistoryOfAPrivatePage(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:a/history'));

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCanSeeHistoryOfAPublicPage(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'public'],
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:a/history'));

        self::assertSame(200, $response->status);
    }

    public function testEditorWithGrantCanSeeHistoryOfAPrivatePageInTheirNamespace(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->createEditor('mihai', 'reports:mri');

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/history',
            cookies: ['reporion' => $this->issueCookie('mihai')]
        ));

        self::assertSame(200, $response->status);
    }

    public function testEditorWithoutGrantCannotSeeHistoryOfAPrivatePage(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->createEditor('mihai', 'reports:ct');

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/history',
            cookies: ['reporion' => $this->issueCookie('mihai')]
        ));

        self::assertSame(404, $response->status);
    }

    public function testOwnerCanRestoreAnOldRevisionFromTheHistoryPage(): void
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
            'POST',
            '/reports:mri:mioveni:a/history/revert',
            cookies: ['reporion' => $this->issueCookie('owner')],
            body: 'to=1'
        ));

        self::assertSame(302, $response->status);
        self::assertSame('/reports:mri:mioveni:a/history', $response->headers['Location']);

        $followUp = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:a', cookies: ['reporion' => $this->issueCookie('owner')]));
        self::assertStringContainsString('v1 body', $followUp->body);
    }

    public function testViewerWithGrantCannotRestoreFromTheHistoryPage(): void
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
        (new FlatFileUserStore($this->dataRoot))->create(
            'ana',
            password_hash('x', PASSWORD_ARGON2ID),
            false,
            [new Grant('reports:mri', GrantRole::Viewer)]
        );

        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/reports:mri:mioveni:a/history/revert',
            cookies: ['reporion' => $this->issueCookie('ana')],
            body: 'to=1'
        ));

        self::assertSame(404, $response->status);
    }

    public function testViewerSeesNoRestoreButtonOnTheHistoryPage(): void
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
        (new FlatFileUserStore($this->dataRoot))->create(
            'ana',
            password_hash('x', PASSWORD_ARGON2ID),
            false,
            [new Grant('reports:mri', GrantRole::Viewer)]
        );

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/history',
            cookies: ['reporion' => $this->issueCookie('ana')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('/history/revert', $response->body, 'the restore form itself, not just its label, must be absent');
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
