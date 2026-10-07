<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\ReportName;

/**
 * The name heading and the exam heading in what exports and the public
 * layout print (D30, docs/FORMATS.md §11).
 */
final class ReportNameTest extends TestCase
{
    private const FM = ['title' => 'TEST Patient Unu', 'exam_title' => 'IRM Cerebral', 'patient' => ['name' => 'TEST Patient Unu']];

    /**
     * @return iterable<string, array{string}>
     */
    public static function nameHeadings(): iterable
    {
        yield 'normalized, #' => ['#'];
        yield 'imported, ##' => ['##'];
        yield 'imported, ###' => ['###'];
    }

    /**
     * @dataProvider nameHeadings
     */
    public function testTheNameHeadingGoesAtAnyLevel(string $marks): void
    {
        self::assertSame("Text.\n", ReportName::withoutNameHeading($marks . " TEST Patient Unu\n\nText.\n", self::FM));
    }

    public function testTheTitleIsTheNameTooWhenAHisSpelledItOtherwise(): void
    {
        $fm = ['title' => 'Levandovskyi Test', 'exam_title' => 'CT Cerebral', 'patient' => ['name' => 'LEVANDOVSKI Test']];

        self::assertSame("Text.\n", ReportName::withoutNameHeading("# Levandovskyi Test\n\nText.\n", $fm), 'the title, as the report spells the name');
        self::assertSame("Text.\n", ReportName::withoutNameHeading("# LEVANDOVSKI Test\n\nText.\n", $fm), 'the HIS/PACS spelling');
        self::assertSame("Text.\n", ReportName::forExport("# Levandovskyi Test\n\nText.\n", $fm), 'so no export or public page prints it');
        self::assertSame("# Setup\n\nText.\n", ReportName::withoutNameHeading("# Setup\n\nText.\n", ['title' => 'Setup']), 'a page without a patient keeps its title heading');
        $imported = ['title' => 'IRM Cerebral', 'patient' => ['name' => 'TEST Patient Unu']];
        self::assertSame("# IRM Cerebral\n\nText.\n", ReportName::withoutNameHeading("# IRM Cerebral\n\nText.\n", $imported), 'an imported report: its title is the exam');
        self::assertSame('IRM Cerebral', ReportName::examTitle($imported));
    }

    public function testAHeadingThatIsNotTheNameStays(): void
    {
        $body = "## Politraumatism\n\nText.\n";

        self::assertSame($body, ReportName::withoutNameHeading($body, self::FM));
    }

    public function testAnExportDropsALoneExamHeadingThatIsItsTitle(): void
    {
        $body = "# TEST Patient Unu\n\n**Cefalee**\n\n## IRM cerebral\n\nText.\n\n### Concluzii\n\nC.\n";

        self::assertSame("**Cefalee**\n\nText.\n\n### Concluzii\n\nC.\n", ReportName::forExport($body, self::FM));
    }

    public function testAnExportKeepsAnExamHeadingThatIsNotTheTitle(): void
    {
        $body = "# TEST Patient Unu\n\n## IRM cerebral nativ\n\nText.\n";

        self::assertSame("## IRM cerebral nativ\n\nText.\n", ReportName::forExport($body, self::FM));
    }

    public function testAnExportKeepsEveryExamHeadingOfSeveral(): void
    {
        $fm = ['exam_title' => 'CT Cerebral + CT Torace'] + self::FM;
        $body = "# TEST Patient Unu\n\n## CT Cerebral\n\nA.\n\n## CT Torace\n\nB.\n\n## Concluzii\n\nC.\n";

        self::assertSame("## CT Cerebral\n\nA.\n\n## CT Torace\n\nB.\n\n## Concluzii\n\nC.\n", ReportName::forExport($body, $fm));
    }
}
