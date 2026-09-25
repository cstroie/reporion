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
 * GET /profile, POST /profile/password, POST /admin/users/{u}/password.
 */
final class ProfileTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner('owner', 'owner-password-1');
        (new FlatFileUserStore($this->dataRoot))->create(
            'mihai',
            password_hash('old-password-1', PASSWORD_ARGON2ID),
            false,
            [new Grant('reports:mri', GrantRole::Editor)],
            'Dr. Mihai Test',
            'Medic primar',
        );
    }

    public function testProfileShowsTheAccountAndIsNotForAnonymous(): void
    {
        $mine = $this->as('mihai', 'GET', '/profile');

        self::assertSame(200, $mine->status);
        self::assertStringContainsString('reports:mri:editor', $mine->body);
        self::assertStringContainsString('Dr. Mihai Test · Medic primar', $mine->body);
        self::assertSame(404, Kernel::boot($this->config)->handle(new Request('GET', '/profile'))->status);
    }

    public function testChangingTheOwnPasswordNeedsTheCurrentOneAndIsAudited(): void
    {
        $wrong = $this->as('mihai', 'POST', '/profile/password', ['current' => 'nope', 'new' => 'new-password-1', 'repeat' => 'new-password-1']);
        self::assertSame(422, $wrong->status);
        self::assertTrue($this->login('mihai', 'old-password-1'));

        $ok = $this->as('mihai', 'POST', '/profile/password', ['current' => 'old-password-1', 'new' => 'new-password-1', 'repeat' => 'new-password-1']);
        self::assertSame(302, $ok->status);
        self::assertFalse($this->login('mihai', 'old-password-1'));
        self::assertTrue($this->login('mihai', 'new-password-1'));

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertSame(2, substr_count($audit, '"action":"password.change"'), 'the refused attempt and the change');
        $changes = array_values(array_filter(explode("\n", $audit), static fn (string $l): bool => str_contains($l, '"password.change"')));
        self::assertStringContainsString('"outcome":"denied"', $changes[0]);
        self::assertStringContainsString('"outcome":"ok"', $changes[1]);
        self::assertStringNotContainsString('new-password-1', $audit);
    }

    public function testShortOrMismatchedNewPasswordsAreRefused(): void
    {
        self::assertSame(422, $this->as('mihai', 'POST', '/profile/password', ['current' => 'old-password-1', 'new' => 'short', 'repeat' => 'short'])->status);
        self::assertSame(422, $this->as('mihai', 'POST', '/profile/password', ['current' => 'old-password-1', 'new' => 'new-password-1', 'repeat' => 'new-password-2'])->status);
        self::assertTrue($this->login('mihai', 'old-password-1'));
    }

    public function testAnOwnerCanSetSomeonesPasswordAndOthersCannot(): void
    {
        self::assertSame(404, $this->as('mihai', 'POST', '/admin/users/owner/password', ['new' => 'taken-over-1', 'repeat' => 'taken-over-1'])->status);
        self::assertTrue($this->login('owner', 'owner-password-1'));

        $reset = $this->as('owner', 'POST', '/admin/users/mihai/password', ['new' => 'reset-password-1', 'repeat' => 'reset-password-1']);
        self::assertSame(302, $reset->status);
        self::assertTrue($this->login('mihai', 'reset-password-1'));
        self::assertStringContainsString('"action":"password.reset"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
    }

    /** @param array<string, string> $fields */
    private function as(string $username, string $method, string $path, array $fields = []): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);

        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $cookie], body: http_build_query($fields)));
    }

    private function login(string $username, string $password): bool
    {
        return Kernel::boot($this->config)->handle(new Request('POST', '/login', body: http_build_query(['username' => $username, 'password' => $password])))->status === 302;
    }
}
