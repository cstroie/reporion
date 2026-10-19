<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\AskExpansion;

final class AskExpansionTest extends TestCase
{
    public function testTheFirstLineIsTheQuestionAndTheRestAreWords(): void
    {
        $parsed = AskExpansion::parse("Cum descriem hernia de disc cervicală?\n- hernie discală\n2. protruzie\n\"compresiune radiculară\"\nleziuni de substanță albă\n");

        self::assertSame('Cum descriem hernia de disc cervicală?', $parsed['question']);
        self::assertSame(['hernie', 'discala', 'protruzie', 'compresiune', 'radiculara', 'leziuni', 'substanta', 'alba'], $parsed['terms']);
    }

    public function testQuestionLabelsAndKeywordOnlyAnswers(): void
    {
        self::assertSame('Ce se recomandă la un nodul tiroidian?', AskExpansion::parse("Question: Ce se recomandă la un nodul tiroidian?\nnodul\ntiroida")['question']);

        $keywords = AskExpansion::parse("Keywords:\nmeningiom\nFLAIR\n");
        self::assertSame('', $keywords['question'], 'no question line: the caller keeps the user\'s');
        self::assertSame(['meningiom', 'flair'], $keywords['terms']);
        self::assertSame(['question' => '', 'terms' => []], AskExpansion::parse(''));
    }

    public function testTheWordsAreCapped(): void
    {
        $many = "Care este?\n" . implode("\n", array_map(static fn (int $i): string => 'cuvant' . str_repeat('x', $i), range(1, 40)));

        self::assertCount(AskExpansion::MAX_TERMS, AskExpansion::parse($many)['terms']);
    }
}
