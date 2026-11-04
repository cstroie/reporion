<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Cli\LibraryDuplicatesCommand;
use Reporion\Cli\LibraryMergeCommand;
use Reporion\Cli\Output;
use Reporion\Index\Sqlite;
use Reporion\Service\Library\Analyses;
use Reporion\Service\Library\Extractor;
use Reporion\Service\Library\Library;
use Reporion\Service\Library\Merge;
use Reporion\Service\PageMoves;
use Reporion\Service\TempUploads;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/** bin/reporion library:duplicates and library:merge (docs/library.md, D60); the sources are made up */
final class LibraryMergeCommandTest extends StorageTestCase
{
    private FileTree $storage;
    private Library $library;
    private Merge $merge;

    protected function setUp(): void
    {
        parent::setUp();
        $index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $index);
        $this->library = new Library($this->storage, $index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot));
        $audit = new AuditLog($this->dataRoot . '/audit');
        $analyses = new Analyses($this->storage, $index);
        $this->merge = new Merge($this->library, $this->storage, $audit, new PageMoves($this->storage, $audit, 'library', $analyses), $analyses);
        $csl = ['title' => 'A made-up study of lesions', 'DOI' => '10.9999/x', 'issued' => ['date-parts' => [[2020]]]];
        $this->storage->create('library:keep2020', ['title' => 'A', 'visibility' => 'private', 'csl' => $csl], '', 'cli');
        $this->storage->create('library:dup2020', ['title' => 'A', 'visibility' => 'private', 'csl' => $csl + ['PMID' => '9']], '', 'cli');
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function exec(object $command, string ...$args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $exit = $command->run($args, new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$exit, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testDuplicatesAreListedAndMergedAndNothingIsWrittenByADryRun(): void
    {
        [$exit, $out] = $this->exec(new LibraryDuplicatesCommand($this->library));
        self::assertSame(0, $exit);
        self::assertStringStartsWith('Looking for duplicates in library: 1 group(s)', $out);
        self::assertStringContainsString('same doi', $out);

        $command = new LibraryMergeCommand($this->merge, $this->library);
        [$exit, $out] = $this->exec($command, 'keep2020', 'dup2020', '--dry-run');
        self::assertSame(0, $exit);
        self::assertStringStartsWith('Checking the merge of dup2020 into keep2020 (dry run, nothing written)', $out);
        self::assertSame('9', $this->storage->read('library:dup2020')->frontmatter['csl']['PMID'], 'still there');

        [$exit, $out] = $this->exec($command, 'keep2020', 'dup2020');
        self::assertSame(0, $exit);
        self::assertStringStartsWith('Merging dup2020 into keep2020', $out);
        self::assertSame('9', $this->storage->read('library:keep2020')->frontmatter['csl']['PMID']);
        self::assertSame('library:keep2020', $this->storage->redirectTarget('library:dup2020'));
        [, $out] = $this->exec(new LibraryDuplicatesCommand($this->library), '--json');
        self::assertSame([], json_decode($out, true)['groups']);
    }

    public function testRefusalsAreSaid(): void
    {
        $command = new LibraryMergeCommand($this->merge, $this->library);

        self::assertSame(1, $this->exec($command, 'keep2020')[0]);
        [$exit, , $err] = $this->exec($command, 'keep2020', 'nothere');
        self::assertSame(1, $exit);
        self::assertStringContainsString('both must be sources', $err);
        self::assertSame(1, $this->exec($command, 'keep2020', 'keep2020')[0]);
        self::assertSame(1, $this->exec($command, 'keep2020', 'dup2020', '--nope')[0]);
    }
}
