<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Every folder of tests is in a phpunit.xml suite: tests/Service and
 * tests/Audit once were not, and 77 tests never ran in `composer test`.
 */
final class PhpunitSuitesTest extends TestCase
{
    public function testEveryTestFolderIsInASuite(): void
    {
        $root = \dirname(__DIR__, 2);
        $xml = simplexml_load_file($root . '/phpunit.xml');
        self::assertNotFalse($xml);
        $listed = array_map('strval', $xml->xpath('//testsuite/directory') ?: []);

        $folders = [];
        foreach (glob($root . '/tests/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (shell_exec('find ' . escapeshellarg($dir) . ' -name "*Test.php" -print -quit') !== null) {
                $folders[] = 'tests/' . basename($dir);
            }
        }

        self::assertSame([], array_values(array_diff($folders, $listed)), 'folders of tests no suite runs');
    }
}
