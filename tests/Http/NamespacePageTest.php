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
 * A page named like its namespace (decided 2026-09-26): a site's
 * description, `reports:mri:mioveni`, beside the reports under
 * `reports:mri:mioveni:*` — created at that exact path, shown as the
 * namespace index's description, never a report, and never deleted with
 * the reports under it.
 */
final class NamespacePageTest extends HttpTestCase
{
    private const SITE = 'reports:mri:mioveni';
    private const REPORT = 'reports:mri:mioveni:260920-popescu-ana';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $this->storage()->create(self::REPORT, ['title' => 'Popescu Ana', 'visibility' => 'private'], 'Raport.', 'owner');
    }

    public function testTheSitePageIsCreatedAtItsOwnPathBesideItsReports(): void
    {
        $created = $this->as('POST', '/new', http_build_query([
            'path' => self::SITE,
            'document' => "---\ntitle: Mioveni\nvisibility: private\n---\n\nSpitalul din Mioveni.\n",
        ]));

        self::assertSame(302, $created->status);
        self::assertSame('/' . self::SITE . '/edit', $created->headers['Location'], 'the exact path, not reports:mri:mioveni-2');
        $page = $this->as('GET', '/' . self::SITE);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Spitalul din Mioveni.', $page->body);
        self::assertStringNotContainsString('/' . self::SITE . '/sign"', $page->body, 'a site page is not a report');
        self::assertSame(200, $this->as('GET', '/' . self::REPORT)->status);
    }

    public function testTheNamespaceIndexShowsItAsTheDescription(): void
    {
        $empty = $this->as('GET', '/' . self::SITE . ':');
        self::assertStringContainsString('/new?path=' . rawurlencode(self::SITE), $empty->body);

        $this->storage()->create(self::SITE, ['title' => 'Mioveni', 'visibility' => 'private'], 'Spitalul din Mioveni.', 'owner');
        $index = $this->as('GET', '/' . self::SITE . ':');

        self::assertSame(200, $index->status);
        self::assertStringContainsString('Spitalul din Mioveni.', $index->body);
        self::assertStringContainsString('href="/' . self::SITE . '/edit"', $index->body);
    }

    public function testDeletingItLeavesTheReportsAndSaysWhy(): void
    {
        $this->storage()->create(self::SITE, ['title' => 'Mioveni', 'visibility' => 'private'], 'Site.', 'owner');

        $form = $this->as('POST', '/' . self::SITE . '/delete');
        self::assertSame(409, $form->status);
        self::assertStringContainsString(htmlspecialchars(t('page.delete_has_children'), ENT_QUOTES), $form->body);

        $api = $this->as('DELETE', '/api/v1/pages/' . self::SITE);
        self::assertSame(409, $api->status);
        self::assertSame('has_children', json_decode($api->body, true)['error']['code']);

        self::assertSame(200, $this->as('GET', '/' . self::SITE)->status);
        self::assertSame(200, $this->as('GET', '/' . self::REPORT)->status);
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    private function as(string $method, string $path, string $body = ''): Response
    {
        $cookie = (new Session(
            (string) $this->config['auth']['session_secret'],
            (string) $this->config['auth']['session_name'],
            (int) $this->config['auth']['session_lifetime'],
            new FlatFileUserStore($this->dataRoot),
        ))->issue('owner');

        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: [(string) $this->config['auth']['session_name'] => $cookie], body: $body));
    }
}
