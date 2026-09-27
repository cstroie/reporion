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
use Reporion\Storage\FlatFile;

/**
 * API tokens (roadmap phase 13) and the profile's signature details: a
 * user makes a token on /profile, and it acts as them on /api/v1 and
 * /export — no further.
 */
final class ApiTokensTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', password_hash('pw-mihai-123', PASSWORD_ARGON2ID), false, [new Grant('reports:mri', GrantRole::Editor)]);
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create('reports:mri:mioveni:a', ['title' => 'A', 'visibility' => 'private'], "Text.\n", 'owner');
        (new FlatFile($this->dataRoot, $index))->create('reports:ct:mioveni:b', ['title' => 'B', 'visibility' => 'private'], "Text.\n", 'owner');
    }

    public function testATokenIsShownOnceAndOnlyItsHashIsKept(): void
    {
        $token = $this->newToken('mihai', 'write');

        self::assertMatchesRegularExpression('/^rpn_[A-Za-z0-9_-]+\.[a-z2-7]{8}\.[A-Za-z0-9_-]{43}$/', $token);
        $record = (string) file_get_contents($this->dataRoot . '/users/mihai.json');
        self::assertStringNotContainsString(explode('.', $token)[2], $record, 'never the secret on disk');
        self::assertStringContainsString('"hash"', $record);
        self::assertStringNotContainsString($token, $this->as('mihai', 'GET', '/profile')->body, 'not shown again');
        self::assertStringContainsString('"token.create"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
    }

    public function testAWriteTokenActsAsItsAccountWithItsGrants(): void
    {
        $token = $this->newToken('mihai', 'write');

        $read = $this->bearer($token, 'GET', '/api/v1/pages/reports:mri:mioveni:a');
        self::assertSame(200, $read->status);
        self::assertSame(404, $this->bearer($token, 'GET', '/api/v1/pages/reports:ct:mioveni:b')->status, 'no grant on ct, as for the account');

        $saved = $this->bearer($token, 'PUT', '/api/v1/pages/reports:mri:mioveni:a', ['document' => "---\ntitle: A\nvisibility: private\n---\n\nEdited.\n", 'base_rev' => 1]);
        self::assertSame(200, $saved->status);
        self::assertSame('mihai', $this->storage()->read('reports:mri:mioveni:a')->meta['revlog'][1]['by']);
    }

    public function testAReadTokenCannotWrite(): void
    {
        $token = $this->newToken('mihai', 'read');

        self::assertSame(200, $this->bearer($token, 'GET', '/api/v1/pages/reports:mri:mioveni:a')->status);
        $write = $this->bearer($token, 'PUT', '/api/v1/pages/reports:mri:mioveni:a', ['document' => "---\ntitle: A\n---\n\nX\n", 'base_rev' => 1]);
        self::assertSame(403, $write->status);
        self::assertSame('insufficient_scope', json_decode($write->body, true)['error']['code']);
        self::assertSame(1, $this->storage()->read('reports:mri:mioveni:a')->rev);
    }

    public function testABadTokenIs401NeverAnonymous(): void
    {
        $token = $this->newToken('mihai', 'write');
        [$prefix, $id] = explode('.', $token);

        foreach (['nonsense', $prefix . '.' . $id . '.' . str_repeat('A', 43), 'Basic abc'] as $bad) {
            $response = $this->bearer($bad, 'GET', '/api/v1/pages/reports:mri:mioveni:a', raw: str_starts_with($bad, 'Basic'));
            self::assertSame(401, $response->status, $bad);
            self::assertSame('invalid_token', json_decode($response->body, true)['error']['code']);
            self::assertStringContainsString('Bearer', $response->headers['WWW-Authenticate']);
        }
    }

    public function testARevokedTokenOrADeactivatedAccountStopsAtOnce(): void
    {
        $token = $this->newToken('mihai', 'write');
        $id = explode('.', $token)[1];

        $store = new FlatFileUserStore($this->dataRoot);
        $store->save($store->find('mihai')->with(active: false));
        self::assertSame(401, $this->bearer($token, 'GET', '/api/v1/pages/reports:mri:mioveni:a')->status);
        $store->save($store->find('mihai')->with(active: true));
        self::assertSame(200, $this->bearer($token, 'GET', '/api/v1/pages/reports:mri:mioveni:a')->status);

        self::assertSame(302, $this->as('mihai', 'POST', '/profile/tokens/' . $id . '/revoke')->status);
        self::assertSame(401, $this->bearer($token, 'GET', '/api/v1/pages/reports:mri:mioveni:a')->status);
        self::assertSame(404, $this->as('owner', 'POST', '/profile/tokens/' . $id . '/revoke')->status, 'a token of someone else is not yours to revoke here');
    }

    public function testATokenIsIgnoredOnScreensAndWorksForExports(): void
    {
        $token = $this->newToken('mihai', 'read');

        $screen = $this->bearer($token, 'GET', '/reports:mri:mioveni:a');
        self::assertSame(404, $screen->status, 'a screen does not take a token: anonymous there, and the page is private');
        self::assertNotSame(401, $this->bearer($token, 'GET', '/export/reports:mri:mioveni:a.pdf')->status);
    }

    public function testLastUsedIsKeptToTheDay(): void
    {
        $token = $this->newToken('mihai', 'read');
        $this->bearer($token, 'GET', '/api/v1/pages/reports:mri:mioveni:a');

        self::assertSame(date('Y-m-d'), (new FlatFileUserStore($this->dataRoot))->find('mihai')->tokens[0]['last_used']);
    }

    public function testATokenNeedsANameAndAKnownScope(): void
    {
        self::assertSame(422, $this->as('mihai', 'POST', '/profile/tokens', ['name' => '', 'scope' => 'write'])->status);
        self::assertSame(422, $this->as('mihai', 'POST', '/profile/tokens', ['name' => 'x', 'scope' => 'admin'])->status);
        self::assertSame([], (new FlatFileUserStore($this->dataRoot))->find('mihai')->tokens);
    }

    public function testTheProfileEditsItsOwnSignatureDetails(): void
    {
        $response = $this->as('mihai', 'POST', '/profile/signature', ['display_name' => ' Dr. Mihai Test ', 'title' => 'Medic primar radiologie']);

        self::assertSame(302, $response->status);
        $account = (new FlatFileUserStore($this->dataRoot))->find('mihai');
        self::assertSame(['Dr. Mihai Test', 'Medic primar radiologie'], [$account->displayName, $account->title]);
        self::assertStringContainsString('value="Dr. Mihai Test"', $this->as('mihai', 'GET', '/profile')->body);
        self::assertStringContainsString('"profile.change"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
        self::assertSame(422, $this->as('mihai', 'POST', '/profile/signature', ['display_name' => str_repeat('x', 121)])->status);
    }

    private function newToken(string $username, string $scope): string
    {
        $response = $this->as($username, 'POST', '/profile/tokens', ['name' => 'script', 'scope' => $scope]);
        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertSame(1, preg_match('/id="new-token">([^<]+)</', $response->body, $m));

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /** @param array<string, mixed> $json */
    private function bearer(string $token, string $method, string $path, array $json = [], bool $raw = false): Response
    {
        return Kernel::boot($this->config)->handle(new Request($method, $path, body: $json === [] ? '' : (string) json_encode($json), authorization: $raw ? $token : 'Bearer ' . $token));
    }

    /** @param array<string, string> $fields */
    private function as(string $username, string $method, string $path, array $fields = []): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);

        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $cookie], body: http_build_query($fields)));
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
