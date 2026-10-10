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
 * A page saved with no title takes its first `# ` heading (2026-10-10),
 * from the editor (curated and raw) and the API alike; a title already
 * there is never changed, and a text page (D40) has no headings to take.
 */
final class TitleFromHeadingSaveTest extends HttpTestCase
{
    private const PATH = 'docs:note';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $this->createPage(self::PATH, 'private', 'Old title', "Old body\n");
    }

    public function testACuratedSaveWithABlankTitleTakesTheHeading(): void
    {
        $response = $this->submit('/' . self::PATH . '/edit', [
            'body' => "Intro\n\n# Protocol RM genunchi\n\nText.\n",
            'fm' => ['title' => ''],
            'fm_shown' => ['title'],
            'base_rev' => 1,
        ]);

        self::assertSame(302, $response->status);
        self::assertSame('Protocol RM genunchi', $this->storage()->read(self::PATH)->frontmatter['title']);
        $row = (new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'))->findByPath(self::PATH, (new FlatFileUserStore($this->dataRoot))->find('owner'));
        self::assertSame('Protocol RM genunchi', $row['title'] ?? null, 'and the index lists it by it');
    }

    public function testATitleAlreadyThereIsKept(): void
    {
        $this->submit('/' . self::PATH . '/edit', [
            'body' => "# Another heading\n",
            'fm' => ['title' => 'Kept'],
            'fm_shown' => ['title'],
            'base_rev' => 1,
        ]);

        self::assertSame('Kept', $this->storage()->read(self::PATH)->frontmatter['title']);
    }

    public function testARawSaveWithNoTitleTakesTheHeading(): void
    {
        $this->submit('/' . self::PATH . '/edit', [
            'document' => "---\nvisibility: private\n---\n\n# From raw\n",
            'base_rev' => 1,
        ], ['raw' => '1']);

        self::assertSame('From raw', $this->storage()->read(self::PATH)->frontmatter['title']);
    }

    public function testATextPageKeepsItsTitleBlank(): void
    {
        $this->submit('/' . self::PATH . '/edit', [
            'body' => "# looks like a heading\n",
            'fm' => ['title' => '', 'format' => 'text'],
            'fm_shown' => ['title', 'format'],
            'base_rev' => 1,
        ]);

        self::assertArrayNotHasKey('title', $this->storage()->read(self::PATH)->frontmatter);
    }

    public function testTheApiFillsItOnCreateAndOnSave(): void
    {
        $created = $this->api('POST', '/api/v1/pages', ['path' => 'docs:api-note', 'meta' => ['visibility' => 'private'], 'body' => "# Made by API\n"]);
        self::assertSame(201, $created->status);
        self::assertSame('Made by API', $this->storage()->read('docs:api-note')->frontmatter['title']);

        $saved = $this->api('PUT', '/api/v1/pages/' . self::PATH, ['meta' => ['visibility' => 'private'], 'body' => "# Saved by API\n", 'base_rev' => 1]);
        self::assertSame(200, $saved->status);
        self::assertSame('Saved by API', $this->storage()->read(self::PATH)->frontmatter['title']);
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'));
    }

    private function cookie(): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');
    }

    /**
     * @param array<string, mixed>  $fields
     * @param array<string, string> $query
     */
    private function submit(string $path, array $fields, array $query = []): Response
    {
        return Kernel::boot($this->config)->handle(new Request('POST', $path, query: $query, cookies: ['reporion' => $this->cookie()], body: http_build_query($fields)));
    }

    /** @param array<string, mixed> $body */
    private function api(string $method, string $path, array $body): Response
    {
        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $this->cookie()], body: (string) json_encode($body)));
    }
}
