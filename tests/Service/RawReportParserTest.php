<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use PHPUnit\Framework\TestCase;
use Reporion\Service\RawReportParser;

/**
 * The old pre-2026 report body format is:
 *
 *     # Patient Name
 *     **indication**
 *     *date*
 *     body paragraphs...
 *
 * The migration restructures it to:
 *
 *     ## Exam Title
 *
 *     ### Descriere
 *     description text
 *
 *     ### Concluzii
 *     conclusion text
 *
 * RawReportParser detects the old shape, extracts its parts,
 * deduces the exam title from body keywords, and flags confidence.
 */
final class RawReportParserTest extends TestCase
{
    // -- Detection ----------------------------------------------------------------

    public function testIsOldFormatReturnsTrueForOldFormat(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\nAșa se prezintă.\n";

        self::assertTrue(RawReportParser::isOldFormat($body));
    }

    public function testIsOldFormatReturnsFalseForStructuredPage(): void
    {
        $body = "## Exam Title\n\n### Descriere\ndescription text\n\n### Concluzii\nconclusion text\n";

        self::assertFalse(RawReportParser::isOldFormat($body));
    }

    public function testIsOldFormatReturnsFalseForNonReportPage(): void
    {
        $body = "Just some plain text without any heading.\n";

        self::assertFalse(RawReportParser::isOldFormat($body));
    }

    public function testIsOldFormatReturnsFalseForAlreadyStructuredWithExamTitle(): void
    {
        $body = "## CT Cerebral\n\n### Descriere\nA.\n\n### Concluzii\nB.\n";

        self::assertFalse(RawReportParser::isOldFormat($body));
    }

    // -- Indication extraction ----------------------------------------------------

    public function testExtractsIndicationFromBoldLine(): void
    {
        $body = "# Test Patient\n**Cefalee și amețeli**\n*01.02.2024*\nBody text.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertSame('Cefalee și amețeli', $result['indication']);
    }

    public function testIndicationIsEmptyWhenMissing(): void
    {
        $body = "# Test Patient\nNo bold line here.\n*01.02.2024*\nBody text.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertNull($result);
    }

    public function testLastBoldLineIsIndication(): void
    {
        $body = "# Test Patient\n**First bold**\n**Second bold**\n*01.02.2024*\nBody.\n\nConclusion.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertSame('Second bold', $result['indication']);
    }

    // -- Body split ---------------------------------------------------------------

    public function testBodySplitByLastParagraph(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\nSome description.\n\nConclusion here.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertSame('Some description.', trim($result['description']));
        self::assertSame('Conclusion here.', trim($result['conclusion']));
    }

    public function testNoConclusionSingleParagraphIsConclusion(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\nOnly description.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertSame('', trim($result['description']));
        self::assertSame('Only description.', trim($result['conclusion']));
    }

    public function testEmptyBody(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertSame('indicație', $result['indication']);
        self::assertSame('', trim($result['description']));
        self::assertSame('', trim($result['conclusion']));
    }

    // -- Exam title deduction -----------------------------------------------------

    public function testExamTitleDeducedFromBodyKeyword(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\nTCC — traumatism craniocerebral.\n\nConcluzie normală.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertSame('CT Cerebral', $result['examTitle']);
    }

    public function testExamTitleDeducedFromGenunchiKeyword(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\nFractură de genunchi.\n\nConcluzie normală.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertSame('CT Genunchi', $result['examTitle']);
    }

    public function testExamTitleDeducedFromHepaticKeyword(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\nLeziune hepatică.\n\nConcluzie normală.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertSame('CT Abdomen', $result['examTitle']);
    }

    public function testExamTitleFallbackToPathModality(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\nSome general findings.\n\nConcluzie normală.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertSame('CT Exam', $result['examTitle']);
    }

    public function testExamTitleFallbackToFrontmatterModality(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\nSome general findings.\n\nConcluzie normală.\n";

        $result = RawReportParser::parse($body, 'unknown/mioveni/010224-test.txt', ['modality' => ['MR']]);

        self::assertSame('MR Exam', $result['examTitle']);
    }

    // -- Confidence flags ---------------------------------------------------------

    public function testHighConfidenceWhenIndicationPresent(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\nBody.\n\nConclusion.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertContains('high', $result['confidence']);
    }

    public function testMediumConfidenceWhenKeywordMatch(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\nTCC cerebral.\n\nConcluzie normală.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertContains('medium', $result['confidence']);
    }

    public function testLowConfidenceWhenFallbackTitle(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\nSome general findings.\n\nConcluzie normală.\n";

        $result = RawReportParser::parse($body, 'unknown/mioveni/010224-test.txt', []);

        self::assertContains('low', $result['confidence']);
    }

    public function testConfidenceHighAndMediumWhenBothPresent(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\nTCC cerebral.\n\nConcluzie normală.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertContains('high', $result['confidence']);
        self::assertContains('medium', $result['confidence']);
    }

    // -- Frontmatter updates ------------------------------------------------------

    public function testFrontmatterUpdatesIncludeIndicationAndExamTitle(): void
    {
        $body = "# Test Patient\n**indicație din raport**\n*01.02.2024*\nTCC cerebral.\n\nConcluzie normală fără leziuni.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertSame('indicație din raport', $result['indication']);
        self::assertSame('CT Cerebral', $result['examTitle']);
        self::assertSame('Concluzie normală fără leziuni.', $result['fmUpdates']['summary']);
        self::assertSame('CT Cerebral', $result['fmUpdates']['exam_title']);
    }

    public function testFrontmatterFixesModalityOther(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\nTCC cerebral.\n\nConcluzie normală.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', ['modality' => ['other']]);

        self::assertSame(['CT'], $result['fmUpdates']['modality']);
    }

    public function testFrontmatterFixesRegionWholeBody(): void
    {
        $body = "# Test Patient\n**indicație**\n*01.02.2024*\nTCC cerebral.\n\nConcluzie normală.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', ['region' => ['whole-body']]);

        self::assertSame(['neuro'], $result['fmUpdates']['region']);
    }

    // -- Edge cases ---------------------------------------------------------------

    public function testNoIndicationLine(): void
    {
        $body = "# Test Patient\nNo indication.\n*01.02.2024*\nBody.\n\nConclusion.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertNull($result);
    }

    public function testNullReturnForStructuredPage(): void
    {
        $body = "## CT Cerebral\n\n### Descriere\nA.\n\n### Concluzii\nB.\n";

        $result = RawReportParser::parse($body, 'ct/mioveni/010224-test.txt', []);

        self::assertNull($result);
    }
}
