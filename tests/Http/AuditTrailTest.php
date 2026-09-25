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
 * Every write, sign and login leaves a line in data/audit/YYYY-MM.ndjson
 * (docs/FORMATS.md §6), and the page path never appears in the file —
 * only its hash (invariant 8).
 */
final class AuditTrailTest extends HttpTestCase
{
    private const PATH = 'reports:mri:mioveni:260923-audit-subject';

    public function testAPageLifecycleIsAuditedWithoutThePath(): void
    {
        $this->createOwner();
        $this->api('POST', '/api/v1/pages', [
            'path' => self::PATH,
            'meta' => [
                'title' => 'RM cerebral nativ', 'visibility' => 'private', 'modality' => ['MR'],
                'region' => ['neuro'], 'site' => 'mioveni', 'study_date' => '2026-09-23',
                'summary' => 'Fara leziuni active.', 'indication' => 'Cefalee cronica.',
                'patient' => ['name' => 'Test Patient'],
            ],
            'body' => 'v1',
        ]);
        $this->api('PUT', '/api/v1/pages/' . self::PATH, [
            'meta' => [
                'title' => 'RM cerebral nativ', 'visibility' => 'private', 'modality' => ['MR'],
                'region' => ['neuro'], 'site' => 'mioveni', 'study_date' => '2026-09-23',
                'summary' => 'Fara leziuni active.', 'indication' => 'Cefalee cronica.',
                'patient' => ['name' => 'Test Patient'],
            ],
            'body' => 'v2',
            'base_rev' => 1,
        ]);
        $this->api('POST', '/api/v1/pages/' . self::PATH . '/sign', ['parafa' => 'P-1']);
        $this->api('POST', '/api/v1/pages/' . self::PATH . '/revert', ['to' => 1]);
        $this->api('DELETE', '/api/v1/pages/' . self::PATH, []);

        $lines = $this->auditLines();
        self::assertSame(
            ['page.create', 'page.save', 'page.sign', 'page.revert', 'page.delete'],
            array_column($lines, 'action')
        );
        foreach ($lines as $line) {
            self::assertSame('owner', $line['actor']);
            self::assertSame('sha256:' . hash('sha256', self::PATH), $line['path_hash']);
        }
        self::assertSame([1, 2, 2, 3, 3], array_column($lines, 'rev'));
        self::assertStringNotContainsString('audit-subject', $this->auditRaw());
    }

    public function testLoginsAreAuditedAndAPasswordInTheUsernameFieldIsNot(): void
    {
        $this->createOwner('owner', 'correct-horse');

        $this->login('owner', 'correct-horse');
        $this->login('owner', 'wrong');
        $this->login('Correct Horse Battery!', 'x');

        $lines = $this->auditLines();
        self::assertSame(['login', 'login.fail', 'login.fail'], array_column($lines, 'action'));
        self::assertSame(['owner', 'owner', '(invalid)'], array_column($lines, 'actor'));
        self::assertStringNotContainsString('Correct Horse', $this->auditRaw());
    }

    /** @param array<string, mixed> $body */
    private function api(string $method, string $path, array $body): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $cookie], body: (string) json_encode($body)));
    }

    private function login(string $username, string $password): Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/login',
            body: http_build_query(['username' => $username, 'password' => $password]),
            remoteAddr: '10.0.0.9',
        ));
    }

    private function auditRaw(): string
    {
        return (string) @file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
    }

    /** @return list<array<string, mixed>> */
    private function auditLines(): array
    {
        return array_map(
            static fn (string $raw): array => json_decode($raw, true),
            array_values(array_filter(explode("\n", $this->auditRaw())))
        );
    }
}
