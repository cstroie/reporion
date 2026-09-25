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
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\RecordingIndex;

/**
 * GET /admin/index and POST /admin/index/rebuild (owner-only).
 */
final class AdminIndexTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
    }

    public function testOnlyAnOwnerReachesIt(): void
    {
        self::assertSame(200, $this->as('owner', 'GET', '/admin/index')->status);
        self::assertSame(404, $this->as('mihai', 'GET', '/admin/index')->status);
        self::assertSame(404, $this->as('mihai', 'POST', '/admin/index/rebuild')->status);
        self::assertSame(404, Kernel::boot($this->config)->handle(new Request('GET', '/admin/index'))->status);
    }

    public function testDriftIsReportedAndARebuildFixesItAndIsAudited(): void
    {
        $this->createPage('reports:mri:mioveni:indexed', 'private', 'Indexed', 'body');
        // Written to disk without reaching the index — what a failed index
        // update after a successful write leaves behind
        (new FlatFile($this->dataRoot, new RecordingIndex()))->create('reports:mri:mioveni:260101-not-indexed', ['title' => 'Unindexed', 'visibility' => 'private'], 'body', 'owner');

        $before = $this->as('owner', 'GET', '/admin/index');
        self::assertStringContainsString(t('admin.index.drift_counts', [1, 0, 0]), $before->body);
        self::assertStringNotContainsString('not-indexed', $before->body, 'counts only, never a page path');

        $rebuild = $this->as('owner', 'POST', '/admin/index/rebuild');
        self::assertSame(302, $rebuild->status);
        self::assertSame('/admin/index?rebuilt=2', $rebuild->headers['Location']);

        $after = $this->as('owner', 'GET', '/admin/index', ['rebuilt' => '2']);
        self::assertStringContainsString(t('admin.index.clean'), $after->body);
        self::assertStringContainsString(t('admin.index.rebuilt', [2]), $after->body);
        self::assertStringContainsString('"action":"index.rebuild"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
    }

    /** @param array<string, string> $query */
    private function as(string $username, string $method, string $path, array $query = []): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);

        return Kernel::boot($this->config)->handle(new Request($method, $path, query: $query, cookies: ['reporion' => $cookie]));
    }
}
