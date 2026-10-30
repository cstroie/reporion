<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Bibliography;
use Reporion\Support\CslJson;
use Reporion\Support\Ris;

/** RIS, CSL-JSON and the format detection; the records are made up */
final class BibliographyTest extends TestCase
{
    private const RIS = "\xEF\xBB\xBFTY  - JOUR\r\nID  - Nobody2024\r\nTI  - A made-up study\r\n  of test lesions\r\nAU  - Nobody, Ann B.\r\nAU  - Ionescu, Ștefan\r\nAU  - WHO Working Group\r\nJO  - Journal of Synthetic Imaging\r\nPY  - 2024///\r\nVL  - 3\r\nIS  - 2\r\nSP  - 11\r\nEP  - 14\r\nDO  - 10.9999/jsi.2024.0001\r\nAN  - PMID:12345678\r\nKW  - mri\r\nKW  - grading\r\nER  - \r\n\r\nTY  - BOOK\r\nT1  - Imaginary imaging\r\nA1  - Nobody, Dan\r\nPB  - Nowhere Press\r\nSN  - 978-0-00-000000-0\r\nY1  - 2019/05/12\r\nER  - \r\n";

    public function testRisIsReadWithCrlfBomRepeatedAuthorsAndPagesJoined(): void
    {
        $entries = Ris::parse(self::RIS);

        self::assertCount(2, $entries);
        $csl = $entries[0]['csl'];
        self::assertSame('Nobody2024', $entries[0]['key']);
        self::assertSame('A made-up study of test lesions', $csl['title']);
        self::assertSame([['family' => 'Nobody', 'given' => 'Ann B.'], ['family' => 'Ionescu', 'given' => 'Ștefan'], ['literal' => 'WHO Working Group']], $csl['author']);
        self::assertSame([[2024]], $csl['issued']['date-parts']);
        self::assertSame(['11-14', '12345678', '10.9999/jsi.2024.0001'], [$csl['page'], $csl['PMID'], $csl['DOI']]);
        self::assertSame(['mri', 'grading'], $entries[0]['tags']);
        self::assertSame(['book', '978-0-00-000000-0', [[2019, 5]]], [$entries[1]['csl']['type'], $entries[1]['csl']['ISBN'], $entries[1]['csl']['issued']['date-parts']]);
    }

    public function testRisWrittenAndReadBackIsTheSameRecord(): void
    {
        $entries = Ris::parse(self::RIS);
        $again = Ris::parse(Ris::write($entries));

        self::assertCount(2, $again);
        self::assertSame($entries[0]['csl'], $again[0]['csl']);
        self::assertSame($entries[0]['tags'], $again[0]['tags']);
        self::assertSame('Nobody2024', $again[0]['key']);
    }

    public function testCslJsonTakesAListAnItemOrAnItemsObjectAndTheIdIsTheKey(): void
    {
        $item = ['id' => 'abel2020', 'type' => 'article-journal', 'title' => 'Another', 'issued' => ['raw' => '2020-03'], 'keyword' => 'a, b'];

        foreach ([json_encode([$item]), json_encode($item), json_encode(['items' => [$item]])] as $text) {
            $entries = CslJson::parse((string) $text);
            self::assertCount(1, $entries);
            self::assertSame('abel2020', $entries[0]['key']);
            self::assertSame([[2020]], $entries[0]['csl']['issued']['date-parts']);
            self::assertSame(['a', 'b'], $entries[0]['tags']);
            self::assertArrayNotHasKey('id', $entries[0]['csl']);
        }
        self::assertSame([], CslJson::parse('not json'));
        self::assertSame([], CslJson::parse('[{"id": "x"}]'), 'an item with no title');
        self::assertSame('abel2020', CslJson::parse(CslJson::write([['key' => 'abel2020', 'csl' => ['title' => 'Another']]]))[0]['key']);
    }

    public function testTheFormatIsToldFromTheContent(): void
    {
        self::assertSame('ris', Bibliography::detect(self::RIS));
        self::assertSame('bibtex', Bibliography::detect("\n% c\n@article{k,\n title={T}}"));
        self::assertSame('csl', Bibliography::detect(' [{"title":"T"}]'));
        self::assertNull(Bibliography::detect('Plain text'));
        self::assertNull(Bibliography::detect(''));
        self::assertCount(2, Bibliography::parse(self::RIS));
    }

    public function testTheFormattedListIsOneReferencePerLineInTheStyle(): void
    {
        $entries = [['key' => 'a', 'csl' => ['title' => 'T', 'author' => [['family' => 'Abel', 'given' => 'Z']], 'issued' => ['date-parts' => [[2020]]]]]];

        self::assertSame("Abel Z. T. 2020.\n", Bibliography::write('text', $entries));
        self::assertSame("Abel, Z. (2020). T.\n", Bibliography::write('text', $entries, 'apa'));
    }
}
