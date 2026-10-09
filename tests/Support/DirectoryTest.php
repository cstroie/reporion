<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * reporion_directory() (src/lang.php): the Kernel hands it a loader, so the
 * account files are read the first time a byline is shown, and only then —
 * a media, API or export request that shows none never reads them.
 */
final class DirectoryTest extends TestCase
{
    protected function tearDown(): void
    {
        reporion_directory([]);
    }

    public function testTheLoaderRunsOnTheFirstBylineAndOnlyOnce(): void
    {
        $calls = 0;
        reporion_directory(static function () use (&$calls): array {
            ++$calls;

            return ['ana' => 'Dr. Ana Popescu'];
        });
        self::assertSame(0, $calls, 'setting it reads nothing');

        self::assertSame('Dr. Ana Popescu', display_name('ana'));
        self::assertSame('mihai', display_name('mihai'), 'not in the directory: the username');
        self::assertSame(1, $calls);
    }

    public function testANewLoaderOrAListReplacesWhatWasThere(): void
    {
        reporion_directory(static fn (): array => ['ana' => 'Ana']);
        self::assertSame('Ana', display_name('ana'));

        reporion_directory(static fn (): array => ['ana' => 'Dr. Ana']);
        self::assertSame('Dr. Ana', display_name('ana'), 'a later boot (another instance in the same process) is read again');

        reporion_directory(['ana' => 'Listed']);
        self::assertSame('Listed', display_name('ana'));
    }
}
