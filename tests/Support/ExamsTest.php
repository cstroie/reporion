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
        self::assertSame([['title' => '', 'region' => ['msk'], 'accession' => ''], ['title' => '', 'region' => [], 'accession' => '']], Exams::declared(['exams' => [['region' => 'msk'], 'x']]));
        self::assertSame([], Exams::declared(['exams' => [['region' => 'msk']]]), 'one exam is a single-exam report');
    }

    public function testCanonicalBytesWithExamsAreStable(): void
    {
        $schema = ['title' => ['type' => 'text'], 'exams' => ['type' => 'list', 'of' => 'exam']];
        $first = Canonical::bytes(['title' => 'T'] + self::FM, self::BODY, $schema);
        [$fm, $body] = \Reporion\Support\DocumentFormat::parse($first);

        self::assertSame($first, Canonical::bytes($fm, $body, $schema));
    }

    public function testAnOldSingleExamReportBecomesAListOfOne(): void
    {
        $old = ['title' => 'Pop Ion', 'exam_title' => 'IRM genunchi', 'modality' => ['MR'], 'region' => ['msk'], 'study_date' => '2026-10-02', 'device' => 'mv-mr1',
            'accession' => 'MV-MR-26-0001', 'template' => 'templates:mri:genunchi', 'study_uid' => '1.2.3', 'protocol' => 'std', 'field_strength' => '1.5T', 'site' => 'mioveni', 'indication' => 'gonalgie'];

        $new = Exams::normalize($old);

        self::assertSame([['title' => 'IRM genunchi', 'modality' => 'MR', 'region' => ['msk'], 'study_date' => '2026-10-02', 'device' => 'mv-mr1', 'protocol' => 'std',
            'template' => 'templates:mri:genunchi', 'accession' => 'MV-MR-26-0001', 'study_uid' => '1.2.3', 'field_strength' => '1.5T']], $new['exams']);
        self::assertSame('IRM genunchi', $new['exam_title']);
        self::assertSame(['MR'], $new['modality']);
        self::assertSame('MV-MR-26-0001', $new['accession']);
        self::assertSame('mioveni', $new['site'], 'report-level fields stay');
        self::assertSame('gonalgie', $new['indication']);
        self::assertArrayNotHasKey('study_uid', $new);
        self::assertArrayNotHasKey('protocol', $new);
        self::assertArrayNotHasKey('field_strength', $new);
        self::assertSame($new, Exams::normalize($new, $new), 'idempotent');
    }

    public function testAnOldMultiExamReportGetsTheSharedValuesOnEveryExam(): void
    {
        $old = ['exam_title' => 'A + B', 'modality' => ['MR'], 'region' => ['msk'], 'study_date' => '2026-10-02', 'template' => 'templates:mri:genunchi',
            'exams' => [['title' => 'A', 'region' => ['msk'], 'accession' => 'X-1'], ['title' => 'B', 'region' => ['msk'], 'accession' => 'X-2']]];

        $new = Exams::normalize($old, $old);

        self::assertSame('MR', $new['exams'][1]['modality']);
        self::assertSame('2026-10-02', $new['exams'][1]['study_date']);
        self::assertSame('templates:mri:genunchi', $new['exams'][0]['template']);
        self::assertArrayNotHasKey('template', $new['exams'][1]);
        self::assertSame('A + B', $new['exam_title']);
        self::assertSame('X-1', $new['accession']);
    }

    public function testAnEditedTopLevelValueGoesIntoTheOneExam(): void
    {
        $before = Exams::normalize(['exam_title' => 'IRM genunchi', 'modality' => ['MR'], 'region' => ['msk']]);
        $edited = $before;
        $edited['region'] = ['msk', 'vascular'];

        self::assertSame(['msk', 'vascular'], Exams::normalize($edited, $before)['exams'][0]['region']);

        $examEdited = $before;
        $examEdited['exams'][0]['region'] = ['neuro'];
        $after = Exams::normalize($examEdited, $before);
        self::assertSame(['neuro'], $after['exams'][0]['region'], 'an unchanged top level does not overwrite the exam');
        self::assertSame(['neuro'], $after['region'], 'and is recomputed');

        $cleared = $before;
        unset($cleared['region']);
        self::assertArrayNotHasKey('region', Exams::normalize($cleared, $before)['exams'][0], 'removing the top-level key clears it');
    }

    public function testAMultiExamReportDerivesFromItsExams(): void
    {
        $fm = ['exams' => [
            ['title' => 'CT torace', 'modality' => 'CT', 'region' => ['chest'], 'study_date' => '2026-10-02T10:00:00+03:00', 'accession' => 'C-1'],
            ['title' => 'IRM cerebral', 'modality' => 'MR', 'region' => ['neuro'], 'study_date' => '2026-10-02T09:00:00+03:00', 'device' => 'mr1'],
        ]];

        $new = Exams::normalize($fm);

        self::assertSame(['CT', 'MR'], $new['modality']);
        self::assertSame(['chest', 'neuro'], $new['region']);
        self::assertSame('CT torace + IRM cerebral', $new['exam_title']);
        self::assertSame('2026-10-02T09:00:00+03:00', $new['study_date'], 'the earliest');
        self::assertSame('mr1', $new['device']);
        self::assertSame('C-1', $new['accession']);
        self::assertSame(['CT', 'MR'], Exams::normalize($new, $new)['modality']);
    }

    public function testAChangedSharedValueGoesToEveryExamOfAMultiExamReport(): void
    {
        $before = Exams::normalize(['study_date' => '2026-10-01', 'exams' => [['title' => 'A'], ['title' => 'B']]]);
        $edited = $before;
        $edited['study_date'] = '2026-10-02';

        $after = Exams::normalize($edited, $before);

        self::assertSame(['2026-10-02', '2026-10-02'], array_column($after['exams'], 'study_date'));
    }

    public function testOneDeclaredExamIsNotAMultiExamReport(): void
    {
        $fm = Exams::normalize(['exam_title' => 'IRM genunchi']);

        self::assertFalse(Exams::isMulti($fm));
        self::assertSame([], Exams::problems($fm, "no heading at all\n"));
        self::assertCount(1, Exams::of($fm));
    }

    public function testOwnKeysAreTheModalitySchemasButIndication(): void
    {
        $own = [];
        foreach (glob(\dirname(__DIR__, 2) . '/conf/schema/*.json') ?: [] as $file) {
            if (basename($file) !== 'base.json') {
                $own = [...$own, ...array_keys(json_decode((string) file_get_contents($file), true)['fields'] ?? [])];
            }
        }
        $own = array_values(array_diff(array_unique($own), ['indication']));
        sort($own);
        $listed = array_values(array_diff(Exams::OWN, ['protocol', 'study_uid', 'pacs_accession', 'order_ref']));
        sort($listed);

        self::assertSame($own, $listed);
    }
}
