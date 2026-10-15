<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\TagNormalize;

/** tags:review's rule (2026-10-15): near-duplicates and tags with a side or a spine level */
final class TagNormalizeTest extends TestCase
{
    public function testNearDuplicatesMergeIntoTheSpellingOnTheMostPages(): void
    {
        $proposals = TagNormalize::proposals(['leziuni' => 2, 'leziune' => 9, 'fractură' => 1, 'fracturi' => 3, 'chist' => 4]);

        self::assertSame([
            ['op' => 'merge', 'from' => ['fractură'], 'to' => 'fracturi', 'reason' => 'same words', 'source' => 'rule'],
            ['op' => 'merge', 'from' => ['leziuni'], 'to' => 'leziune', 'reason' => 'same words', 'source' => 'rule'],
        ], $proposals, 'a tag alone has nothing to merge with');
    }

    public function testASideOrALevelIsGeneralisedIntoTheTagWithoutIt(): void
    {
        $proposals = TagNormalize::proposals([
            'hernie discală' => 5,
            'hernie discală L4-L5 stângă' => 1,
            'hernie discala C5C6' => 1,
            'chist ovar stâng' => 2,
            'chist ovar drept' => 1,
        ]);

        self::assertSame([
            ['op' => 'generalise', 'from' => ['chist ovar drept', 'chist ovar stâng'], 'to' => 'chist ovar', 'reason' => 'side or level', 'source' => 'rule'],
            ['op' => 'generalise', 'from' => ['hernie discala C5C6', 'hernie discală L4-L5 stângă'], 'to' => 'hernie discală', 'reason' => 'side or level', 'source' => 'rule'],
        ], $proposals);
    }

    public function testRadsAndATagThatIsOnlyASideAreLeftAlone(): void
    {
        self::assertSame([], TagNormalize::proposals(['rads:birads-2' => 3, 'rads:birads-3' => 1, 'stâng' => 2, 'menisc' => 1]));
        self::assertSame([], TagNormalize::proposals(['normal' => 1, 'normală' => 5]), 'normal keeps its spelling: Similar reports reads it');
        self::assertSame('menisc lateral', TagNormalize::withoutQualifiers('menisc lateral stâng'), 'lateral is anatomy, not a side');
    }

    public function testTagsAreGroupedByTheWordsTheyShareAndATagMayBeInSeveral(): void
    {
        $groups = TagNormalize::groups(['leziune meniscală', 'menisc lateral', 'leziuni de cartilaj', 'chist ovar stâng', 'L4']);

        self::assertSame(['leziune meniscală', 'leziuni de cartilaj'], $groups['leziun']);
        self::assertSame(['leziune meniscală'], $groups['meniscal']);
        self::assertSame(['chist ovar stâng'], $groups['chist']);
        self::assertArrayNotHasKey('stang', $groups, 'a side groups nothing');
        self::assertArrayNotHasKey('de', $groups);
        self::assertSame(['L4'], $groups['l4'], 'no word left: its own group');
    }

    public function testARequestIsClosedEarlyRatherThanSplitAGroup(): void
    {
        $groups = ['a' => ['a1', 'a2'], 'b' => ['b1', 'b2', 'b3'], 'c' => ['a1', 'c1'], 'd' => ['a1', 'a2'], 'e' => ['e1', 'e2', 'e3', 'e4', 'e5']];

        self::assertSame([
            ['a1', 'a2'],
            ['b1', 'b2', 'b3'],
            ['a1', 'c1'],
            ['e1', 'e2', 'e3', 'e4'],
            ['e5'],
        ], TagNormalize::chunks($groups, 4), 'c would not fit beside b: a new request, a1 again; d already went together');
    }
}
