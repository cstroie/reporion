<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\TagList;

final class TagListTest extends TestCase
{
    private const DICTIONARY = [
        'fractura' => ['group' => 'diagnosis', 'icd10' => '', 'synonyms' => ['fracturi', 'fisura']],
        'adenopatie' => ['group' => '', 'icd10' => '', 'synonyms' => ['adenopatii']],
    ];

    public function testACommaListIsLowercasedTrimmedAndKeptInOrder(): void
    {
        self::assertSame(['radiografie', 'torace', 'pneumonie', 'revărsat pleural'], TagList::parse('Radiografie,  torace , Pneumonie, revărsat pleural.'));
    }

    public function testNoneOrProseIsNoTags(): void
    {
        self::assertSame([], TagList::parse('NONE'));
        self::assertSame([], TagList::parse("NONE\n"));
        self::assertSame([], TagList::parse(''));
        self::assertSame([], TagList::parse('Raportul nu conține date clinice utilizabile'), 'one item, not an exam type');
        self::assertSame([], TagList::parse('normal'));
    }

    public function testASingleExamTypeIsKept(): void
    {
        self::assertSame(['irm cerebral'], TagList::parse('IRM cerebral'));
        self::assertSame(['ct'], TagList::parse('CT'));
    }

    public function testDuplicatesGoAndTheListStopsAtFive(): void
    {
        self::assertSame(['ct', 'torace', 'nodul'], TagList::parse('ct, torace, nodul, Nodul, CT'));
        self::assertSame(['ct', 'abdomen', 'a', 'b', 'c'], TagList::parse('ct, abdomen, a, b, c, d, e, f'));
    }

    public function testQuotesLabelsMarkersAndOneTagPerLineAreHandled(): void
    {
        self::assertSame(['ecografie', 'abdomen', 'lichid liber'], TagList::parse('Tags: "ecografie, abdomen, lichid liber"'));
        self::assertSame(['ecografie', 'abdomen', 'lichid liber'], TagList::parse("- ecografie\n- abdomen\n- **lichid liber**"));
    }

    public function testATagTakesTheDictionarysSpellingForAnEntryOrASynonym(): void
    {
        self::assertSame(['radiografie', 'gambă', 'fractura', 'adenopatie'], TagList::parse('radiografie, gambă, fractură, adenopatii', self::DICTIONARY));
        self::assertSame(['rx', 'fractura'], TagList::parse('rx, fractură, Fisură', self::DICTIONARY), 'two spellings of one entry are one tag');
    }

    public function testASentenceIsNotATag(): void
    {
        self::assertSame(['ct', 'torace'], TagList::parse('ct, torace, nu se evidențiază modificări patologice semnificative'));
    }
}
