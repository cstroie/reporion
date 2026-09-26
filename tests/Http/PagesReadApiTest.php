<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * GET /api/v1/pages and GET /api/v1/pages/{path}.
 */
final class PagesReadApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $s = new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
        $s->create('reports:mri:mioveni:a', ['title' => 'MR private', 'visibility' => 'private', 'modality' => ['MR'], 'site' => 'mioveni', 'patient' => ['name' => 'TEST PATIENT']], 'a', 'owner');
        $s->create('reports:ct:mioveni:b', ['title' => 'CT public', 'visibility' => 'public', 'modality' => ['CT'], 'site' => 'mioveni', 'patient' => ['name' => 'OTHER PATIENT']], 'b', 'owner');
        $s->create('reports:mrix:c', ['title' => 'Lookalike ns', 'visibility' => 'private'], 'c', 'owner');
        $s->create('docs:guide', ['title' => 'Guide', 'visibility' => 'public'], 'g', 'owner');
    }

    public function testAnonymousListsPublicPagesOnly(): void
    {
        $data = $this->json(null, '/api/v1/pages')['data'];

        $titles = array_column($data, 'title');
        sort($titles);
        self::assertSame(['CT public', 'Guide'], $titles);
    }

    public function testFiltersAndPaging(): void
    {
        self::assertSame(['MR private'], array_column($this->json('owner', '/api/v1/pages', ['modality' => 'MR'])['data'], 'title'));
        self::assertSame(['MR private'], array_column($this->json('owner', '/api/v1/pages', ['ns' => 'reports:mri'])['data'], 'title'), 'ns covers sub-namespaces, not lookalike prefixes');
        self::assertCount(2, $this->json('owner', '/api/v1/pages', ['site' => 'mioveni'])['data']);

        $first = $this->json('owner', '/api/v1/pages', ['limit' => '3']);
        self::assertCount(3, $first['data']);
        self::assertSame(3, $first['page']['next']);
        $second = $this->json('owner', '/api/v1/pages', ['limit' => '3', 'offset' => '3']);
        self::assertCount(1, $second['data']);
        self::assertNull($second['page']['next']);
    }

    public function testOnePageWithMetaBodyAndHtml(): void
    {
        $page = $this->json('owner', '/api/v1/pages/reports:mri:mioveni:a');

        self::assertSame('MR private', $page['meta']['title']);
        self::assertSame('TEST PATIENT', $page['meta']['patient']['name']);
        self::assertSame("a\n", $page['body']);
        self::assertSame("<p>a</p>\n", $page['html']);
        self::assertArrayNotHasKey('html', $this->json('owner', '/api/v1/pages/reports:mri:mioveni:a', ['render' => '0']));
    }

    public function testAnonymousGets404ForPrivateAndNoPatientBlockForPublic(): void
    {
        self::assertSame(404, $this->request(null, '/api/v1/pages/reports:mri:mioveni:a')->status);

        $public = $this->json(null, '/api/v1/pages/reports:ct:mioveni:b');
        self::assertArrayNotHasKey('patient', $public['meta']);
    }

    /** @param array<string, string> $query */
    private function request(?string $username, string $path, array $query = []): Response
    {
        $cookies = $username === null ? [] : ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username)];

        return Kernel::boot($this->config)->handle(new Request('GET', $path, query: $query, cookies: $cookies));
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function json(?string $username, string $path, array $query = []): array
    {
        $response = $this->request($username, $path, $query);
        self::assertSame(200, $response->status, $path);

        return json_decode($response->body, true);
    }
}
