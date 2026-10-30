<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Cli\LibraryAddCommand;
use Reporion\Cli\Output;
use Reporion\Index\Sqlite;
use Reporion\Service\Library\Extractor;
use Reporion\Service\Library\Library;
use Reporion\Service\TempUploads;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;
use Reporion\Tests\Support\CnpTest;

/** bin/reporion library:add (docs/library.md); the documents are made up */
final class LibraryAddCommandTest extends StorageTestCase
{
    private FileTree $storage;
    private LibraryAddCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        $index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $index);
        $library = new Library($this->storage, $index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot));
        $this->command = new LibraryAddCommand($library, new AuditLog($this->dataRoot . '/audit'));
        mkdir($this->dataRoot . '/in');
        file_put_contents($this->dataRoot . '/in/study.md', "# A made-up study of test lesions\n\n© 2024 doi:10.1234/jti.2024.0001\n\nText.\n");
        file_put_contents($this->dataRoot . '/in/letter.txt', 'CNP ' . CnpTest::make(2, '750301') . "\n");
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function exec(string ...$args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $exit = $this->command->run($args, new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$exit, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testADryRunSaysWhatItWouldAddAndKeepsNothing(): void
    {
        [$exit, $out] = $this->exec($this->dataRoot . '/in/study.md', '--dry-run');

        self::assertSame(0, $exit);
        self::assertStringStartsWith('Reading 1 file(s) to library: (dry run, nothing kept)', $out);
        self::assertStringContainsString('would add madeup2024 "A made-up study of test lesions", 2024 doi:10.1234/jti.2024.0001', $out);
        self::assertFalse($this->storage->isPathTaken('library:madeup2024'));
        self::assertSame([], glob($this->dataRoot . '/sources/*') ?: []);
    }

    public function testFilesAreAddedWithTheirTagsAndAPatientDocumentIsSkipped(): void
    {
        [$exit, $out] = $this->exec($this->dataRoot . '/in/study.md', $this->dataRoot . '/in/letter.txt', $this->dataRoot . '/in/missing.pdf', '--tags=Grading,lesions', '--actor=ana');

        self::assertSame(1, $exit, 'a file was skipped');
        self::assertStringContainsString('added library:madeup2024', $out);
        self::assertStringContainsString('letter.txt: The file holds a personal numeric code', $out);
        self::assertStringContainsString('missing.pdf: The file cannot be read.', $out);
        $page = $this->storage->read('library:madeup2024');
        self::assertSame(['grading', 'lesions'], $page->frontmatter['tags']);
        self::assertStringContainsString('"action":"library.add"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));

        [$exit, $out] = $this->exec($this->dataRoot . '/in/study.md');
        self::assertSame(1, $exit);
        self::assertStringContainsString('in the library already', $out);
    }

    public function testJsonIsTheWholeStdout(): void
    {
        [$exit, $out] = $this->exec($this->dataRoot . '/in/study.md', '--json', '--key=study2024');

        self::assertSame(0, $exit);
        $json = json_decode($out, true);
        self::assertSame('library:study2024', $json['results'][0]['path']);
    }
}
