<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use Reporion\Exception\PageNotFoundException;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;

/**
 * Crash tests that crash (CLAUDE.md "Storage"): a write runs in a process of
 * its own (crash-child.php) that is SIGKILLed just before its Nth rename()
 * or link() — the rev file, current.md, meta.json in turn — and journal
 * replay must then leave either the old page or the whole new one. The
 * hand-built crash states in FlatFileTest stay as the fast cases; these
 * follow the write order the code really has.
 */
final class RealCrashTest extends StorageTestCase
{
    private const PATH = 'docs:a';

    protected function setUp(): void
    {
        if (!\function_exists('posix_kill') && !\extension_loaded('posix')) {
            self::markTestSkipped('the posix extension is not loaded');
        }
        parent::setUp();
    }

    /** @return iterable<string, array{0: string, 1: int, 2: ?int, 3: string}> op, kill before step, rev after replay (null: no page), body */
    public static function crashPoints(): iterable
    {
        yield 'create, before the rev file' => ['create', 1, null, ''];
        yield 'create, before current.md' => ['create', 2, 1, "first body\n"];
        yield 'create, before meta.json' => ['create', 3, 1, "first body\n"];
        yield 'create, not killed' => ['create', 99, 1, "first body\n"];
        yield 'save, before the rev file' => ['save', 1, 1, "first body\n"];
        yield 'save, before current.md' => ['save', 2, 2, "second body\n"];
        yield 'save, before meta.json' => ['save', 3, 2, "second body\n"];
        yield 'save, not killed' => ['save', 99, 2, "second body\n"];
    }

    #[DataProvider('crashPoints')]
    public function testAKilledWriteIsReplayedToTheOldPageOrTheWholeNewOne(string $op, int $killBefore, ?int $rev, string $body): void
    {
        if ($op === 'save') {
            $this->storage()->create(self::PATH, ['title' => 'Crash', 'visibility' => 'private'], "first body\n", 'owner');
        }

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/crash-child.php') . ' ' . escapeshellarg($this->dataRoot) . ' ' . $op . ' ' . self::PATH . ' ' . $killBefore . ' 2>&1', $out, $code);
        self::assertSame($killBefore === 99 ? 0 : 137, $code, 'killed where asked: ' . implode(' ', $out));

        $storage = $this->storage();
        $storage->replayJournal(0);
        self::assertSame([], $storage->replayJournal(0), 'no intent left open');

        if ($rev === null) {
            try {
                $storage->read(self::PATH);
                self::fail('a create killed before its revision must leave no page');
            } catch (PageNotFoundException) {
            }

            return;
        }
        $page = $storage->read(self::PATH);
        self::assertSame($rev, $page->rev);
        self::assertSame($body, $page->body);

        $dir = $this->dataRoot . '/pages/docs/a';
        $meta = json_decode((string) file_get_contents($dir . '/meta.json'), true);
        self::assertSame($rev, $meta['rev'], 'meta.json names the revision read() gave');
        self::assertCount($rev, $meta['revlog']);
        self::assertSame(
            (string) file_get_contents($dir . '/current.md'),
            gzdecode((string) file_get_contents(sprintf('%s/rev/%04d.md.gz', $dir, $rev))),
            'current.md is the newest revision, byte for byte (D2)',
        );
        self::assertFileDoesNotExist(sprintf('%s/rev/%04d.md.gz', $dir, $rev + 1), 'no revision past the one meta.json names');
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'));
    }
}
