<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\ReferenceList;
use Reporion\Support\SourceChunks;
use Reporion\Support\TextFlow;

/** Made-up article text in the shapes pdftotext leaves: no real article, name or address */
final class TextFlowTest extends TestCase
{
    private const HEAD_EVEN = 'Journal of Synthetic Imaging, XXXX, Vol. XX, No. XX';

    private static function article(): string
    {
        return implode("\n\n", [
            '*— p. 1 —*',
            'Send Orders for Reprints to orders@example.org Journal of Synthetic Imaging, XXXX, XX, 1-9',
            '1',
            'The study was done on phantoms only (a single phan*Address correspondence to this author at the Department of Testing, Nowhere; Tel: +000000000; E-mail: nobody@example.org 0000-0000/XX $65.00+.00',
            'tom per scan) and read twice.',
            '2\\. MATERIALS AND PHANTOMS Phantoms were made of gel \\[1\\]. The gel was poured into a compact © XXXX Example Science Publishers',
            '*— p. 2 —*',
            '2 ' . self::HEAD_EVEN,
            'mould of plastic \\[2\\]. The T2-weighted images were read first.',
            '3.1. Signal on Spin Echo Sequences MR signal was measured. The basic sequences for the phantom assess-',
            'Nobody et al.',
            'ment are T1-weighted and T2-weighted.',
            'Both complement each other as T1weighted images show the gel at 2years of storage, and 2 years is the limit. Fractional anisotropy rose with a corre-',
            '*— p. 3 —*',
            'Phantoms on MRI',
            self::HEAD_EVEN . ' 3',
            'Fig. (1). Axial images of the phantom.',
            'sponding fall in diffusivity.',
            '*— p. 4 —*',
            '4 ' . self::HEAD_EVEN,
            'Nobody et al.',
            'Absent',
            'Present',
            'Absent',
            'Mild',
            'Marked',
            'Hazy',
            'The last paragraph ends here.',
            '*— p. 5 —*',
            'Phantoms on MRI',
            self::HEAD_EVEN . ' 5',
            'Closing words of the article.',
            '*— p. 6 —*',
            '6 ' . self::HEAD_EVEN,
            'Nobody et al.',
            'Acknowledgements are none.',
        ]) . "\n";
    }

    public function testRunningHeadsPageNumbersAndThePublishersLinesGo(): void
    {
        $r = TextFlow::fix(self::article());

        self::assertStringNotContainsString('Journal of Synthetic Imaging', $r['text']);
        self::assertStringNotContainsString('Nobody et al.', $r['text']);
        self::assertStringNotContainsString('Phantoms on MRI', $r['text']);
        self::assertStringNotContainsString('nobody@example.org', $r['text']);
        self::assertStringNotContainsString('$65.00', $r['text']);
        self::assertStringNotContainsString('©', $r['text']);
        self::assertDoesNotMatchRegularExpression('/^1$/m', $r['text']);
        self::assertGreaterThan(0, $r['fixed']['heads']);
        self::assertGreaterThan(0, $r['fixed']['boilerplate']);
    }

    public function testParagraphsCutByAPageACaptionOrAHeadAreJoinedAndTheMarkerGoesAfter(): void
    {
        $text = TextFlow::fix(self::article())['text'];

        self::assertStringContainsString('poured into a compact mould of plastic \\[2\\]. The T2-weighted images were read first.', $text);
        self::assertStringContainsString('for the phantom assessment are T1-weighted and T2-weighted.', $text, 'a hyphen over a removed head is dropped');
        self::assertStringContainsString('with a corresponding fall in diffusivity.', $text, 'across a page and a caption');
        self::assertStringContainsString('(a single phantom per scan)', $text, 'a word cut by a removed note: whole, as the text writes it elsewhere');
        // The marker and the caption follow the joined paragraph, in their order
        self::assertMatchesRegularExpression('/fall in diffusivity\.\n\n\*— p\. 3 —\*\n\nFig\. \(1\)\./u', $text);
        self::assertMatchesRegularExpression('/plastic \\\\\[2\\\\\]\. The T2-weighted images were read first\.\n\n\*— p\. 2 —\*/u', $text);
    }

    public function testAHyphenTheTextWritesElsewhereStays(): void
    {
        $text = TextFlow::fix("Read the T2-\n\nweighted images. Then the T2-weighted ones.\n")['text'];

        self::assertStringContainsString('Read the T2-weighted images.', $text);
    }

    public function testNumberedHeadingsRunIntoAParagraphBecomeHeadings(): void
    {
        $text = TextFlow::fix(self::article())['text'];

        self::assertStringContainsString("## 2. MATERIALS AND PHANTOMS\n\nPhantoms were made of gel", $text);
        self::assertStringContainsString("### 3.1. Signal on Spin Echo Sequences\n\nMR signal was measured.", $text, 'the capital word before the first lower-case one begins the sentence');
        self::assertStringContainsString("### 4.1. At Birth (Figs. 1 and 2)\n\nAs discussed", TextFlow::fix("4.1. At Birth (Figs. 1 and 2) As discussed above, it starts.\n")['text']);
        self::assertStringContainsString("## 5. RESULTS \\[3\\]\n\nA summary", TextFlow::fix("It ended. 5. RESULTS \\[3\\] A summary follows.\n")['text']);
    }

    public function testGluedWordsAreWrittenAsTheTextWritesThemElsewhereOnly(): void
    {
        $text = TextFlow::fix(self::article())['text'];

        self::assertStringContainsString('as T1-weighted images show the gel at 2 years of storage', $text);
        self::assertStringContainsString('at 3months', TextFlow::fix("Seen at 3months.\n")['text'], 'never by guess');
    }

    public function testWhatCannotBeMendedIsReported(): void
    {
        $r = TextFlow::fix(self::article());
        $codes = array_column($r['issues'], 'code');

        self::assertContains('table', $codes);
        self::assertContains('open_end', array_column(TextFlow::fix("A sentence that is cut off by a column read out of order and goes on\n\nElsewhere.\n")['issues'], 'code'));
    }

    public function testItIsIdempotent(): void
    {
        $once = TextFlow::fix(self::article())['text'];
        $twice = TextFlow::fix($once);

        self::assertSame($once, $twice['text']);
        self::assertSame([], $twice['fixed']);
    }

    public function testAFencedBlockIsLeftAlone(): void
    {
        $body = "Text that goes on\n\n```\ncode line\n\nmore\n```\n\nlower case after.\n";

        self::assertSame($body, TextFlow::fix($body)['text']);
    }

    public function testThePrintedReferenceListBecomesANumberedListWithNumbersPairedAgain(): void
    {
        $raw = implode("\n", [
            '[1]', '[2]', '', '[3]', '',
            'Abel A, Bach B. A made-up paper on gel', 'phantoms. J Synth 2015; 3(4): 44-8.',
            'http://dx.doi.org/10.1234/js.2015.001 PMID: 11111111',
            'Cole C, Dunn D. Another made-up paper. Synth Rev 2019; 8(1):', '14.',
            'http://dx.doi.org/10.1234/sr.2019.002 PMID:', '22222222',
            'Phantoms on MRI',
            'The role of made-up sequences. [cited 2020 Aug 2]. Available', 'from:http://www.example.org/the-role-of-made-up-a', 'nd-other-sequences-1350',
            '', 'DISCLAIMER: The above article is made up.', 'Nothing to keep.',
        ]);
        $body = "Text.\n\n## References\n\n```text\n" . $raw . "\n```\n";
        $r = TextFlow::fix($body);

        self::assertStringContainsString("## References\n\n1. Abel A, Bach B. A made-up paper on gel phantoms. J Synth 2015; 3(4): 44-8. <http://dx.doi.org/10.1234/js.2015.001> PMID: 11111111\n2. Cole C, Dunn D.", $r['text']);
        self::assertStringContainsString('<http://dx.doi.org/10.1234/sr.2019.002> PMID: 22222222', $r['text']);
        self::assertStringContainsString("3. The role of made-up sequences. \\[cited 2020 Aug 2\\]. Available from:<http://www.example.org/the-role-of-made-up-and-other-sequences-1350>", $r['text']);
        self::assertStringNotContainsString('DISCLAIMER', $r['text']);
        self::assertStringNotContainsString('```', $r['text']);
        self::assertSame(3, $r['fixed']['references']);

        // Read back as the library reads it, and left out of the article's text
        $entries = ReferenceList::entries(ReferenceList::of($r['text']));
        self::assertCount(3, $entries);
        self::assertStringContainsString('http://dx.doi.org/10.1234/js.2015.001', $entries[0]);
        self::assertStringContainsString('[cited 2020 Aug 2]', $entries[2]);
        self::assertSame('Text.', SourceChunks::withoutReferences($r['text']));
    }

    public function testNumbersThatDoNotPairLeaveAnUnnumberedList(): void
    {
        $list = TextFlow::referenceList("[1]\n[2]\n\nAbel A. One made-up paper. 2015.\nCole C. Two. 2016.\nDunn D. Three. 2017.");

        self::assertFalse($list['paired']);
        self::assertStringStartsWith('- Abel A.', $list['text']);
        self::assertStringContainsString("\n- Dunn D. Three. 2017.", $list['text']);
    }
}
