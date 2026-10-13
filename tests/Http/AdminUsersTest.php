<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileGroupStore;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Group;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * GET/POST /admin/users, end to end through the real Kernel: owner-only
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
        self::assertSame('/admin/users/mihai?saved=1', $response->headers['Location'], "onto the new account's page");

        $store = new FlatFileUserStore($this->dataRoot);
        $created = $store->find('mihai');
        self::assertNotNull($created);
        self::assertFalse($created->isOwner);
        self::assertCount(1, $created->grants);
        self::assertSame('reports:mri', $created->grants[0]->namespace);
    }

    /**
     * The end-to-end path a controller-only test would miss: an account
     * created through this screen must actually work: log in, and write
     * inside the namespace it was granted.
     */
    public function testAnAccountCreatedThroughTheScreenCanLogInAndWriteInItsGrantedNamespace(): void
    {
        $this->ownerFormRequest('POST', '/admin/users', [
            'username' => 'mihai',
            'password' => 'correct-horse-2',
            'grants' => 'reports:mri:editor',
        ]);
        $this->makeRadiologist('mihai'); // patient pages are a radiologist's (D47)

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

        self::assertSame(422, $response->status);
        self::assertStringContainsString('already exists', $response->body);
    }

    public function testCreatingWithAnInvalidGrantReRendersWithAnError(): void
    {
        $response = $this->ownerFormRequest('POST', '/admin/users', [
            'username' => 'mihai',
            'password' => 'x',
            'grants' => 'reports:mri:admin',
        ]);

        self::assertSame(422, $response->status);
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

    /**
     * Who signs (D37, amended 2026-10-13): an owner ticks "Radiologist" on the account;
     * the list shows it, saving the profile without the box clears it, and the
     * account itself cannot do either.
     */
    public function testOwnerMarksAnAccountAsARadiologistAndCanTakeItBack(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        $store->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        self::assertFalse($store->find('mihai')?->radiologist, 'nobody is a radiologist until an owner says so');

        $this->ownerFormRequest('POST', '/admin/users/mihai/profile', ['display_name' => 'Dr. M', 'title' => '', 'radiologist' => '1']);
        self::assertTrue($store->find('mihai')?->radiologist);
        self::assertTrue($store->find('mihai')?->canSign('reports:mri:mioveni:a'));
        self::assertStringContainsString('ph-radioactive', $this->ownerFormRequest('GET', '/admin/users', [])->body);

        $this->ownerFormRequest('POST', '/admin/users/mihai/profile', ['display_name' => 'Dr. M', 'title' => '']);
        self::assertFalse($store->find('mihai')?->radiologist, 'the box left empty takes it back');

        $this->authenticatedRequest('mihai', 'POST', '/admin/users/mihai/profile');
        self::assertFalse($store->find('mihai')?->radiologist, 'an account cannot make itself one');
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
     * authenticating: proves the end-to-end path, not just the flag on
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

        self::assertSame(422, $response->status);
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
        if (str_starts_with($namespace, 'reports')) {
            $this->makeRadiologist($username); // patient pages are a radiologist's (D47)
        }
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

    /** One account's page: identity and access in one form, audited by field name; groups resolve (D47) */
    public function testTheAccountPageEditsAccessAndGroupsAndSaysWhatItAddsUpTo(): void
    {
        (new FlatFileGroupStore($this->dataRoot))->save(new Group('radiologists', 'All radiologists', [new Grant('reports', GrantRole::Editor)], true, '', ''));
        $store = new FlatFileUserStore($this->dataRoot);
        $store->create('ana', 'x', false, [new Grant('wiki', GrantRole::Editor), new Grant('reports:ct', GrantRole::Viewer)]);

        $page = $this->ownerFormRequest('GET', '/admin/users/ana', []);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('<s>reports:ct</s>', $page->body, 'a report grant of someone not a radiologist is shown as not counted');
        self::assertStringContainsString('value="radiologists"', $page->body, 'the groups to tick');

        $saved = $this->ownerFormRequest('POST', '/admin/users/ana', [
            'display_name' => 'Dr. Ana', 'title' => '', 'grants' => "wiki:editor\nreports:ct:viewer", 'groups' => ['radiologists', 'nope'],
        ]);
        self::assertSame('/admin/users/ana?saved=1', $saved->headers['Location']);
        $ana = $store->find('ana');
        self::assertSame(['radiologists'], $ana?->groups, 'a group that does not exist is not added');
        self::assertTrue($ana?->isRadiologist());
        self::assertFalse($ana?->radiologist, 'radiologist through the group, not on the account');
        self::assertTrue($ana?->canSign('reports:mri:s:260101-x'));
        self::assertStringNotContainsString('<s>reports:ct</s>', $this->ownerFormRequest('GET', '/admin/users/ana', [])->body);

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"user.access"', $audit);
        self::assertStringContainsString('"groups"', $audit);
        self::assertStringNotContainsString('Dr. Ana', $audit, 'field names only, never values');

        $bad = $this->ownerFormRequest('POST', '/admin/users/ana', ['grants' => 'wiki:admin']);
        self::assertSame(422, $bad->status);
        self::assertSame(['radiologists'], $store->find('ana')?->groups, 'nothing saved on an error');
    }

    public function testTheLastActiveOwnerCannotStopBeingOne(): void
    {
        $response = $this->ownerFormRequest('POST', '/admin/users/owner', ['grants' => '']);

        self::assertSame(422, $response->status);
        self::assertTrue((new FlatFileUserStore($this->dataRoot))->find('owner')?->isOwner);
    }

    /** Admin → Groups: create, list with members, edit, and delete only once empty (D47) */
    public function testGroupsAreCreatedEditedAndDeletedOnlyWhenEmpty(): void
    {
        $this->createEditor('ed', 'wiki');
        self::assertSame(404, $this->authenticatedRequest('ed', 'GET', '/admin/groups')->status, 'owners only');

        $created = $this->ownerFormRequest('POST', '/admin/groups', ['name' => 'writers', 'grants' => 'wiki:editor', 'display_name' => 'Writers']);
        self::assertSame('/admin/groups/writers?saved=1', $created->headers['Location']);
        self::assertSame(422, $this->ownerFormRequest('POST', '/admin/groups', ['name' => 'writers'])->status, 'no two with one name');
        self::assertSame(422, $this->ownerFormRequest('POST', '/admin/groups', ['name' => 'Bad Name'])->status);

        $store = new FlatFileUserStore($this->dataRoot);
        $store->save($store->create('ion', 'x', false)->with(groups: ['writers']));
        self::assertTrue($store->find('ion')?->canWrite('wiki:notes'));
        self::assertStringContainsString('/admin/users/ion', $this->ownerFormRequest('GET', '/admin/groups', [])->body, 'its members are listed');

        $this->ownerFormRequest('POST', '/admin/groups/writers', ['grants' => 'docs:editor', 'display_name' => 'Writers']);
        $store = new FlatFileUserStore($this->dataRoot); // the next request's read
        self::assertFalse($store->find('ion')?->canWrite('wiki:notes'), 'a group edit reaches its members');
        self::assertTrue($store->find('ion')?->canWrite('docs:x'));

        self::assertSame(422, $this->ownerFormRequest('POST', '/admin/groups/writers/delete', [])->status, 'not while it has members');
        $store->save($store->find('ion')->with(groups: []));
        self::assertSame(302, $this->ownerFormRequest('POST', '/admin/groups/writers/delete', [])->status);
        self::assertNull((new FlatFileGroupStore($this->dataRoot))->find('writers'));
    }
}
