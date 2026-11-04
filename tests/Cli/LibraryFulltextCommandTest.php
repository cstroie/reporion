<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Cli\LibraryFulltextCommand;
use Reporion\Cli\Output;
use Reporion\Index\Sqlite;
use Reporion\Service\Library\Extractor;
use Reporion\Service\Library\Library;
use Reporion\Service\Library\Lookup;
use Reporion\Service\Library\WebClip;
use Reporion\Service\TempUploads;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/** bin/reporion library:fulltext (docs/library.md, D60); nothing leaves the test */
final class LibraryFulltextCommandTest extends StorageTestCase
{
    private FileTree $storage;
    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        foreach (['a2020' => '10.9999/a', 'b2020' => '10.9999/b'] as $key => $doi) {
            $this->storage->create('library:' . $key, ['title' => 'Made-up ' . $key, 'visibility' => 'private', 'csl' => ['title' => 'Made-up ' . $key, 'DOI' => $doi]], '', 'cli');
        }
        $this->storage->create('library:nodoi', ['title' => 'No DOI', 'visibility' => 'private', 'csl' => ['title' => 'No DOI']], '', 'cli');
    }

    private function library(bool $on = true): Library
    {
        $lookup = new Lookup(static fn (string $url): ?string => str_contains($url, 'unpaywall') && str_contains($url, '10.9999%2Fa')
            ? '{"best_oa_location":{"url_for_pdf":"https://repo.example.org/a.pdf"}}' : '');
        $clip = new WebClip(1024 * 1024, static fn (string $host): array => ['93.184.216.34'], static fn (string $url, string $address, string $host, int $max): array => ['status' => 200, 'headers' => ['Content-Type: application/pdf'], 'body' => "%PDF-1.4\nmade-up"]);
        $runner = static function (array $argv, int $timeout, array $env): array {
            if (basename($argv[0]) === 'pdftotext') {
                file_put_contents((string) end($argv), "Made-up a2020\n\nDOI 10.9999/a\n\n" . str_repeat('The text of the article, long enough to be a text. ', 8));

                return [0, ''];
            }

            return [0, basename($argv[0]) === 'pdfinfo' && !\in_array('-meta', $argv, true) ? "Pages: 1\n" : ''];
        };

        return new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp', runner: $runner), new TempUploads($this->dataRoot), 'library', 50 * 1024 * 1024, $on ? $lookup : null, null, $clip, 'library@example.org');
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function exec(Library $library, string ...$args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $exit = (new LibraryFulltextCommand($library, new AuditLog($this->dataRoot . '/audit'), 0))->run($args, new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$exit, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testSourcesWithADoiAndNoFileAreTriedAndTheOneUnpaywallKnowsGetsItsPdf(): void
    {
        [$exit, $out] = $this->exec($this->library(), '--dry-run');
        self::assertSame(0, $exit);
        self::assertStringStartsWith('Looking for the free PDF of 2 source(s) of library: (dry run, nothing kept)', $out);
        self::assertArrayNotHasKey('original', $this->storage->read('library:a2020')->frontmatter);

        [$exit, $out] = $this->exec($this->library());
        self::assertSame(0, $exit);
        self::assertStringContainsString('a2020: attached (repo.example.org)', $out);
        self::assertStringContainsString('b2020: none', $out);
        self::assertSame('pdf', $this->storage->read('library:a2020')->frontmatter['original']['ext']);
        self::assertStringContainsString('"action":"library.fulltext"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));

        [, $out] = $this->exec($this->library(), '--json');
        self::assertSame(['b2020'], array_column(json_decode($out, true)['results'], 'key'), 'the one with a file is left out');
    }

    public function testItNeedsTheFeatureSetUpAndKnowsOnlyItsOptions(): void
    {
        [$exit, , $err] = $this->exec(new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot)));
        self::assertSame(1, $exit);
        self::assertStringContainsString('not set up', $err);
        self::assertSame(1, $this->exec($this->library(), '--nope')[0]);
    }
}
