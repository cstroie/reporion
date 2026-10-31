<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Cli\LibraryRelookupCommand;
use Reporion\Cli\Output;
use Reporion\Index\Sqlite;
use Reporion\Service\Library\Extractor;
use Reporion\Service\Library\Library;
use Reporion\Service\Library\Lookup;
use Reporion\Service\TempUploads;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/** bin/reporion library:relookup (docs/library.md); the answers are made up */
final class LibraryRelookupCommandTest extends StorageTestCase
{
    private FileTree $storage;
    private Sqlite $index;
    /** @var list<string> */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        $owner = new User('owner', '', true, [], true, 'now', 'now');
        $this->library(false)->add("# A made-up study of test lesions\n\n© 2024 doi:10.1234/jti.2024.0001\n\nText.\n", 'study.md', $owner, 'owner', ['tags' => ['mine']]);
    }

    private function library(bool $lookup): Library
    {
        $fetch = function (string $url): ?string {
            $this->asked[] = $url;

            return str_contains($url, 'crossref')
                ? '{"message":{"type":"journal-article","title":["Looked-up made-up study"],"issued":{"date-parts":[[2023]]}}}'
                : '{"esearchresult":{"idlist":[]}}';
        };

        return new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot), 'library', 50 * 1024 * 1024, $lookup ? new Lookup($fetch) : null);
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function exec(bool $lookup, string ...$args): array
    {
        $command = new LibraryRelookupCommand($this->library($lookup), new AuditLog($this->dataRoot . '/audit'), 0);
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $exit = $command->run($args, new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$exit, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    private function title(): string
    {
        return (string) $this->storage->read('library:' . $this->key())->frontmatter['csl']['title'];
    }

    private function key(): string
    {
        return substr((string) ($this->index->listNamespaceMeta('library', new User('o', '', true, [], true, 'now', 'now'))[0]['path'] ?? ''), \strlen('library:'));
    }

    public function testItNeedsASelectionAndTheLookupsOn(): void
    {
        [$exit, , $err] = $this->exec(true);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Usage', $err);

        [$exit, , $err] = $this->exec(false, '--all', '--yes');
        self::assertSame(1, $exit);
        self::assertStringContainsString('lookups are off', $err);
        self::assertSame([], $this->asked);
    }

    public function testItWarnsAndWritesNothingWithoutYes(): void
    {
        [$exit, $out, $err] = $this->exec(true, '--all');

        self::assertSame(1, $exit);
        self::assertStringStartsWith('Looking up and overwriting 1 source(s) of library:', $out);
        self::assertStringContainsString('OVERWRITES', $err);
        self::assertSame([], $this->asked, 'nothing asked');
        self::assertSame('A made-up study of test lesions', $this->title());
    }

    public function testADryRunAsksAndShowsWhatWouldChange(): void
    {
        [$exit, $out] = $this->exec(true, '--all', '--dry-run');

        self::assertSame(0, $exit);
        self::assertStringContainsString('(dry run, nothing written)', $out);
        self::assertStringContainsString('would update ' . $this->key() . ': ', $out);
        self::assertStringContainsString('title', $out);
        self::assertNotSame([], $this->asked);
        self::assertSame('A made-up study of test lesions', $this->title());
    }

    public function testYesOverwritesTheRecordAndAuditsIt(): void
    {
        [$exit, $out] = $this->exec(true, $this->key(), '--yes', '--actor=tester');

        self::assertSame(0, $exit);
        self::assertStringContainsString('updated ' . $this->key() . ': ', $out);
        self::assertSame('Looked-up made-up study', $this->title());
        foreach ($this->asked as $url) {
            self::assertStringNotContainsString('made-up', $url, 'the identifier only');
        }
        self::assertStringContainsString('"lookup"', (string) file_get_contents(glob($this->dataRoot . '/audit/*')[0] ?? ''));

        [, $again] = $this->exec(true, $this->key(), '--yes');
        self::assertStringContainsString('unchanged ' . $this->key(), $again);
    }

    public function testAKeyThatIsNoSourceIsSaidAndFails(): void
    {
        [$exit, $out] = $this->exec(true, 'nope2020', '--yes');

        self::assertSame(1, $exit);
        self::assertStringContainsString('skipped nope2020: not a source of the library', $out);
    }
}
