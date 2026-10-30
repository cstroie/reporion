<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Cli\LibraryExportCommand;
use Reporion\Cli\LibraryImportCommand;
use Reporion\Cli\Output;
use Reporion\Index\Sqlite;
use Reporion\Service\Library\Extractor;
use Reporion\Service\Library\Library;
use Reporion\Service\TempUploads;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/** bin/reporion library:import and library:export (docs/library.md); the records are made up */
final class LibraryImportExportCommandTest extends StorageTestCase
{
    private FileTree $storage;
    private LibraryImportCommand $import;
    private LibraryExportCommand $export;

    protected function setUp(): void
    {
        parent::setUp();
        $index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $index);
        $library = new Library($this->storage, $index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot));
        $this->import = new LibraryImportCommand($library, new AuditLog($this->dataRoot . '/audit'));
        $this->export = new LibraryExportCommand($library);
        file_put_contents($this->dataRoot . '/refs.bib', "@article{Abel2020, author = {Abel, Zed}, title = {A made-up study}, journal = {J Synth}, year = {2020}, keywords = {mri}}\n");
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

    public function testADryRunKeepsNothingAndTheRealRunAddsTheRecord(): void
    {
        [$exit, $out] = $this->exec($this->import, $this->dataRoot . '/refs.bib', '--dry-run');
        self::assertSame(0, $exit);
        self::assertStringStartsWith('Reading 1 file(s) into library: (dry run, nothing kept)', $out);
        self::assertFalse($this->storage->isPathTaken('library:abel2020'));

        [$exit, $out] = $this->exec($this->import, $this->dataRoot . '/refs.bib', '--tags=batch');
        self::assertSame(0, $exit);
        self::assertStringStartsWith('Importing 1 file(s) into library:', $out);
        self::assertStringContainsString('library:abel2020 "A made-up study"', $out);
        self::assertSame(['mri', 'batch'], $this->storage->read('library:abel2020')->frontmatter['tags']);
        self::assertStringContainsString('"action":"library.import"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
    }

    public function testNoFileOrNoBibliographyIsAnError(): void
    {
        self::assertSame(1, $this->exec($this->import)[0]);
        file_put_contents($this->dataRoot . '/notes.txt', 'plain text');
        [$exit, $out] = $this->exec($this->import, $this->dataRoot . '/notes.txt', $this->dataRoot . '/missing.bib');
        self::assertSame(1, $exit);
        self::assertStringContainsString('cannot read the file', $out);
    }

    public function testTheExportIsTheLibraryInTheFormatAskedAndNeverOverwritesAFile(): void
    {
        $this->exec($this->import, $this->dataRoot . '/refs.bib');

        [$exit, $out] = $this->exec($this->export, '--format=ris');
        self::assertSame(0, $exit);
        self::assertStringStartsWith('Exporting 1 reference(s) as ris', $out);
        self::assertStringContainsString('TY  - JOUR', $out);
        self::assertStringContainsString('Abel, Z. (2020). A made-up study.', $this->exec($this->export, '--format=text', '--style=apa')[1]);
        self::assertStringContainsString('Exporting 0 reference(s)', $this->exec($this->export, '--tag=none')[1]);

        $to = $this->dataRoot . '/out.bib';
        self::assertSame(0, $this->exec($this->export, '--to=' . $to)[0]);
        self::assertStringContainsString('@article{abel2020,', (string) file_get_contents($to));
        self::assertSame(1, $this->exec($this->export, '--to=' . $to)[0], 'a file that is there is not overwritten');
        self::assertSame(1, $this->exec($this->export, '--format=pdf')[0]);
    }
}
