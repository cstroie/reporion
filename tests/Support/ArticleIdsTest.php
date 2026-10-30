<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\ArticleIds;

/**
 * Every identifier here is made up for the test (a synthetic page, docs/library.md):
 * the shapes are real, the articles are not.
 */
final class ArticleIdsTest extends TestCase
{
    public function testTheArticlesOwnDoiComesFirstAndRepeatsAreDropped(): void
    {
        $text = "Journal of Test Imaging 2019;12(3):45-52\n"
            . "https://doi.org/10.1234/JTI.2019.0042\n\n"
            . "References\n1. A. Author. doi:10.5678/abc-1(2)3.\n"
            . '2. Again 10.1234/jti.2019.0042, cited twice.';

        self::assertSame(['10.1234/jti.2019.0042', '10.5678/abc-1(2)3'], ArticleIds::find($text)['doi']);
    }

    public function testSentencePunctuationAndAnUnbalancedBracketAreNotPartOfTheDoi(): void
    {
        $ids = ArticleIds::find('(see 10.1000/xyz.12). And [10.1000/q-9]; then 10.1000/end:');

        self::assertSame(['10.1000/xyz.12', '10.1000/q-9', '10.1000/end'], $ids['doi']);
    }

    public function testNormalizeDoiAcceptsTheWaysItIsWrittenAndRefusesAnythingElse(): void
    {
        self::assertSame('10.1234/ab.c', ArticleIds::normalizeDoi(' https://dx.doi.org/10.1234/AB.C. '));
        self::assertSame('10.1234/ab.c', ArticleIds::normalizeDoi('DOI: 10.1234/ab.c'));
        self::assertNull(ArticleIds::normalizeDoi('10.12/too-short-prefix'));
        self::assertNull(ArticleIds::normalizeDoi('not a doi'));
    }

    public function testPubmedAndArxivIdentifiers(): void
    {
        $ids = ArticleIds::find("PMID: 12345678 PMCID: PMC7654321\narXiv:2101.01234v2 and PMID 12345678 again");

        self::assertSame(['12345678'], $ids['pmid']);
        self::assertSame(['PMC7654321'], $ids['pmcid']);
        self::assertSame(['2101.01234'], $ids['arxiv']);
    }

    public function testAnIsbnCountsOnlyWhenItsCheckDigitHolds(): void
    {
        $ids = ArticleIds::find('ISBN 978-0-306-40615-7, ISBN-10: 0-306-40615-2, ISBN 978-0-306-40615-8');

        self::assertSame(['9780306406157', '0306406152'], $ids['isbn']);
    }

    public function testATextWithoutIdentifiersFindsNothing(): void
    {
        self::assertSame(
            ['doi' => [], 'pmid' => [], 'pmcid' => [], 'arxiv' => [], 'isbn' => []],
            ArticleIds::find('Plain prose with numbers 10.5 and 2019.'),
        );
    }
}
