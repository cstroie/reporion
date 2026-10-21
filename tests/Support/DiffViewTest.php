<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\DiffView;

final class DiffViewTest extends TestCase
{
    public function testAChangedLineIsPairedAndItsChangedWordsMarked(): void
    {
        $view = DiffView::chunks("a\nfoo(bar, baz);\nz", "a\nfoo(bar, qux);\nz");
        $rows = DiffView::unified($view['chunks']);

        self::assertSame(['ctx', 'del', 'add', 'ctx'], array_column($rows, 't'));
        self::assertSame([['eq', 'foo(bar, '], ['chg', 'baz'], ['eq', ');']], $rows[1]['segs']);
        self::assertSame([['eq', 'foo(bar, '], ['chg', 'qux'], ['eq', ');']], $rows[2]['segs']);
        self::assertSame(['add' => 1, 'remove' => 1], ['add' => $view['add'], 'remove' => $view['remove']]);
    }

    public function testCompletelyDifferentLinesAreNotMarkedInside(): void
    {
        $rows = DiffView::unified(DiffView::chunks("alpha beta gamma", "one two three")['chunks']);

        self::assertNull($rows[0]['segs']);
        self::assertNull($rows[1]['segs']);
    }

    public function testLineNumbersFollowEachSide(): void
    {
        $rows = DiffView::unified(DiffView::chunks("a\nb", "x\na\nb")['chunks']);

        self::assertSame([null, 1], [$rows[0]['a'], $rows[0]['b']]);
        self::assertSame([1, 2], [$rows[1]['a'], $rows[1]['b']]);
    }

    public function testALongUnchangedRunIsFoldedWithItsSectionAndContextKept(): void
    {
        $lines = array_map(static fn (int $i): string => 'line ' . $i, range(1, 30));
        $from = "## Sect\n" . implode("\n", $lines) . "\nend";
        $to = "## Sect\n" . implode("\n", $lines) . "\nEND";

        $rows = DiffView::unified(DiffView::chunks($from, $to)['chunks']);

        self::assertSame('fold', $rows[0]['t']);
        self::assertSame(28, $rows[0]['n']);
        self::assertSame('## Sect', $rows[0]['heading']);
        self::assertCount(28, $rows[0]['rows']);
        // three lines of context, then the change
        self::assertSame(['ctx', 'ctx', 'ctx', 'del', 'add'], array_column(\array_slice($rows, 1), 't'));
    }

    public function testShortRunsAreNotFolded(): void
    {
        $rows = DiffView::unified(DiffView::chunks("1\n2\n3\n4\n5\n6\nx", "1\n2\n3\n4\n5\n6\ny")['chunks']);

        self::assertNotContains('fold', array_column($rows, 't'));
    }

    public function testSplitAlignsDeletionsWithAdditionsAndLeavesTheRestEmpty(): void
    {
        $rows = DiffView::split(DiffView::chunks("a\nb\nc", "a\nB\nc\nd")['chunks']);

        self::assertSame('ctx', $rows[0]['l']['t']);
        self::assertSame(['del', 'add'], [$rows[1]['l']['t'], $rows[1]['r']['t']]);
        self::assertNull($rows[3]['l']);
        self::assertSame('add', $rows[3]['r']['t']);
        self::assertSame(4, $rows[3]['r']['n']);
    }

    public function testIdenticalTextsHaveNoChanges(): void
    {
        $view = DiffView::chunks("same\ntext", "same\ntext");

        self::assertSame(0, $view['add'] + $view['remove']);
    }
}
