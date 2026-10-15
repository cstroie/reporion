<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\TagProposals;

/** tags:review's reading of the assistant's answer (2026-10-15): only what passes its checks */
final class TagProposalsTest extends TestCase
{
    private const COUNTS = ['leziune corn anterior menisc' => 1, 'leziune meniscală' => 4, 'de urmărit' => 2, 'scleroza multipla' => 3, 'SM' => 5, 'rads:birads-2' => 1];

    public function testOneObjectPerLineIsReadAndWhatFailsTheChecksIsLeftOut(): void
    {
        $answer = <<<'TXT'
            ```json
            {"op": "generalize", "from": ["leziune corn anterior menisc"], "to": "Leziune meniscală", "reason": "segment"},
            {"op": "drop", "from": ["de urmărit"], "reason": "not a finding"}
            {"op": "merge", "from": ["no-such-tag"], "to": "x"}
            {"op": "drop", "from": ["rads:birads-2"]}
            {"op": "rename", "from": ["SM", "scleroza multipla"], "to": "scleroză multiplă"}
            {"op": "merge", "from": ["SM"], "to": "a, b"}
            {"op": "merge", "from": ["SM"], "to": "one two three four five"}
            {"op": "split", "from": ["SM"], "to": "x"}
            ```
            TXT;

        self::assertSame([
            ['op' => 'generalise', 'from' => ['leziune corn anterior menisc'], 'to' => 'leziune meniscală', 'reason' => 'segment', 'source' => 'ai'],
            ['op' => 'drop', 'from' => ['de urmărit'], 'to' => '', 'reason' => 'not a finding', 'source' => 'ai'],
        ], TagProposals::parse($answer, self::COUNTS));
    }

    public function testAJsonArrayACuratedTagAndATurnedDownProposal(): void
    {
        $answer = '[{"op": "merge", "from": ["SM"], "to": "scleroza multipla"}, {"op": "drop", "from": ["de urmărit"]}]';

        self::assertCount(2, TagProposals::parse($answer, self::COUNTS));
        self::assertCount(1, TagProposals::parse($answer, self::COUNTS, curated: ['de urmărit']), 'a dictionary tag is never dropped');
        self::assertSame('SM → scleroza multipla', TagProposals::key(['op' => 'merge', 'from' => ['SM'], 'to' => 'scleroza multipla']));
        self::assertSame([['op' => 'drop', 'from' => ['de urmărit'], 'to' => '', 'reason' => '', 'source' => 'ai']], TagProposals::parse($answer, self::COUNTS, rejected: ['SM → scleroza multipla']));
    }

    public function testNormalAndExamTypesKeepTheirMeaning(): void
    {
        $counts = ['normal' => 9, 'Normală' => 2, 'irm' => 4, 'ct genunchi' => 1, 'aspect normal' => 1];
        $answer = implode("\n", [
            '{"op": "drop", "from": ["normal"]}',
            '{"op": "drop", "from": ["Normală"]}',
            '{"op": "drop", "from": ["irm"]}',
            '{"op": "drop", "from": ["ct genunchi"]}',
            '{"op": "merge", "from": ["normal"], "to": "fără leziuni"}',
            '{"op": "merge", "from": ["Normală", "aspect normal"], "to": "normal"}',
        ]);

        self::assertSame([['op' => 'merge', 'from' => ['Normală', 'aspect normal'], 'to' => 'normal', 'reason' => '', 'source' => 'ai']], TagProposals::parse($answer, $counts));
    }

    public function testTheRulesProposalsWinOverTheAssistantsOnTheSameTag(): void
    {
        $rule = [['op' => 'merge', 'from' => ['SM'], 'to' => 'scleroza multipla', 'reason' => '', 'source' => 'rule']];
        $ai = [
            ['op' => 'drop', 'from' => ['SM'], 'to' => '', 'reason' => '', 'source' => 'ai'],
            ['op' => 'drop', 'from' => ['de urmărit'], 'to' => '', 'reason' => '', 'source' => 'ai'],
        ];

        self::assertSame([$rule[0], $ai[1]], TagProposals::combine($rule, $ai));
    }
}
