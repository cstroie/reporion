<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use DOMDocument;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Plugin\Loader;
use Reporion\Storage\FileTree;
use Reporion\Support\Zip;
use Reporion\Tests\Http\HttpTestCase;

/**
 * plugins/epub: a namespace as a book, through the real Kernel and routes.
 * Names are made up (invariant 10).
 */
final class EpubExportTest extends HttpTestCase
{
    private const BOOK = 'docs:handbook';

    protected function setUp(): void
    {
        parent::setUp();
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'epub');
        $this->config['plugins'] = ['enabled' => ['epub'], 'settings' => ['epub' => ['language' => 'en']]];
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true, [], 'Dr. Test Owner');
        $users->create('viewer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('docs', GrantRole::Viewer)]);
        $users->create('outsider', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('other', GrantRole::Editor)]);
        $s = $this->storage();
        $s->create(self::BOOK, ['visibility' => 'private', 'sort' => 'title'], "# The Handbook\n\nWelcome & enjoy.\n", 'owner');
        $s->create(self::BOOK . ':b-second', ['visibility' => 'private'], "# Second chapter\n\nSee [other](docs:elsewhere) and ![pic](media:" . str_repeat('a', 64) . ".png).\n\n| a | b |\n|---|---|\n| 1 | 2 |\n", 'owner');
        $s->create(self::BOOK . ':a-first', ['visibility' => 'private'], "# First chapter\n\nÎntâi, ș ț ă.\n\n## Part\n\nText.\n", 'owner');
    }

    public function testTheNamespaceBecomesABookInSortOrder(): void
    {
        $response = $this->get('owner', '/x/epub/' . $this->pid(self::BOOK));

        self::assertSame(200, $response->status);
        self::assertSame('application/epub+zip', $response->headers['Content-Type']);
        self::assertSame('attachment; filename="docs-handbook.epub"', $response->headers['Content-Disposition']);

        $entries = Zip::read($response->body);
        self::assertSame('mimetype', array_key_first($entries), 'mimetype is the first entry');
        self::assertSame('application/epub+zip', $entries['mimetype']->bytes());
        foreach ($entries as $name => $entry) {
            if (str_ends_with($name, '.xhtml') || str_ends_with($name, '.opf') || str_ends_with($name, '.ncx') || str_ends_with($name, '.xml')) {
                $dom = new DOMDocument();
                self::assertTrue($dom->loadXML($entry->bytes()), $name . ' is well-formed XML');
            }
        }
        self::assertSame(['front', 'ch001', 'ch002'], array_map(static fn (string $f): string => basename($f, '.xhtml'), array_values(array_filter(array_keys($entries), static fn (string $n): bool => preg_match('~OEBPS/(front|ch\d+)\.xhtml$~', $n) === 1))));
        self::assertStringContainsString('<h1>The Handbook</h1>', $entries['OEBPS/front.xhtml']->bytes());
        self::assertStringContainsString('Welcome &amp; enjoy.', $entries['OEBPS/front.xhtml']->bytes());
        self::assertStringContainsString('First chapter', $entries['OEBPS/ch001.xhtml']->bytes());
        self::assertSame(1, substr_count($entries['OEBPS/ch001.xhtml']->bytes(), 'First chapter</h1>'), 'the title heading is not repeated');
        self::assertStringContainsString('Întâi, ș ț ă.', $entries['OEBPS/ch001.xhtml']->bytes());
        $second = $entries['OEBPS/ch002.xhtml']->bytes();
        self::assertStringContainsString('<table', $second);
        self::assertStringNotContainsString('<img', $second, 'no media is embedded');
        self::assertStringNotContainsString('docs:elsewhere', $second, 'a page link has no address in the book');
        self::assertStringContainsString('<dc:title>The Handbook</dc:title>', $entries['OEBPS/content.opf']->bytes());
        self::assertStringNotContainsString('handbook', $entries['OEBPS/content.opf']->bytes(), 'the address names the file only (invariant 8)');

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"format":"epub"', $audit);
        self::assertStringNotContainsString(self::BOOK, $audit);
    }

    public function testTheIndexPageOfANamespaceIsTheSameBook(): void
    {
        $this->storage()->create(self::BOOK . ':_index', ['visibility' => 'private'], "# About\n\nIntro.\n", 'owner');
        $entries = Zip::read($this->get('owner', '/x/epub/' . $this->pid(self::BOOK . ':_index'))->body);

        self::assertStringContainsString('<h1>About</h1>', $entries['OEBPS/front.xhtml']->bytes());
        self::assertArrayHasKey('OEBPS/ch002.xhtml', $entries);
        self::assertArrayNotHasKey('OEBPS/ch003.xhtml', $entries, 'the description page is not a chapter');
    }

    public function testOnlyChaptersTheReaderMaySeeAndNeverPatientPages(): void
    {
        $s = $this->storage();
        $s->create(self::BOOK . ':c-public', ['visibility' => 'public'], "# Third\n\nOpen.\n", 'owner');
        $s->create(self::BOOK . ':d-patient', ['visibility' => 'private', 'patient' => ['name' => 'TEST PATIENT']], "# Hidden\n\nText.\n", 'owner');

        $viewer = Zip::read($this->get('viewer', '/x/epub/' . $this->pid(self::BOOK))->body);
        self::assertArrayHasKey('OEBPS/ch003.xhtml', $viewer);
        self::assertArrayNotHasKey('OEBPS/ch004.xhtml', $viewer, 'the patient page is left out');
        foreach ($viewer as $name => $entry) {
            self::assertStringNotContainsString('TEST PATIENT', $entry->bytes(), $name);
        }
        self::assertSame(404, $this->get('outsider', '/x/epub/' . $this->pid(self::BOOK))->status, 'no grant (invariant 6)');
        self::assertSame(404, $this->get('owner', '/x/epub/' . $this->pid(self::BOOK . ':d-patient'))->status);
    }

    public function testAnonymousUnknownAndChapterlessAreNotFound(): void
    {
        self::assertSame(404, $this->anonymous('/x/epub/' . $this->pid(self::BOOK))->status);
        self::assertSame(404, $this->get('owner', '/x/epub/01NOSUCHPIDXXXXXXXXXXXXXXX')->status);
        self::assertSame(404, $this->get('owner', '/x/epub/' . $this->pid(self::BOOK . ':a-first'))->status, 'a page with nothing under it');
    }

    private function pid(string $path): string
    {
        return $this->storage()->read($path)->pid;
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    private function get(string $user, string $path): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user);

        return Kernel::boot($this->config)->handle(new Request('GET', $path, cookies: ['reporion' => $cookie]));
    }

    private function anonymous(string $path): Response
    {
        return Kernel::boot($this->config)->handle(new Request('GET', $path));
    }
}
