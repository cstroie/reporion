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
use Reporion\Storage\FileTree;

/**
 * D65: the page header's Archive / Unarchive and POST
 * /api/v1/pages/{path}/archive|unarchive: a radiologist with the write
 * grant; a signed report never.
 */
final class ArchiveButtonTest extends HttpTestCase
{
    private const PATH = 'reports:mri:site:260101-test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $users->create('ana', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)]);
        $users->create('boss', 'x', true);
        $this->makeRadiologist('mihai');
        $this->makeRadiologist('ana');
    }

    public function testTheHeaderArchivesAndUnarchivesForARadiologistWithTheWriteGrant(): void
    {
        $this->storage()->create(self::PATH, ['modality' => 'MR'], 'Text.', 'owner');

        self::assertStringContainsString('action="/' . self::PATH . '/archive"', $this->as('mihai', 'GET', '/' . self::PATH)->body);
        self::assertStringNotContainsString('/archive"', $this->as('ana', 'GET', '/' . self::PATH)->body, 'a viewer');
        self::assertStringNotContainsString('/archive"', $this->as('boss', 'GET', '/' . self::PATH)->body, 'not a radiologist');

        self::assertSame(302, $this->as('mihai', 'POST', '/' . self::PATH . '/archive', 'base_rev=1')->status);
        self::assertSame('archived', $this->storage()->read(self::PATH)->status);
        $page = $this->as('mihai', 'GET', '/' . self::PATH)->body;
        self::assertStringContainsString('action="/' . self::PATH . '/unarchive"', $page);
        self::assertStringNotContainsString('href="/' . self::PATH . '/sign"', $page, 'an archived report is not signed here');

        $this->as('mihai', 'POST', '/' . self::PATH . '/unarchive', 'base_rev=1');
        self::assertSame('draft', $this->storage()->read(self::PATH)->status);
    }

    public function testTheApi(): void
    {
        $this->storage()->create(self::PATH, ['modality' => 'MR'], 'Text.', 'owner');
        $url = '/api/v1/pages/' . self::PATH . '/archive';

        self::assertSame(403, $this->as('boss', 'POST', $url, '{"base_rev":1}')->status);
        self::assertSame(404, $this->as('ana', 'POST', $url, '{"base_rev":1}')->status);
        self::assertSame(409, $this->as('mihai', 'POST', $url, '{"base_rev":5}')->status);
        $ok = $this->as('mihai', 'POST', $url, '{"base_rev":1}');
        self::assertSame(200, $ok->status);
        self::assertSame('archived', json_decode($ok->body, true)['status']);

        $this->as('mihai', 'POST', '/api/v1/pages/' . self::PATH . '/unarchive', '{"base_rev":1}');
        $this->storage()->sign(self::PATH, 'mihai', []);
        $signed = $this->as('mihai', 'POST', $url, '{"base_rev":1}');
        self::assertSame(409, $signed->status);
        self::assertStringContainsString('"signed"', $signed->body);
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    private function as(string $username, string $method, string $path, string $body = ''): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);

        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $cookie], body: $body));
    }
}
