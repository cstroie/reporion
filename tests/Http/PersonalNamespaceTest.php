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
 * D46: every account is an editor of its own namespace, users:{username} — derived, never stored.
 * Over HTTP: it can write there and nowhere else, nobody else reads its private pages, an empty
 * namespace is its owner's (not a 404), the quick-nav leads to it, and the new-page form starts in it.
 */
final class PersonalNamespaceTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('alice', 'x', false);
        $users->create('bob', 'x', false);
        $users->create('dan', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $this->makeRadiologist('dan'); // a report grant counts only for a radiologist (D47)
    }

    public function testAnAccountWritesInItsOwnNamespaceAndNowhereElse(): void
    {
        $created = $this->as('alice', 'POST', '/api/v1/pages', ['path' => 'users:alice:notes', 'meta' => ['title' => 'Notes', 'visibility' => 'private'], 'body' => 'my notes']);
        self::assertSame(201, $created->status, $created->body);

        self::assertSame(404, $this->as('alice', 'POST', '/api/v1/pages', ['path' => 'users:bob:notes', 'meta' => ['title' => 'x', 'visibility' => 'private'], 'body' => 'x'])->status, "not in someone else's");
        self::assertSame(404, $this->as('alice', 'POST', '/api/v1/pages', ['path' => 'docs:notes', 'meta' => ['title' => 'x', 'visibility' => 'private'], 'body' => 'x'])->status, 'not in a namespace it has no grant on');
    }

    public function testNobodyElseReadsItsPrivatePagesButAnOwnerAndAGrantedAccountDo(): void
    {
        $this->as('alice', 'POST', '/api/v1/pages', ['path' => 'users:alice:notes', 'meta' => ['title' => 'Notes', 'visibility' => 'private'], 'body' => 'my notes']);

        self::assertSame(200, $this->as('alice', 'GET', '/users:alice:notes')->status);
        self::assertSame(404, $this->as('bob', 'GET', '/users:alice:notes')->status);
        self::assertSame(404, $this->as(null, 'GET', '/users:alice:notes')->status);
        self::assertSame(200, $this->as('owner', 'GET', '/users:alice:notes')->status, 'an instance owner sees everything');

        $users = new FlatFileUserStore($this->dataRoot);
        $bob = $users->find('bob');
        self::assertNotNull($bob);
        $users->save(new \Reporion\Auth\User('bob', $bob->passwordHash, false, [new Grant('users:alice', GrantRole::Viewer)], true, $bob->createdAt, $bob->updatedAt));
        self::assertSame(200, $this->as('bob', 'GET', '/users:alice:notes')->status, 'an explicit grant opens it, as on any namespace');
    }

    public function testTheImplicitGrantIsNeverWrittenToTheAccountFile(): void
    {
        $users = new FlatFileUserStore($this->dataRoot);
        $alice = $users->find('alice');
        self::assertNotNull($alice);
        $users->save($alice->with(displayName: 'Alice'));

        $json = json_decode((string) file_get_contents($this->dataRoot . '/users/alice.json'), true);
        self::assertSame([], $json['grants'], 'the disk holds what an owner granted, not the rule');
    }

    public function testAnEmptyPersonalNamespaceIsTheirsToOpenAndOthersGetA404(): void
    {
        $own = $this->as('alice', 'GET', '/users:alice:');
        self::assertSame(200, $own->status);
        self::assertStringContainsString('href="/users:alice/new"', $own->body, 'with the way to start a page');
        self::assertSame(404, $this->as('bob', 'GET', '/users:alice:')->status);
        self::assertSame(404, $this->as(null, 'GET', '/users:alice:')->status);
    }

    public function testTheQuickNavLeadsToMyPages(): void
    {
        $page = $this->as('alice', 'GET', '/')->body;
        self::assertStringContainsString('href="/users:alice:"', $page);
        self::assertStringContainsString(t('quick.mine'), $page);
    }

    public function testTheNewPageFormStartsInTheOwnNamespaceOnlyForAnAccountWithNoOtherPlace(): void
    {
        self::assertStringContainsString('value="users:alice:"', $this->as('alice', 'GET', '/new')->body);
        self::assertStringNotContainsString('value="users:dan:"', $this->as('dan', 'GET', '/new')->body, 'an editor elsewhere keeps the usual form');
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function as(?string $username, string $method, string $path, ?array $json = null): Response
    {
        $cookies = [];
        if ($username !== null) {
            $cookies = ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username)];
        }

        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: $cookies, body: $json !== null ? (string) json_encode($json) : ''));
    }
}
