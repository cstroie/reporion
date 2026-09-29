<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reporion\Support\Zip;

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
}
