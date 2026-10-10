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
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Service\NamespaceDefaults;

/**
 * Phase 16, F: a new page's visibility picker opens on the nearest
 * namespace description's level — a hint only; Public still needs D16's
 * acknowledgement, and every creator without a picker stays private.
 */
final class NamespaceDefaultVisibilityTest extends HttpTestCase
{
    private function defaults(): NamespaceDefaults
    {
        return new NamespaceDefaults(new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
    }

    public function testNoDescriptionMeansPrivate(): void
    {
        self::assertSame(['visibility' => 'private', 'from' => null], $this->defaults()->forNewPage('docs:new', null));
    }

    public function testTheNearestAncestorWins(): void
    {
        $this->createPage('docs', 'public', 'Docs', 'x');
        $this->createPage('docs:internal', 'unlisted', 'Internal', 'x');

        self::assertSame(['visibility' => 'unlisted', 'from' => 'docs:internal'], $this->defaults()->forNewPage('docs:internal:note', null));
        self::assertSame(['visibility' => 'public', 'from' => 'docs'], $this->defaults()->forNewPage('docs:other:note', null));
        self::assertSame(['visibility' => 'public', 'from' => 'docs'], $this->defaults()->forNewPage('docs:note', null));
    }

    public function testTheOlderIndexPageCountsToo(): void
    {
        $this->createPage('docs:_index', 'public', 'Docs', 'x');

        self::assertSame('docs:_index', $this->defaults()->forNewPage('docs:note', null)['from']);
    }

    public function testAnAncestorTheCallerCannotSeeIsSkippedAsIfAbsent(): void
    {
        $this->createPage('docs', 'public', 'Docs', 'x');
        $this->createPage('docs:secret', 'private', 'Secret', 'x');
        (new FlatFileUserStore($this->dataRoot))->create('ana', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('elsewhere', GrantRole::Viewer)]);
        $store = new FlatFileUserStore($this->dataRoot);
        $ana = $store->find('ana');

        // Private and not covered by a grant: invisible, so the walk goes on to the public parent
        self::assertSame('docs', $this->defaults()->forNewPage('docs:secret:note', $ana)['from']);
        // The owner sees it: private is the answer, and it is the source
        $owner = $store->find('owner');
        self::assertSame(['visibility' => 'private', 'from' => 'docs:secret'], $this->defaults()->forNewPage('docs:secret:note', $owner));
    }

    public function testTheEditorOpensOnTheDefaultButPublicStillNeedsTheAcknowledgement(): void
    {
        $this->createPage('docs', 'public', 'Docs', 'x');

        $opened = $this->ownerRequest('GET', '/docs:note/edit');

        self::assertSame(200, $opened->status);
        self::assertMatchesRegularExpression('/name="visibility" value="public" checked/', $opened->body);
        self::assertStringContainsString('Preselected from docs', $opened->body);
        self::assertStringNotContainsString('visibility_from', $opened->body);

        $refused = $this->ownerSubmit('/docs:note/edit', ['body' => 'text', 'base_rev' => 0, 'visibility' => 'public']);
        self::assertSame(422, $refused->status, 'no acknowledgement, nothing created');
        self::assertNull((new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'))->findByPath('docs:note', null));

        $done = $this->ownerSubmit('/docs:note/edit', ['body' => 'text', 'base_rev' => 0, 'visibility' => 'public', 'acknowledge' => '1']);
        self::assertSame(302, $done->status);
    }

    public function testACopyAndTheRawViewStayPrivate(): void
    {
        $this->createPage('docs', 'public', 'Docs', 'x');
        $this->createPage('docs:orig', 'public', 'Orig', 'x');

        $copy = $this->ownerRequest('GET', '/docs:copy/edit', ['from' => 'docs:orig']);
        self::assertDoesNotMatchRegularExpression('/name="visibility" value="public" checked/', $copy->body);
        $raw = $this->ownerRequest('GET', '/docs:note/edit', ['raw' => '1']);
        self::assertStringContainsString('visibility: private', $raw->body);
    }

    /** @param array<string, string> $query */
    private function ownerRequest(string $method, string $path, array $query = []): Response
    {
        return Kernel::boot($this->config)->handle(new Request($method, $path, query: $query, cookies: ['reporion' => $this->issueCookie('owner')]));
    }

    /**
     * @param array<string, mixed>  $fields
     * @param array<string, string> $query
     */
    private function ownerSubmit(string $path, array $fields, array $query = []): Response
    {
        return Kernel::boot($this->config)->handle(new Request('POST', $path, query: $query, cookies: ['reporion' => $this->issueCookie('owner')], body: http_build_query($fields)));
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
