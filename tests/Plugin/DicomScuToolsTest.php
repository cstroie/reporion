<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Dicom\Scu;
use Reporion\Plugin\Loader;

/** Where each dcmtk tool is found: its own path, else the dcmtk directory, else next to findscu */
final class DicomScuToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'dicom');
    }

    public function testResolutionOrder(): void
    {
        $next = new Scu('/opt/a/findscu', 5);
        self::assertSame('/opt/a/echoscu', $next->tool('echoscu'));

        $dir = new Scu('/opt/a/findscu', 5, null, '', '/opt/own/storescu', '/opt/b/');
        self::assertSame('/opt/a/findscu', $dir->tool('findscu'));
        self::assertSame('/opt/b/echoscu', $dir->tool('echoscu'));
        self::assertSame('/opt/own/storescu', $dir->tool('storescu'));
    }

    public function testVersionsReadTheVersionLine(): void
    {
        $runner = static fn (array $argv): array => str_ends_with($argv[0], 'storescu')
            ? [-1, '']
            : [0, "\$dcmtk: " . basename($argv[0]) . " v3.6.7 2021-12-16 \$\nother\n"];
        $v = (new Scu('/opt/a/findscu', 5, $runner))->versions();

        self::assertSame('findscu v3.6.7 2021-12-16', $v['findscu']['version']);
        self::assertSame('/opt/a/echoscu', $v['echoscu']['path']);
        self::assertSame('', $v['storescu']['version']);
    }
}
