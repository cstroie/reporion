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
}
