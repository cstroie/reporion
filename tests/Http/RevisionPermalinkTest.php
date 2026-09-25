<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * GET /{path}@{rev} (an older revision, rendered from its own bytes) and
 * GET /r/{pid}/{rev} (the rename-proof link exports cite, D3).
 */
final class RevisionPermalinkTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
    }

    public function testAnOlderRevisionRendersFromItsOwnBytesWithANotice(): void
    {
        $this->twoRevisions('reports:mri:mioveni:a', 'private');

        $old = $this->owner('GET', '/reports:mri:mioveni:a@1');
        $current = $this->owner('GET', '/reports:mri:mioveni:a');

        self::assertSame(200, $old->status);
        self::assertStringContainsString('first body', $old->body);
        self::assertStringNotContainsString('second body', $old->body);
        self::assertStringContainsString(t('rev.viewing', [1, 2]), $old->body);
        self::assertStringNotContainsString(t('rev.view_current'), $current->body);
    }

    public function testRevisionsOutsideTheHistoryAre404(): void
    {
        $this->twoRevisions('reports:mri:mioveni:a', 'private');

        self::assertSame(404, $this->owner('GET', '/reports:mri:mioveni:a@3')->status);
        self::assertSame(404, $this->owner('GET', '/reports:mri:mioveni:a@0')->status);
    }

    public function testAnonymousGets404ForAPrivateRevisionAndThePublicLayoutForAPublicOne(): void
    {
        $this->twoRevisions('reports:mri:mioveni:priv', 'private');
        $this->twoRevisions('docs:pub', 'public');

        $private = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:priv@1'));
        $public = Kernel::boot($this->config)->handle(new Request('GET', '/docs:pub@1'));

        self::assertSame(404, $private->status);
        self::assertSame(200, $public->status);
        self::assertStringContainsString('first body', $public->body);
        self::assertStringContainsString('wk-public', $public->body);
    }

    public function testPermalinkRedirectsToTheCurrentPathAtThatRevision(): void
    {
        $pid = $this->twoRevisions('reports:mri:mioveni:a', 'private');

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/r/' . $pid . '/1',
            cookies: ['reporion' => $this->cookie()],
            basePath: '/reporion',
        ));

        self::assertSame(302, $response->status);
        self::assertSame('/reporion/reports:mri:mioveni:a@1', $response->headers['Location']);
    }

    public function testPermalinkIs404ForAnInvisiblePageABadRevOrAnUnknownPid(): void
    {
        $pid = $this->twoRevisions('reports:mri:mioveni:a', 'private');

        self::assertSame(404, Kernel::boot($this->config)->handle(new Request('GET', '/r/' . $pid . '/1'))->status, 'anonymous, private page');
        self::assertSame(404, $this->owner('GET', '/r/' . $pid . '/9')->status);
        self::assertSame(404, $this->owner('GET', '/r/' . $pid . '/x')->status);
        self::assertSame(404, $this->owner('GET', '/r/01NOTAPID00000000000000000/1')->status);
    }

    public function testASignedRevisionShowsItsSignatureAndDigestCheck(): void
    {
        $this->owner('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:s',
            'meta' => [
                'title' => 'RM cerebral nativ', 'visibility' => 'private', 'modality' => ['MR'],
                'region' => ['neuro'], 'site' => 'mioveni', 'study_date' => '2026-09-23',
                'summary' => 'Fara leziuni active.', 'indication' => 'Cefalee cronica.',
                'patient' => ['name' => 'Test Patient'],
            ],
            'body' => 'signed body',
        ]);
        self::assertSame(200, $this->owner('POST', '/api/v1/pages/reports:mri:mioveni:s/sign', ['parafa' => 'P-1'])->status);

        $response = $this->owner('GET', '/reports:mri:mioveni:s@1');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('parafa P-1', $response->body);
        self::assertStringContainsString(t('rev.digest_matches'), $response->body);
    }

    public function testAPageLiterallyNamedWithAnAtSuffixIsNotShadowed(): void
    {
        $this->twoRevisions('docs:odd', 'public');
        $this->createPage('docs:odd@2', 'public', 'Literal', 'literal page body');

        $response = $this->owner('GET', '/docs:odd@2');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('literal page body', $response->body);
    }

    /** Two revisions ("first body", "second body"); returns the pid */
    private function twoRevisions(string $path, string $visibility): string
    {
        $created = json_decode($this->owner('POST', '/api/v1/pages', [
            'path' => $path,
            'meta' => ['title' => 'T', 'visibility' => $visibility],
            'body' => 'first body',
        ])->body, true);
        $this->owner('PUT', '/api/v1/pages/' . $path, [
            'meta' => ['title' => 'T', 'visibility' => $visibility],
            'body' => 'second body',
            'base_rev' => 1,
        ]);

        return (string) $created['pid'];
    }

    /** @param array<string, mixed> $body */
    private function owner(string $method, string $path, array $body = []): Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            $method,
            $path,
            cookies: ['reporion' => $this->cookie()],
            body: $body === [] ? '' : (string) json_encode($body),
        ));
    }

    private function cookie(): string
    {
        return (new Session(
            (string) $this->config['auth']['session_secret'],
            (string) $this->config['auth']['session_name'],
            (int) $this->config['auth']['session_lifetime'],
            new FlatFileUserStore($this->dataRoot),
        ))->issue('owner');
    }
}
