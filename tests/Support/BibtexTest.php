<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Bibtex;

/** The records are made up (docs/library.md); the DOIs do not exist */
final class BibtexTest extends TestCase
{
    private const FILE = <<<'BIB'
@comment{exported by a reference manager}
@string{jsi = "Journal of Synthetic Imaging"}
@article{Nobody2024,
  author  = {Nobody, Ann B. and Ionescu, Ștefan and van der Nobody, Cleo},
  title   = {A {M}ade-up Study of {MRI} Lesions: Grade {II}--{III}},
  journal = jsi,
  year    = 2024,
  month   = may,
  volume  = {3},
  number  = {2},
  pages   = {11--14},
  doi     = {10.9999/jsi.2024.0001},
  pmid    = {12345678},
  keywords = {mri, grading; lesions},
  abstract = "A short abstract with a \% sign
    over two lines."
}
@book{who2019,
  author    = {{WHO Working Group} and Nobody, Dan},
  title     = {Imaginary Imaging},
  publisher = {Nowhere Press},
  address   = {Nowhere},
  year      = {2019}
}
@misc{notitle, author = {X, Y}}
BIB;

    public function testAnArticleIsReadWithItsMacroMonthPagesAndTags(): void
    {
        $entry = Bibtex::parse(self::FILE)[0];

        self::assertSame('Nobody2024', $entry['key']);
        self::assertSame(['mri', 'grading', 'lesions'], $entry['tags']);
        $csl = $entry['csl'];
        self::assertSame('article-journal', $csl['type']);
        self::assertSame('A Made-up Study of MRI Lesions: Grade II–III', $csl['title']);
        self::assertSame('Journal of Synthetic Imaging', $csl['container-title']);
        self::assertSame([[2024, 5]], $csl['issued']['date-parts']);
        self::assertSame(['3', '2', '11–14'], [$csl['volume'], $csl['issue'], $csl['page']]);
        self::assertSame(['10.9999/jsi.2024.0001', '12345678'], [$csl['DOI'], $csl['PMID']]);
        self::assertSame('A short abstract with a % sign over two lines.', $csl['abstract']);
        self::assertSame([
            ['family' => 'Nobody', 'given' => 'Ann B.'],
            ['family' => 'Ionescu', 'given' => 'Ștefan'],
            ['family' => 'van der Nobody', 'given' => 'Cleo'],
        ], $csl['author']);
    }

    public function testABodyNameAndAGivenFamilyNameAndAnEntryWithoutATitle(): void
    {
        $entries = Bibtex::parse(self::FILE);

        self::assertCount(2, $entries, 'the entry with no title is left out; @comment and @string are not entries');
        self::assertSame([['literal' => 'WHO Working Group'], ['family' => 'Nobody', 'given' => 'Dan']], $entries[1]['csl']['author']);
        self::assertSame('book', $entries[1]['csl']['type']);

        $people = Bibtex::people('Ann B. Nobody and Cleo van der Nobody and others');
        self::assertSame([['family' => 'Nobody', 'given' => 'Ann B.'], ['family' => 'van der Nobody', 'given' => 'Cleo']], $people);
    }

    public function testLatexAccentsBecomeUtf8WithRomanianCommaBelowLetters(): void
    {
        self::assertSame('Ștefan Țepeș Pătrașcu', Bibtex::decode('\c{S}tefan \c{T}epe\c{s} P\u{a}tra\c{s}cu'));
        self::assertSame('Café Müller Naïve Hâlă', Bibtex::parse("@article{k, title = {Caf\\'e M{\\\"u}ller Na\\\"ive H\\^{a}l\\u{a}}}")[0]['csl']['title']);
        self::assertSame('Straße & 5% of $x_1$ ø', Bibtex::decode('Stra\ss{}e \& 5\% of $x\_1$ \o'));
    }

    public function testWrittenAndReadBackItIsTheSameRecord(): void
    {
        $entries = Bibtex::parse(self::FILE);
        $again = Bibtex::parse(Bibtex::write($entries));

        self::assertCount(2, $again);
        foreach ($entries as $i => $entry) {
            self::assertSame($entry['csl'], $again[$i]['csl']);
            self::assertSame($entry['key'], $again[$i]['key']);
        }
        self::assertSame(['mri', 'grading', 'lesions'], $again[0]['tags']);
    }

    public function testWritingEscapesTheSpecialCharacters(): void
    {
        $out = Bibtex::write([['key' => 'k2020', 'csl' => ['type' => 'article-journal', 'title' => 'R&D 50% of {x}_y', 'issued' => ['date-parts' => [[2020]]]]]]);

        self::assertStringContainsString('R\&D 50\% of \{x\}\_y', $out);
        self::assertSame('R&D 50% of {x}_y', Bibtex::parse($out)[0]['csl']['title']);
    }

    public function testNothingToReadIsNothing(): void
    {
        self::assertSame([], Bibtex::parse(''));
        self::assertSame([], Bibtex::parse("just some text with an @ sign and no entry\n"));
    }
}
