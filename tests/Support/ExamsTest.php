<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Canonical;
use Reporion\Support\Exams;

/**
 * A multi-exam report (phase 12, docs/FORMATS.md §12): the exams list in
 * the frontmatter, the `##` exams in the body.
 */
final class ExamsTest extends TestCase
{
    private const FM = ['exams' => [
        ['title' => 'IRM genunchi drept', 'region' => ['msk'], 'accession' => 'MV-MR-26-0001'],
        ['title' => 'IRM genunchi stâng', 'region' => ['msk'], 'accession' => 'MV-MR-26-0002'],
    ]];
    private const BODY = "# TEST Patient Unu\n\n**Gonalgie**\n\n## IRM genunchi drept\n\nA.\n\n### Concluzii\n\nB.\n\n## IRM genunchi stâng\n\nC.\n\n### Concluzii\n\nD.\n";

    public function testTheBodySplitsIntoTheHeadAndOnePartPerExamAndBackByteForByte(): void
    {
        $split = Exams::split(self::BODY);

        self::assertSame("# TEST Patient Unu\n\n**Gonalgie**\n\n", $split['head']);
        self::assertSame(['IRM genunchi drept', 'IRM genunchi stâng'], array_column($split['parts'], 'title'));
        self::assertSame("## IRM genunchi stâng\n\nC.\n\n### Concluzii\n\nD.\n", $split['parts'][1]['text']);
        self::assertSame(self::BODY, $split['head'] . implode('', array_column($split['parts'], 'text')));
    }

    public function testOnlyATopLevelDoubleHashOutsideCodeSplits(): void
    {
        $body = "# N\n\n## A\n\n### Descriere\n\n#### Sub\n\n```\n## not an exam\n```\n\n    ## indented code\n\n## B ##\n";

        self::assertSame(['A', 'B'], array_column(Exams::split($body)['parts'], 'title'));
    }

    public function testAReportWithoutExamsIsNeverSplitNorChecked(): void
    {
        self::assertFalse(Exams::isMulti([]));
        self::assertSame([], Exams::problems([], "# N\n\n## Concluzii\n"));
    }

    public function testACompleteReportHasNoProblems(): void
    {
        self::assertSame([], Exams::problems(self::FM, self::BODY));
        self::assertSame(['MV-MR-26-0001', 'MV-MR-26-0002'], Exams::accessions(self::FM));
    }

    public function testACountMismatchIsAProblem(): void
    {
        $fm = self::FM;
        $fm['exams'][] = ['title' => 'IRM gleznă'];

        self::assertContains('exams.count', Exams::problems($fm, self::BODY));
    }

    public function testAnExamWithoutAConclusionIsAProblem(): void
    {
        $body = str_replace("C.\n\n### Concluzii\n\nD.\n", "C. Concluzii: nimic.\n\n#### Concluzii\n\nD.\n", self::BODY);

        self::assertSame(['exams.2.conclusion'], Exams::problems(self::FM, $body), 'a sentence or a #### does not count');
    }

    public function testConclusionMatchesCaseAndDiacriticsInsensitively(): void
    {
        $body = str_replace(['### Concluzii', "D.\n"], ['### CONCLUZIE', "D.\n"], self::BODY);

        self::assertSame([], Exams::problems(self::FM, $body));
    }

    public function testAnUntitledExamIsAProblemOnlyWhenItsHeadingIsEmptyToo(): void
    {
        $fm = self::FM;
        $fm['exams'][1]['title'] = '';
        self::assertSame([], Exams::problems($fm, self::BODY), 'the heading titles it');

        self::assertSame(['exams.2.title', 'exams.2.conclusion'], Exams::problems($fm, str_replace("## IRM genunchi stâng\n\nC.\n\n### Concluzii", "## \n\nC.\n\nx", self::BODY)));
    }

    public function testMalformedExamsAreReadDefensively(): void
    {
        self::assertSame([], Exams::declared(['exams' => 'x']));
        self::assertSame([['title' => '', 'region' => ['msk'], 'accession' => '']], Exams::declared(['exams' => [['region' => 'msk']]]));
    }

    public function testCanonicalBytesWithExamsAreStable(): void
    {
        $schema = ['title' => ['type' => 'text'], 'exams' => ['type' => 'list', 'of' => 'exam']];
        $first = Canonical::bytes(['title' => 'T'] + self::FM, self::BODY, $schema);
        [$fm, $body] = \Reporion\Support\DocumentFormat::parse($first);

        self::assertSame($first, Canonical::bytes($fm, $body, $schema));
    }
}
