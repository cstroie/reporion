<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Diff;

final class DiffTest extends TestCase
{
    public function testIdenticalTextIsAllEqual(): void
    {
        $lines = Diff::lines("a\nb\nc", "a\nb\nc");

        self::assertSame(
            [
                ['op' => 'equal', 'line' => 'a'],
                ['op' => 'equal', 'line' => 'b'],
                ['op' => 'equal', 'line' => 'c'],
            ],
            $lines
        );
    }

    public function testPureAddition(): void
    {
        $lines = Diff::lines("a\nb", "a\nb\nc");

        self::assertSame(
            [
                ['op' => 'equal', 'line' => 'a'],
                ['op' => 'equal', 'line' => 'b'],
                ['op' => 'add', 'line' => 'c'],
            ],
            $lines
        );
    }

    public function testPureRemoval(): void
    {
        $lines = Diff::lines("a\nb\nc", "a\nc");

        self::assertSame(
            [
                ['op' => 'equal', 'line' => 'a'],
                ['op' => 'remove', 'line' => 'b'],
                ['op' => 'equal', 'line' => 'c'],
            ],
            $lines
        );
    }

    public function testALineChangedInPlaceIsRemoveThenAddNotAnUpdateOp(): void
    {
        $lines = Diff::lines('Concluzie: stabil', 'Concluzie: progresie usoara');

        self::assertSame(
            [
                ['op' => 'remove', 'line' => 'Concluzie: stabil'],
                ['op' => 'add', 'line' => 'Concluzie: progresie usoara'],
            ],
            $lines
        );
    }

    public function testCompletelyDifferentTextHasNoEqualLines(): void
    {
        $lines = Diff::lines('alpha', 'omega');

        foreach ($lines as $line) {
            self::assertNotSame('equal', $line['op']);
        }
    }

    public function testEmptyToEmptyIsOneEqualBlankLine(): void
    {
        // explode("\n", '') is [''], one blank line, not zero lines — an
        // empty string and "no lines at all" aren't the same document.
        self::assertSame([['op' => 'equal', 'line' => '']], Diff::lines('', ''));
    }

    public function testCountsMatchTheLineDiff(): void
    {
        $counts = Diff::counts("a\nb\nc", "a\nc\nd");

        // b removed, c kept, d added.
        self::assertSame(['add' => 1, 'remove' => 1], $counts);
    }

    public function testCountsForIdenticalTextAreZero(): void
    {
        self::assertSame(['add' => 0, 'remove' => 0], Diff::counts('same', 'same'));
    }

    /**
     * A real report edit — a few unchanged lines, one changed sentence, one
     * appended paragraph — as a sanity check that the algorithm produces a
     * sensible diff on realistic prose, not just single-token cases.
     */
    public function testRealisticReportEdit(): void
    {
        $rev6 = "## Descriere\nLeziuni periventriculare, cea mai mare 11 mm.\n- leziuni juxtacorticale - 4\n\n## Concluzie";
        $rev7 = "## Descriere\nLeziuni periventriculare, cea mai mare 12 mm.\n- leziuni juxtacorticale - 5\n\n## Concluzie\nAspect stabil.";

        $lines = Diff::lines($rev6, $rev7);

        self::assertSame('equal', $lines[0]['op']);
        self::assertSame('## Descriere', $lines[0]['line']);

        $counts = Diff::counts($rev6, $rev7);
        self::assertSame(3, $counts['add']);
        self::assertSame(2, $counts['remove']);
    }

    /**
     * TODO 13: the Compare screen's word-level diff. Whitespace is its own
     * token (equal ops), so joining every op's text round-trips exactly —
     * the property the template's <ins>/<del> rendering relies on.
     */
    public function testWordsMarksOnlyTheChangedWord(): void
    {
        $words = Diff::words('the leziuni are stabile', 'the leziuni sunt stabile');

        // Adjacent same-op tokens are coalesced: one <ins>/<del> per run,
        // not one per word/space token.
        self::assertSame(
            [
                ['op' => 'equal', 'line' => 'the leziuni '],
                ['op' => 'remove', 'line' => 'are'],
                ['op' => 'add', 'line' => 'sunt'],
                ['op' => 'equal', 'line' => ' stabile'],
            ],
            $words
        );
    }

    public function testWordsRoundTripsWhitespaceExactly(): void
    {
        $from = "Linia unu.\nLinia  doi cu spații.";
        $to = "Linia unu, modificat.\nLinia  doi cu spații.";

        $joined = implode('', array_column(Diff::words($from, $to), 'line'));
        // Every kept and every removed token together reconstruct $from;
        // every kept and every added token together reconstruct $to.
        $fromRebuilt = implode('', array_map(
            static fn (array $op): string => $op['line'],
            array_filter(Diff::words($from, $to), static fn (array $op): bool => $op['op'] !== 'add')
        ));
        $toRebuilt = implode('', array_map(
            static fn (array $op): string => $op['line'],
            array_filter(Diff::words($from, $to), static fn (array $op): bool => $op['op'] !== 'remove')
        ));

        self::assertSame($from, $fromRebuilt);
        self::assertSame($to, $toRebuilt);
        self::assertNotSame('', $joined);
    }

    public function testWordsIdenticalTextIsAllEqual(): void
    {
        $words = Diff::words('same text', 'same text');

        self::assertSame([['op' => 'equal', 'line' => 'same text']], $words);
    }
}
