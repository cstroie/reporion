<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\NamespaceSort;

final class NamespaceSortTest extends TestCase
{
    /** @return list<array{path: string, title: string, updated: string}> */
    private static function rows(): array
    {
        return [
            ['path' => 't:zeta', 'title' => 'Zeta', 'updated' => '2026-10-01'],
            ['path' => 't:sold', 'title' => 'Șold', 'updated' => '2026-10-03'],
            ['path' => 't:cap-10', 'title' => 'cap 10', 'updated' => '2026-10-02'],
            ['path' => 't:cap-2', 'title' => 'Cap 2', 'updated' => '2026-10-05'],
            ['path' => 't:alpha', 'title' => '', 'updated' => '2026-10-04'],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function paths(array $rows): array
    {
        return array_column($rows, 'path');
    }

    public function testModeOfReadsAKnownModeFromMetaJsonOrAnArray(): void
    {
        self::assertSame('title', NamespaceSort::modeOf('{"sort":"title","priority":"high"}'));
        self::assertSame('updated', NamespaceSort::modeOf(['sort' => ' updated ']));
        self::assertNull(NamespaceSort::modeOf('{"sort":"random"}'), 'an unknown mode keeps the default order');
        self::assertNull(NamespaceSort::modeOf('{"sort":3}'));
        self::assertNull(NamespaceSort::modeOf(''));
        self::assertNull(NamespaceSort::modeOf(null));
    }

    public function testNoModeKeepsTheRowsAsGiven(): void
    {
        self::assertSame(self::rows(), NamespaceSort::sort(self::rows(), null));
    }

    public function testTitleIsCaseBlindNumericRomanianAndFallsBackToTheName(): void
    {
        // alpha has no title: its name stands in; Șold sorts after the S's of a Latin alphabet, before Zeta
        self::assertSame(
            ['t:alpha', 't:cap-2', 't:cap-10', 't:sold', 't:zeta'],
            self::paths(NamespaceSort::sort(self::rows(), 'title'))
        );
    }

    public function testNameSortsOnTheLastSegmentNotTheTitle(): void
    {
        $rows = [
            ['path' => 't:mri:b', 'title' => 'A title', 'updated' => ''],
            ['path' => 't:mri:a', 'title' => 'Z title', 'updated' => ''],
        ];
        self::assertSame(['t:mri:a', 't:mri:b'], self::paths(NamespaceSort::sort($rows, 'name')));
    }

    public function testUpdatedIsNewestFirstThenByName(): void
    {
        $rows = [...self::rows(), ['path' => 't:aaa', 'title' => 'x', 'updated' => '2026-10-05']];
        self::assertSame(
            ['t:aaa', 't:cap-2', 't:alpha', 't:sold', 't:cap-10', 't:zeta'],
            self::paths(NamespaceSort::sort($rows, 'updated'))
        );
    }
}
