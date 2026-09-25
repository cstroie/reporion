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
 * GET/POST /admin/users, end to end through the real Kernel — owner-only
 * account management (Controller\AdminUsersController).
 */
final class AdminUsersTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
    }

    public function testOwnerSeesTheAccountsList(): void
    {
        $response = $this->ownerRequest('GET', '/admin/users');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('owner', $response->body);
    }

    public function testNonOwnerGets404(): void
    {
        $this->createEditor('mihai', 'reports:mri');

        $response = $this->authenticatedRequest('mihai', 'GET', '/admin/users');

        self::assertSame(404, $response->status);
    }

    public function testAnonymousGets404(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('GET', '/admin/users'));

        self::assertSame(404, $response->status);
    }

    public function testOwnerCanCreateAnAccount(): void
    {
        $response = $this->ownerFormRequest('POST', '/admin/users', [
            'username' => 'mihai',
            'password' => 'correct-horse-2',
            'grants' => 'reports:mri:editor',
        ]);

        self::assertSame(302, $response->status);
        self::assertSame('/admin/users', $response->headers['Location']);

        $store = new FlatFileUserStore($this->dataRoot);
        $created = $store->find('mihai');
        self::assertNotNull($created);
        self::assertFalse($created->isOwner);
        self::assertCount(1, $created->grants);
        self::assertSame('reports:mri', $created->grants[0]->namespace);
    }

    /**
     * The end-to-end path a controller-only test would miss: an account
     * created through this screen must actually work — log in, and write
     * inside the namespace it was granted.
     */
    public function testAnAccountCreatedThroughTheScreenCanLogInAndWriteInItsGrantedNamespace(): void
    {
        $this->ownerFormRequest('POST', '/admin/users', [
            'username' => 'mihai',
            'password' => 'correct-horse-2',
            'grants' => 'reports:mri:editor',
        ]);

        $login = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/login',
            body: 'username=mihai&password=correct-horse-2'
        ));
        self::assertSame(302, $login->status, 'the created account must be able to log in');

        [$pair] = explode(';', $login->headers['Set-Cookie'], 2);
        [, $cookieValue] = explode('=', $pair, 2);

        $write = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/api/v1/pages',
            cookies: ['reporion' => rawurldecode($cookieValue)],
            body: (string) json_encode([
                'path' => 'reports:mri:mioveni:a',
                'meta' => ['title' => 'x', 'visibility' => 'private'],
                'body' => 'x',
            ])
        ));
        self::assertSame(201, $write->status, 'the granted namespace must actually be writable');
    }

    public function testCreatingADuplicateUsernameReRendersWithAnError(): void
    {
        $this->ownerFormRequest('POST', '/admin/users', ['username' => 'mihai', 'password' => 'x']);

        $response = $this->ownerFormRequest('POST', '/admin/users', ['username' => 'mihai', 'password' => 'y']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('already exists', $response->body);
    }

    public function testCreatingWithAnInvalidGrantReRendersWithAnError(): void
    {
        $response = $this->ownerFormRequest('POST', '/admin/users', [
            'username' => 'mihai',
            'password' => 'x',
            'grants' => 'reports:mri:admin',
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('mihai', $response->body, 'must not lose the typed username on error');
        self::assertStringContainsString('Invalid grant role', $response->body);

        $store = new FlatFileUserStore($this->dataRoot);
        self::assertNull($store->find('mihai'), 'a rejected grant must not leave a partially-created account behind');
    }

    public function testOwnerCanSetAndEditTheSignatureDetails(): void
    {
        $this->ownerFormRequest('POST', '/admin/users', [
            'username' => 'mihai',
            'password' => 'correct-horse-2',
            'display_name' => 'Dr. Test Mihai',
            'title' => 'Medic specialist',
        ]);
        $store = new FlatFileUserStore($this->dataRoot);
        self::assertSame('Dr. Test Mihai', $store->find('mihai')?->displayName);
        self::assertSame('Medic specialist', $store->find('mihai')?->title);

        $response = $this->ownerFormRequest('POST', '/admin/users/mihai/profile', [
            'display_name' => '  Dr. T. Mihai ',
            'title' => 'Medic primar',
        ]);

        self::assertSame(302, $response->status);
        $edited = $store->find('mihai');
        self::assertSame('Dr. T. Mihai', $edited?->displayName);
        self::assertSame('Medic primar', $edited?->title);
        self::assertTrue($edited?->active, 'editing the profile keeps everything else');
        self::assertStringContainsString('Dr. T. Mihai', $this->ownerFormRequest('GET', '/admin/users', [])->body);
    }

    public function testNonOwnerCannotEditAProfile(): void
    {
        $this->createEditor('mihai', 'reports:mri');

        $response = $this->authenticatedRequest('mihai', 'POST', '/admin/users/mihai/profile');

        self::assertSame(404, $response->status);
        self::assertSame('', (new FlatFileUserStore($this->dataRoot))->find('mihai')?->displayName);
    }

    public function testOwnerCanDeactivateAndReactivateAnAccount(): void
    {
        $this->createEditor('mihai', 'reports:mri');

        $deactivate = $this->ownerFormRequest('POST', '/admin/users/mihai/deactivate', []);
        self::assertSame(302, $deactivate->status);

        $store = new FlatFileUserStore($this->dataRoot);
        self::assertFalse($store->find('mihai')?->active);

        $reactivate = $this->ownerFormRequest('POST', '/admin/users/mihai/reactivate', []);
        self::assertSame(302, $reactivate->status);
        self::assertTrue($store->find('mihai')?->active);
    }

    /**
     * Deactivating a deactivated account's cookie must actually stop it
     * authenticating — proves the end-to-end path, not just the flag on
     * disk (Session::principal() already unit-tests the flag in isolation).
     */
    public function testDeactivatingAnAccountStopsItFromAuthenticating(): void
    {
        $this->createEditor('mihai', 'reports:mri');
        $login = Kernel::boot($this->config)->handle(new Request('POST', '/login', body: 'username=mihai&password=x'));
        [$pair] = explode(';', $login->headers['Set-Cookie'], 2);
        [, $cookieValue] = explode('=', $pair, 2);
        $cookie = rawurldecode($cookieValue);

        $this->ownerFormRequest('POST', '/admin/users/mihai/deactivate', []);

        $write = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/api/v1/pages',
            cookies: ['reporion' => $cookie],
            body: (string) json_encode(['path' => 'reports:mri:mioveni:a', 'meta' => ['title' => 'x'], 'body' => 'x'])
        ));
        self::assertSame(404, $write->status);
    }

    public function testCannotDeactivateTheLastActiveOwner(): void
    {
        $response = $this->ownerFormRequest('POST', '/admin/users/owner/deactivate', []);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('last active owner', $response->body);

        $store = new FlatFileUserStore($this->dataRoot);
        self::assertTrue($store->find('owner')?->active, 'must not have actually been deactivated');
    }

    public function testCanDeactivateAnOwnerWhenAnotherActiveOwnerExists(): void
    {
        (new FlatFileUserStore($this->dataRoot))->create('second-owner', password_hash('x', PASSWORD_ARGON2ID), true);

        $response = $this->ownerFormRequest('POST', '/admin/users/owner/deactivate', []);

        self::assertSame(302, $response->status);
        $store = new FlatFileUserStore($this->dataRoot);
        self::assertFalse($store->find('owner')?->active);
    }

    public function testNonOwnerCannotDeactivateAnyone(): void
    {
        $this->createEditor('mihai', 'reports:mri');

        $response = $this->authenticatedFormRequest('mihai', 'POST', '/admin/users/owner/deactivate', []);

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

    private function ownerRequest(string $method, string $path): Response
    {
        return $this->authenticatedRequest('owner', $method, $path);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function ownerFormRequest(string $method, string $path, array $fields): Response
    {
        return $this->authenticatedFormRequest('owner', $method, $path, $fields);
    }

    private function authenticatedRequest(string $username, string $method, string $path): Response
    {
        $cookie = $this->issueCookie($username);

        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $cookie]));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function authenticatedFormRequest(string $username, string $method, string $path, array $fields): Response
    {
        $cookie = $this->issueCookie($username);

        return Kernel::boot($this->config)->handle(new Request(
            $method,
            $path,
            cookies: ['reporion' => $cookie],
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
