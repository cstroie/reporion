<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Citation;

/** The works cited are made up (docs/library.md) */
final class CitationTest extends TestCase
{
    private const ARTICLE = [
        'type' => 'article-journal',
        'title' => 'A made-up study of test lesions',
        'author' => [['family' => 'Nobody', 'given' => 'Ann'], ['family' => 'Nobody', 'given' => 'Bob B.'], ['family' => 'van Nobody', 'given' => 'Cleo-Marie']],
        'container-title' => 'Journal of Synthetic Imaging',
        'container-title-short' => 'J Synth Imaging',
        'issued' => ['date-parts' => [[2024, 5]]],
        'volume' => '3', 'issue' => '2', 'page' => '11-14',
        'DOI' => '10.1234/jti.2024.0001',
    ];

    public function testAJournalArticleInVancouver(): void
    {
        self::assertSame(
            'Nobody A, Nobody BB, van Nobody CM. A made-up study of test lesions. J Synth Imaging. 2024;3(2):11-14. doi:10.1234/jti.2024.0001',
            Citation::vancouver(self::ARTICLE),
        );
    }

    public function testMoreThanSixAuthorsAreSixThenEtAlAndATitleKeepsItsQuestionMark(): void
    {
        $csl = ['title' => 'Is any of this real?', 'author' => array_map(static fn (int $i): array => ['family' => 'Author' . $i, 'given' => 'X'], range(1, 7)), 'issued' => ['date-parts' => [[2020]]]];

        self::assertSame('Author1 X, Author2 X, Author3 X, Author4 X, Author5 X, Author6 X, et al. Is any of this real? 2020.', Citation::vancouver($csl));
    }

    public function testABookHasItsEditionAndImprint(): void
    {
        $csl = ['type' => 'book', 'title' => 'Imaginary imaging', 'author' => [['literal' => 'WHO Working Group']], 'edition' => '2', 'publisher' => 'Nowhere Press', 'publisher-place' => 'Nowhere', 'issued' => ['date-parts' => [[2019]]]];

        self::assertSame('WHO Working Group. Imaginary imaging. 2nd ed. Nowhere: Nowhere Press; 2019.', Citation::vancouver($csl));
    }

    public function testShortFormsForLists(): void
    {
        self::assertSame('Nobody A, Nobody BB, et al.', Citation::authorsShort(self::ARTICLE, 2));
        self::assertSame('', Citation::authorsShort(['title' => 'x']));
        self::assertSame(2024, Citation::year(self::ARTICLE));
        self::assertNull(Citation::year([]));
        self::assertSame('A title.', Citation::vancouver(['title' => 'A title']));
    }
}
