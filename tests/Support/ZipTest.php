<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reporion\Support\Zip;
use Reporion\Support\ZipEntry;
use RuntimeException;

final class ZipTest extends TestCase
{
    public function testEntriesReadBackIntactWithTheirNamesAndTime(): void
    {
        $bytes = Zip::build(['a-rev1.pdf' => "%PDF\x00\xff binary", 'ș.txt' => "text\n"], new DateTimeImmutable('2026-09-29 13:45:10'));

        require_once \dirname(__DIR__, 2) . '/vendor/phpoffice/phpword/src/PhpWord/Shared/PCLZip/pclzip.lib.php';
        $file = tempnam(sys_get_temp_dir(), 'reporion-zip-');
        file_put_contents($file, $bytes);
        try {
            $list = (new \PclZip($file))->extract(PCLZIP_OPT_EXTRACT_AS_STRING);
        } finally {
            unlink($file);
        }

        self::assertIsArray($list);
        self::assertSame(['a-rev1.pdf', 'ș.txt'], array_column($list, 'filename'));
        self::assertSame(["%PDF\x00\xff binary", "text\n"], array_column($list, 'content'));
        self::assertSame(mktime(13, 45, 10, 9, 29, 2026), $list[0]['mtime']);
    }

    public function testAnEntryNameCannotEscapeTheArchive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Zip::build(['../evil.txt' => 'x'], new DateTimeImmutable());
    }

    public function testDeflatedEntriesReadBackThroughReadAndThroughPclZip(): void
    {
        $at = new DateTimeImmutable('2026-10-20 08:30:00');
        $text = str_repeat("Fără modificări de semnal.\n", 50);
        $bytes = Zip::assemble([ZipEntry::deflated('0001.md', $text, $at), ZipEntry::stored('revlog.json', '{}', $at)]);

        $entries = Zip::read($bytes);
        self::assertSame(['0001.md', 'revlog.json'], array_keys($entries));
        self::assertSame(ZipEntry::DEFLATED, $entries['0001.md']->method);
        self::assertLessThan(\strlen($text), \strlen($entries['0001.md']->data));
        self::assertSame($text, $entries['0001.md']->bytes());

        self::assertSame([$text, '{}'], array_column($this->pclExtract($bytes), 'content'));
    }

    public function testReadsAnArchiveAnotherWriterMade(): void
    {
        require_once \dirname(__DIR__, 2) . '/vendor/phpoffice/phpword/src/PhpWord/Shared/PCLZip/pclzip.lib.php';
        $dir = sys_get_temp_dir() . '/reporion-zip-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/a.md', str_repeat('abc ', 500));
        $file = $dir . '/x.zip';
        try {
            (new \PclZip($file))->create([$dir . '/a.md'], PCLZIP_OPT_REMOVE_ALL_PATH);
            $entries = Zip::read((string) file_get_contents($file));
        } finally {
            @unlink($dir . '/a.md');
            @unlink($file);
            rmdir($dir);
        }

        self::assertSame(str_repeat('abc ', 500), $entries['a.md']->bytes());
    }

    public function testAnEntryKeepsItsCompressedBytesWhenWrittenAgain(): void
    {
        $entry = ZipEntry::deflated('0001.md', 'raport', new DateTimeImmutable());
        $again = Zip::read(Zip::assemble([$entry]))['0001.md'];

        self::assertTrue($again->sameAs($entry));
        self::assertSame($entry->dosTime, $again->dosTime);
    }

    public function testAnEntryWhoseBytesDoNotMatchItsCrcIsRefused(): void
    {
        $bytes = Zip::assemble([ZipEntry::stored('a.md', 'original', new DateTimeImmutable())]);
        $entry = Zip::read(str_replace('original', 'tampered', $bytes))['a.md'];

        $this->expectException(RuntimeException::class);
        $entry->bytes();
    }

    public function testANameThatCouldEscapeIsRefusedOnRead(): void
    {
        $bytes = Zip::assemble([ZipEntry::stored('aa/x', 'x', new DateTimeImmutable())]);

        $this->expectException(RuntimeException::class);
        Zip::read(str_replace('aa/x', '../x', $bytes));
    }

    public function testTruncatedOrForeignBytesAreNotAZip(): void
    {
        $bytes = Zip::assemble([ZipEntry::deflated('a.md', str_repeat('x', 100), new DateTimeImmutable())]);
        foreach ([substr($bytes, 0, -5), substr($bytes, 10), 'not a zip', ''] as $broken) {
            try {
                Zip::read($broken);
                self::fail('read a broken archive');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testADuplicateNameIsRefusedOnWrite(): void
    {
        $at = new DateTimeImmutable();
        $this->expectException(InvalidArgumentException::class);
        Zip::assemble([ZipEntry::stored('a', '1', $at), ZipEntry::stored('a', '2', $at)]);
    }

    /** @return list<array<string, mixed>> */
    private function pclExtract(string $bytes): array
    {
        require_once \dirname(__DIR__, 2) . '/vendor/phpoffice/phpword/src/PhpWord/Shared/PCLZip/pclzip.lib.php';
        $file = tempnam(sys_get_temp_dir(), 'reporion-zip-');
        file_put_contents($file, $bytes);
        try {
            $list = (new \PclZip($file))->extract(PCLZIP_OPT_EXTRACT_AS_STRING);
        } finally {
            unlink($file);
        }
        self::assertIsArray($list);

        return $list;
    }
}
