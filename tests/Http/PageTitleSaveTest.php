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
 * The first `# ` heading is a page's title; the frontmatter `title` is only
 * an override (2026-10-13). A save never writes a title, a heading edited
 * later moves the listed title, and a text page (D40) has no headings.
 */
final class PageTitleSaveTest extends HttpTestCase
{
    private const PATH = 'docs:note';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $this->createPage(self::PATH, 'private', '', "Old body\n");
    }

    public function testACuratedSaveWithABlankTitleLeavesItBlankAndTheIndexListsTheHeading(): void
    {
        $response = $this->submit('/' . self::PATH . '/edit', [
            'body' => "Intro\n\n# Protocol RM genunchi\n\nText.\n",
            'fm' => ['title' => ''],
            'fm_shown' => ['title'],
            'base_rev' => 1,
        ]);

        self::assertSame(302, $response->status);
        self::assertArrayNotHasKey('title', $this->storage()->read(self::PATH)->frontmatter);
        self::assertSame('Protocol RM genunchi', $this->listedTitle(), 'listed by its heading');
    }

    public function testAHeadingEditedLaterMovesTheTitle(): void
    {
        $this->submit('/' . self::PATH . '/edit', ['body' => "# First\n", 'fm' => ['title' => ''], 'fm_shown' => ['title'], 'base_rev' => 1]);
        $this->submit('/' . self::PATH . '/edit', ['body' => "# Second\n", 'fm' => ['title' => ''], 'fm_shown' => ['title'], 'base_rev' => 2]);

        self::assertSame('Second', $this->listedTitle());
    }

    public function testATitleSetIsAnOverrideTheHeadingDoesNotMove(): void
    {
        $this->submit('/' . self::PATH . '/edit', [
            'body' => "# Another heading\n",
            'fm' => ['title' => 'Kept'],
            'fm_shown' => ['title'],
            'base_rev' => 1,
        ]);

        self::assertSame('Kept', $this->storage()->read(self::PATH)->frontmatter['title']);
        self::assertSame('Kept', $this->listedTitle());
    }

    public function testARawSaveWithNoTitleWritesNone(): void
    {
        $this->submit('/' . self::PATH . '/edit', [
            'document' => "---\nvisibility: private\n---\n\n# From raw\n",
            'base_rev' => 1,
        ], ['raw' => '1']);

        self::assertArrayNotHasKey('title', $this->storage()->read(self::PATH)->frontmatter);
        self::assertSame('From raw', $this->listedTitle());
    }

    public function testATextPageHasNoTitleFromItsText(): void
    {
        $this->submit('/' . self::PATH . '/edit', [
            'body' => "# looks like a heading\n",
            'fm' => ['title' => '', 'format' => 'text'],
            'fm_shown' => ['title', 'format'],
            'base_rev' => 1,
        ]);

        self::assertArrayNotHasKey('title', $this->storage()->read(self::PATH)->frontmatter);
        self::assertSame('', $this->listedTitle());
    }

    public function testTheApiWritesNoTitleEither(): void
    {
        $created = $this->api('POST', '/api/v1/pages', ['path' => 'docs:api-note', 'meta' => ['visibility' => 'private'], 'body' => "# Made by API\n"]);
        self::assertSame(201, $created->status);
        self::assertArrayNotHasKey('title', $this->storage()->read('docs:api-note')->frontmatter);

        $saved = $this->api('PUT', '/api/v1/pages/' . self::PATH, ['meta' => ['visibility' => 'private'], 'body' => "# Saved by API\n", 'base_rev' => 1]);
        self::assertSame(200, $saved->status);
        self::assertArrayNotHasKey('title', $this->storage()->read(self::PATH)->frontmatter);
        self::assertSame('Saved by API', $this->listedTitle());
    }

    private function listedTitle(): ?string
    {
        $row = (new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'))->findByPath(self::PATH, (new FlatFileUserStore($this->dataRoot))->find('owner'));

        return isset($row['title']) ? (string) $row['title'] : null;
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
