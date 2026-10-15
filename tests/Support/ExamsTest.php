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

    public function testThePacsAccessionsAreTheStudiesNumbersNotOurs(): void
    {
        self::assertSame([], Exams::pacsAccessions(self::FM), 'none given');
        $fm = self::FM;
        $fm['exams'][0]['pacs_accession'] = 'PX1';
        $fm['exams'][1]['pacs_accession'] = 'PX2';
        self::assertSame(['PX1', 'PX2'], Exams::pacsAccessions($fm));
        self::assertSame(['PX9'], Exams::pacsAccessions(['pacs_accession' => 'PX9']), 'the old single-exam shape');
        self::assertSame(['PX3'], Exams::pacsAccessions(['exams' => [['title' => 'A', 'pacs_accession' => 'PX3']]]));
        self::assertSame(['PX4'], Exams::pacsAccessions(['exams' => [['pacs_accession' => 'PX4'], ['pacs_accession' => 'PX4']]]), 'one study for two exams: once');
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
        $old = ['title' => 'Pop Ion', 'exam_title' => 'IRM genunchi', 'modality' => ['MR'], 'region' => ['msk'], 'study_date' => '2026-10-02T09:10:00+03:00', 'device' => 'mv-mr1',
            'accession' => 'MV-MR-26-0001', 'template' => 'templates:mri:genunchi', 'study_uid' => '1.2.3', 'protocol' => 'std', 'field_strength' => '1.5T', 'site' => 'mioveni', 'indication' => 'gonalgie'];

        $new = Exams::normalize($old);

        self::assertSame([['title' => 'IRM genunchi', 'time' => '09:10', 'region' => ['msk'], 'template' => 'templates:mri:genunchi', 'protocol' => 'std',
            'accession' => 'MV-MR-26-0001', 'study_uid' => '1.2.3', 'field_strength' => '1.5T']], $new['exams']);
        self::assertSame(['MR'], $new['modality'], 'the report is of one modality');
        self::assertSame('mv-mr1', $new['device']);
        self::assertSame('2026-10-02', $new['study_date'], 'the day only; the time is the exam\'s');
        foreach (['exam_title', 'region', 'accession', 'template', 'study_uid', 'protocol', 'field_strength'] as $gone) {
            self::assertArrayNotHasKey($gone, $new, $gone . ' is on the exam, not copied up');
        }
        self::assertSame('mioveni', $new['site'], 'report-level fields stay');
        self::assertSame('gonalgie', $new['indication']);
        self::assertSame($new, Exams::normalize($new, $new), 'idempotent');
    }

    public function testAnOldMultiExamReportKeepsEachExamsOwnTemplateAndTakesTheSharedValuesFromTheReport(): void
    {
        $old = ['exam_title' => 'A + B', 'modality' => ['MR'], 'region' => ['msk'], 'study_date' => '2026-10-02T09:00:00+03:00', 'device' => 'mr1', 'template' => 'templates:mri:a', 'accession' => 'X-1',
            'exams' => [
                ['title' => 'A', 'modality' => 'MR', 'region' => ['msk'], 'accession' => 'X-1', 'study_date' => '2026-10-02T09:00:00+03:00', 'template' => 'templates:mri:a'],
                ['title' => 'B', 'modality' => 'MR', 'region' => ['msk'], 'accession' => 'X-2', 'study_date' => '2026-10-02T09:40:00+03:00', 'template' => 'templates:mri:b'],
            ]];

        $new = Exams::normalize($old, $old);

        self::assertSame(['09:00', '09:40'], array_column($new['exams'], 'time'));
        self::assertSame(['templates:mri:a', 'templates:mri:b'], array_column($new['exams'], 'template'));
        self::assertSame(['MR'], $new['modality']);
        self::assertSame('mr1', $new['device']);
        self::assertSame('2026-10-02', $new['study_date']);
        foreach (['exam_title', 'region', 'accession', 'template'] as $gone) {
            self::assertArrayNotHasKey($gone, $new);
        }
        foreach ($new['exams'] as $exam) {
            self::assertArrayNotHasKey('modality', $exam);
            self::assertArrayNotHasKey('study_date', $exam);
        }
    }

    public function testTheTopLevelTemplateOfAReportWithSeveralExamsIsNoExamsTemplate(): void
    {
        // The first exam's copy (older saves): the second exam must never inherit it
        $fm = ['template' => 'templates:mri:a', 'exams' => [['title' => 'A', 'template' => 'templates:mri:a'], ['title' => 'B']]];

        self::assertSame(['templates:mri:a'], array_column(Exams::of($fm), 'template'));
        self::assertArrayNotHasKey('template', Exams::of($fm)[1]);
        self::assertArrayNotHasKey('template', Exams::normalize($fm, $fm)['exams'][1]);
        // A report of one exam took its template from the top level before exams: existed
        self::assertSame('templates:mri:a', Exams::of(['template' => 'templates:mri:a', 'exams' => [['title' => 'A']]])[0]['template']);
    }

    public function testAnEditedTopLevelValueGoesIntoTheOneExam(): void
    {
        $before = Exams::normalize(['exam_title' => 'IRM genunchi', 'modality' => ['MR'], 'region' => ['msk']]);
        $edited = $before;
        $edited['region'] = ['msk', 'vascular'];

        $after = Exams::normalize($edited, $before);
        self::assertSame(['msk', 'vascular'], $after['exams'][0]['region']);
        self::assertArrayNotHasKey('region', $after, 'and is not kept at the top level');

        $examEdited = $before;
        $examEdited['exams'][0]['region'] = ['neuro'];
        self::assertSame(['neuro'], Exams::normalize($examEdited, $before)['exams'][0]['region'], 'an unchanged top level does not overwrite the exam');
    }

    /** D48: saving a report of the current shape over an older revision converts it and loses nothing */
    public function testSavingTheCurrentShapeOverAnOlderRevisionKeepsWhatTheExamHolds(): void
    {
        $older = ['exam_title' => 'IRM genunchi', 'modality' => ['MR'], 'region' => ['msk'], 'template' => 'templates:mri:genunchi', 'accession' => 'MV-MR-26-0001', 'study_date' => '2026-10-02T09:10:00+03:00'];
        $current = Exams::normalize($older, $older);

        $saved = Exams::normalize($current, $older);

        self::assertSame($current, $saved, 'a key absent at the top level is no deletion');
        self::assertSame('templates:mri:genunchi', $saved['exams'][0]['template']);
        self::assertSame(['msk'], $saved['exams'][0]['region']);
        self::assertSame('09:10', $saved['exams'][0]['time']);
    }

    public function testWhatAnOlderRevisionKeptOnItsExamsIsNotLostWhenTheCardsRewriteThem(): void
    {
        $before = ['exams' => [
            ['title' => 'A', 'modality' => 'MR', 'device' => 'd1', 'study_date' => '2026-10-02T09:00:00+03:00'],
            ['title' => 'B', 'modality' => 'MR', 'device' => 'd1', 'study_date' => '2026-10-02T09:40:00+03:00'],
        ]];
        // The editor's cards post the exams without those keys
        $posted = ['exams' => [['title' => 'A', 'time' => '09:00'], ['title' => 'B', 'time' => '09:40']]];

        $saved = Exams::normalize($posted, $before);

        self::assertSame(['MR'], $saved['modality']);
        self::assertSame('d1', $saved['device']);
        self::assertSame('2026-10-02', $saved['study_date']);

        // A shared value the user cleared on the report is cleared, not brought back
        $cleared = Exams::normalize(['modality' => ['MR'], 'exams' => [['title' => 'A']]]);
        unset($cleared['modality']);
        self::assertArrayNotHasKey('modality', Exams::normalize($cleared, ['modality' => ['MR'], 'exams' => [['title' => 'A']]]));
    }

    public function testEveryHistoricShapeNormalizesToTheSameCurrentOne(): void
    {
        $flat = ['exam_title' => 'IRM genunchi', 'modality' => ['MR'], 'region' => ['msk'], 'device' => 'd1', 'template' => 'templates:mri:g', 'accession' => 'X-1', 'protocol' => 'std', 'study_date' => '2026-10-02T09:10:00+03:00'];
        $perExam = ['exam_title' => 'IRM genunchi', 'modality' => ['MR'], 'region' => ['msk'], 'device' => 'd1', 'template' => 'templates:mri:g', 'accession' => 'X-1', 'study_date' => '2026-10-02T09:10:00+03:00',
            'exams' => [['title' => 'IRM genunchi', 'modality' => 'MR', 'region' => ['msk'], 'device' => 'd1', 'study_date' => '2026-10-02T09:10:00+03:00', 'protocol' => 'std', 'template' => 'templates:mri:g', 'accession' => 'X-1']]];
        $current = ['modality' => ['MR'], 'device' => 'd1', 'study_date' => '2026-10-02',
            'exams' => [['title' => 'IRM genunchi', 'time' => '09:10', 'region' => ['msk'], 'template' => 'templates:mri:g', 'protocol' => 'std', 'accession' => 'X-1']]];

        self::assertSame($current, Exams::normalize($flat, $flat));
        self::assertSame($current, Exams::normalize($perExam, $perExam));
        self::assertSame($current, Exams::normalize($current, $current));
        self::assertSame($current, Exams::normalize($flat), 'a new page from an older shape');
    }

    public function testAnUnquotedYamlDateIsTheReportsDayAndHasNoTime(): void
    {
        // Symfony YAML reads an unquoted date as a timestamp at midnight UTC
        $fm = ['modality' => ['MR'], 'study_date' => strtotime('2026-10-02 00:00:00 UTC'), 'exams' => [['title' => 'A']]];

        self::assertSame('2026-10-02', Exams::normalize($fm)['study_date']);
        self::assertArrayNotHasKey('time', Exams::normalize($fm)['exams'][0], 'midnight claims no time');
        self::assertSame('2026-10-02', Exams::report($fm)['moment']);
    }

    public function testAnExamWithoutATemplateStaysWithoutOne(): void
    {
        $fm = Exams::normalize(['exams' => [['title' => 'A', 'template' => 'templates:mri:a'], ['title' => 'B']]]);

        self::assertSame(['templates:mri:a'], array_column($fm['exams'], 'template'), 'B is not given A\'s');
        self::assertSame(['', ''], [Exams::of($fm)[1]['template'] ?? '', Exams::of($fm)[1]['time'] ?? ''], 'nothing is made up for an exam');
    }

    public function testAMalformedExamsListIsReadDefensivelyAsOneEmptyExam(): void
    {
        foreach ([['exams' => 'x'], ['exams' => []], ['exams' => ['a' => 1]], ['exams' => [null, 'x']]] as $fm) {
            self::assertNotSame([], Exams::of($fm), 'a report has one exam at least');
            self::assertIsArray(Exams::normalize($fm)['exams']);
        }
    }

    public function testATimeWrittenAtTheTopLevelByAPluginIsTheFirstExamsTime(): void
    {
        $before = Exams::normalize(['modality' => ['MR'], 'study_date' => '2026-10-02', 'exams' => [['title' => 'A'], ['title' => 'B', 'time' => '10:00']]]);
        $filled = $before;
        $filled['study_date'] = '2026-10-02T09:10:00+03:00';

        $after = Exams::normalize($filled, $before);

        self::assertSame('2026-10-02', $after['study_date']);
        self::assertSame(['09:10', '10:00'], array_column($after['exams'], 'time'));
    }

    public function testTheReportIsReadInEveryHistoricShape(): void
    {
        $flat = ['modality' => ['MR'], 'device' => 'd1', 'study_date' => '2026-10-02T09:10:00+03:00'];
        self::assertSame(['modality' => ['MR'], 'device' => 'd1', 'date' => '2026-10-02', 'time' => '09:10', 'moment' => '2026-10-02T09:10:00'], Exams::report($flat));

        $perExam = ['exams' => [
            ['title' => 'B', 'modality' => 'MR', 'device' => 'd2', 'study_date' => '2026-10-02T10:00:00+03:00'],
            ['title' => 'A', 'modality' => 'MR', 'study_date' => '2026-10-02T09:00:00+03:00'],
        ]];
        self::assertSame(['modality' => ['MR'], 'device' => 'd2', 'date' => '2026-10-02', 'time' => '09:00', 'moment' => '2026-10-02T09:00:00'], Exams::report($perExam));

        $current = ['modality' => ['CT'], 'study_date' => '2026-10-02', 'exams' => [['title' => 'A'], ['title' => 'B', 'time' => '11:30']]];
        self::assertSame('2026-10-02T11:30:00', Exams::report($current)['moment']);
        self::assertSame('2026-10-02', Exams::report(['study_date' => '2026-10-02', 'exams' => [['title' => 'A']]])['moment'], 'no time: the day');
        self::assertSame('', Exams::report([])['moment']);
    }

    public function testATitleAndTheRegionsOfSeveralExamsAreJoinedForDisplay(): void
    {
        $fm = ['exams' => [['title' => 'CT torace', 'region' => ['chest']], ['title' => 'CT cerebral', 'region' => ['neuro', 'chest']]]];

        self::assertSame('CT torace + CT cerebral', Exams::title($fm));
        self::assertSame(['chest', 'neuro'], Exams::regions($fm));
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
        foreach (glob(\dirname(__DIR__, 2) . '/resources/schema/*.json') ?: [] as $file) {
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
