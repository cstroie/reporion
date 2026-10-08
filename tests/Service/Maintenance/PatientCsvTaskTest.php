<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Maintenance;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Service\Maintenance\MaintenanceReport;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Maintenance\PatientCsvTask;
use Reporion\Storage\FlatFile;
use Reporion\Support\Exams;
use Reporion\Tests\Storage\RecordingIndex;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * pages:apply-patient-csv: a booking table filling CNP, birth year, sex,
 * and indication into the reports it names without doubt, and
 * sending everything doubtful to review untouched. Names and CNPs here are
 * made up (invariant 10).
 */
final class PatientCsvTaskTest extends StorageTestCase
{
    private const NS = 'reports:mr:polimed';

    private const HEADER = '"DATA","NUME","PRENUME","CNP","SEGMENT ANALIZAT","DATE CLINICE","MEDIC INTERPR.","DOCUMENTE","STATUS"';

    private const FM = [
        'title' => 'Test Pacient', 'visibility' => 'private', 'status' => 'archived', 'modality' => ['MR'],
        'region' => ['neuro'], 'site' => 'polimed', 'study_date' => '2022-12-15', 'imported_from' => 'fixture',
        'patient' => ['name' => 'TEST PACIENT Unu', 'born' => null, 'sex' => null, 'cnp' => null],
    ];

    /** A male born 2001-02-03: a checksum-valid CNP */
    private function cnp(string $first12 = '501020340123'): string
    {
        return $first12 . \Reporion\Support\Cnp::checksum($first12);
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new RecordingIndex());
    }

    private function page(FlatFile $storage, string $leaf, array $fm = []): string
    {
        $path = self::NS . ':' . $leaf;
        $storage->create($path, array_replace_recursive(self::FM, $fm), "# Test Pacient\n\n## IRM Cerebral\n\nText.\n", 'importer');

        return $path;
    }

    /** @param list<string> $lines table rows after the header; line 1 is the header */
    private function apply(FlatFile $storage, string $mode, array $lines, int $limit = 0, bool $loose = false): MaintenanceReport
    {
        $file = $this->dataRoot . '/table.csv';
        file_put_contents($file, self::HEADER . "\n" . implode("\n", $lines) . "\n");
        $task = new PatientCsvTask($storage, new AuditLog($this->dataRoot . '/audit'));

        return $task->run($mode, 'owner', $task->options(['from' => $file, 'namespace' => self::NS, 'limit' => $limit, 'loose_names' => $loose]));
    }

    private function row(string $date, string $surname, string $given, string $cnp, string $segment = 'IRM CEREBRAL NATIV', string $clinical = 'CEFALEE; '): string
    {
        return \sprintf('"%s","%s","%s","%s","%s","%s","DR X","","INTERPRETAT"', $date, $surname, $given, $cnp, $segment, $clinical);
    }

    public function testApplyFillsTheBlanksOfTheOneReportTheRowNames(): void
    {
        $storage = $this->storage();
        $path = $this->page($storage, '221215-pacient-test-unu', ['exam_title' => 'IRM Cerebral']);

        $report = $this->apply($storage, MaintenanceTask::APPLY, [$this->row('15.12.2022', 'PACIENT', 'TEST UNU', $this->cnp())]);

        self::assertSame(1, $report->summary()['applied']);
        $page = $storage->read($path);
        self::assertSame(2, $page->rev);
        self::assertSame($this->cnp(), $page->frontmatter['patient']['cnp']);
        self::assertSame(2001, $page->frontmatter['patient']['born']);
        self::assertSame('M', $page->frontmatter['patient']['sex']);
        self::assertSame('CEFALEE', $page->frontmatter['indication'], 'trimmed, the trailing ; gone');
        self::assertSame('IRM Cerebral', Exams::of($page->frontmatter)[0]['title'], 'the title is not replaced by the table\'s wording');
        self::assertSame(1, $report->summary()['exam_kept']);
        self::assertSame("# Test Pacient\n\n## IRM Cerebral\n\nText.\n", $page->body, 'the body is not touched');
        self::assertStringContainsString('"reason":"patient-csv-apply"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
    }

    public function testCheckWritesNothingAndTheReportNamesNobody(): void
    {
        $storage = $this->storage();
        $path = $this->page($storage, '221215-pacient-test-unu');

        $report = $this->apply($storage, MaintenanceTask::CHECK, [$this->row('15.12.2022', 'PACIENT', 'TEST UNU', $this->cnp())]);

        self::assertSame(1, $report->summary()['would_apply']);
        self::assertSame(1, $storage->read($path)->rev);
        $json = $report->toJson();
        foreach (['PACIENT', 'pacient', $this->cnp()] as $secret) {
            self::assertStringNotContainsString($secret, $json, 'a report carries pids and line numbers, never a name, a CNP or a path');
        }
    }

    public function testEitherNameOrderMatches(): void
    {
        $storage = $this->storage();
        $path = $this->page($storage, '221215-test-unu-pacient');

        $this->apply($storage, MaintenanceTask::APPLY, [$this->row('15.12.2022', 'PACIENT', 'TEST UNU', $this->cnp())]);

        self::assertSame(2, $storage->read($path)->rev);
    }

    public function testTheDayPicksBetweenSeveralReportsOfOnePatient(): void
    {
        $storage = $this->storage();
        $first = $this->page($storage, '221215-pacient-test-unu');
        $second = $this->page($storage, '230110-pacient-test-unu');

        $this->apply($storage, MaintenanceTask::APPLY, [$this->row('10.01.2023', 'PACIENT', 'TEST UNU', $this->cnp())]);

        self::assertSame(1, $storage->read($first)->rev);
        self::assertSame(2, $storage->read($second)->rev);
    }

    public function testANameOnANearbyDayIsReviewedNotApplied(): void
    {
        $storage = $this->storage();
        $path = $this->page($storage, '221219-pacient-test-unu');

        $report = $this->apply($storage, MaintenanceTask::APPLY, [$this->row('15.12.2022', 'PACIENT', 'TEST UNU', $this->cnp())]);

        self::assertSame(1, $report->summary()['review']);
        self::assertSame(0, $report->summary()['applied']);
        self::assertSame(1, $storage->read($path)->rev);
    }

    public function testAGivenNameMissingOnOneSideIsReviewedUnlessLooseNamesIsOn(): void
    {
        $storage = $this->storage();
        $path = $this->page($storage, '221215-pacient-test');
        $line = $this->row('15.12.2022', 'PACIENT', 'TEST UNU', $this->cnp());

        $report = $this->apply($storage, MaintenanceTask::APPLY, [$line]);
        self::assertSame(1, $report->summary()['review']);
        self::assertSame(1, $storage->read($path)->rev);

        $report = $this->apply($storage, MaintenanceTask::APPLY, [$line], loose: true);
        self::assertSame(1, $report->summary()['applied']);
        self::assertSame(2, $storage->read($path)->rev);
    }

    public function testASingleSharedTokenIsNotAMatchAtAll(): void
    {
        $storage = $this->storage();
        $this->page($storage, '221215-pacient-altul');

        $report = $this->apply($storage, MaintenanceTask::CHECK, [$this->row('15.12.2022', 'PACIENT', 'TEST UNU', $this->cnp())], loose: true);

        self::assertSame(1, $report->summary()['unmatched']);
    }

    public function testARowWithNoReportIsUnmatchedByLine(): void
    {
        $report = $this->apply($this->storage(), MaintenanceTask::CHECK, [$this->row('15.12.2022', 'NECUNOSCUT', 'ALT NUME', $this->cnp())]);

        self::assertSame(1, $report->summary()['unmatched']);
        self::assertStringContainsString('line 2', $report->items()[0]['detail']);
    }

    public function testAnInvalidCnpIsNeverWrittenButTheIndicationStillIs(): void
    {
        $storage = $this->storage();
        $path = $this->page($storage, '221215-pacient-test-unu');
        $wrong = substr($this->cnp(), 0, 12) . ((int) substr($this->cnp(), 12) + 1) % 10;

        $report = $this->apply($storage, MaintenanceTask::APPLY, [$this->row('15.12.2022', 'PACIENT', 'TEST UNU', $wrong)]);

        self::assertSame(1, $report->summary()['invalid_cnp']);
        $page = $storage->read($path);
        self::assertNull($page->frontmatter['patient']['cnp']);
        self::assertNull($page->frontmatter['patient']['born']);
        self::assertSame('CEFALEE', $page->frontmatter['indication']);
    }

    public function testADifferentCnpAlreadyOnTheReportSendsItToReview(): void
    {
        $storage = $this->storage();
        $path = $this->page($storage, '221215-pacient-test-unu', ['patient' => ['cnp' => $this->cnp('600101010011')]]);

        $report = $this->apply($storage, MaintenanceTask::APPLY, [$this->row('15.12.2022', 'PACIENT', 'TEST UNU', $this->cnp())]);

        self::assertSame(1, $report->summary()['review']);
        self::assertSame(1, $storage->read($path)->rev);
    }

    public function testASexOrBirthYearThatContradictsTheCnpSendsItToReview(): void
    {
        $storage = $this->storage();
        $path = $this->page($storage, '221215-pacient-test-unu', ['patient' => ['sex' => 'F']]);

        $report = $this->apply($storage, MaintenanceTask::APPLY, [$this->row('15.12.2022', 'PACIENT', 'TEST UNU', $this->cnp())]);

        self::assertSame(1, $report->summary()['review']);
        self::assertSame(1, $storage->read($path)->rev);
    }

    public function testTwoRowsForOneReportAreAConflict(): void
    {
        $storage = $this->storage();
        $path = $this->page($storage, '221215-pacient-test-unu');

        $report = $this->apply($storage, MaintenanceTask::APPLY, [
            $this->row('15.12.2022', 'PACIENT', 'TEST UNU', $this->cnp()),
            $this->row('15.12.2022', 'PACIENT', 'TEST UNU', $this->cnp('600101010011'), 'IRM GENUNCHI NATIV'),
        ]);

        self::assertSame(1, $report->summary()['review']);
        self::assertSame(1, $storage->read($path)->rev);
    }

    public function testAnExistingExamTitleAndIndicationAreKept(): void
    {
        $storage = $this->storage();
        $path = $this->page($storage, '221215-pacient-test-unu', ['exam_title' => 'IRM Cerebral', 'indication' => 'Scris de mână']);

        $report = $this->apply($storage, MaintenanceTask::APPLY, [$this->row('15.12.2022', 'PACIENT', 'TEST UNU', $this->cnp())]);

        $page = $storage->read($path);
        self::assertSame('IRM Cerebral', $page->frontmatter['exam_title']);
        self::assertSame('Scris de mână', $page->frontmatter['indication']);
        self::assertSame(1, $report->summary()['exam_kept']);
        self::assertSame($this->cnp(), $page->frontmatter['patient']['cnp'], 'the blanks around them are still filled');
    }

    public function testASignedReportIsListedButNotRewritten(): void
    {
        $storage = $this->storage();
        $path = $this->page($storage, '221215-pacient-test-unu', ['status' => 'draft']);
        $storage->sign($path, 'owner', []);

        $report = $this->apply($storage, MaintenanceTask::APPLY, [$this->row('15.12.2022', 'PACIENT', 'TEST UNU', $this->cnp())]);

        self::assertSame(1, $report->summary()['signed']);
        self::assertSame(1, $storage->read($path)->rev);
    }

    public function testAnUnreadableRowIsListedByLineAndTheRestStillRuns(): void
    {
        $storage = $this->storage();
        $path = $this->page($storage, '221215-pacient-test-unu');

        $report = $this->apply($storage, MaintenanceTask::APPLY, [
            $this->row('151222', 'PACIENT', 'TEST UNU', $this->cnp()),
            $this->row('15.12.2022', 'PACIENT', 'TEST UNU', $this->cnp()),
        ]);

        self::assertSame(1, $report->summary()['unreadable_rows']);
        self::assertSame(1, $report->summary()['applied']);
        self::assertSame(2, $storage->read($path)->rev);
    }

    public function testLimitLeavesTheRestForTheNextRun(): void
    {
        $storage = $this->storage();
        $this->page($storage, '221215-pacient-test-unu');
        $this->page($storage, '221216-pacient-test-doi', ['patient' => ['name' => 'TEST PACIENT Doi']]);

        $report = $this->apply($storage, MaintenanceTask::APPLY, [
            $this->row('15.12.2022', 'PACIENT', 'TEST UNU', $this->cnp()),
            $this->row('16.12.2022', 'PACIENT', 'TEST DOI', $this->cnp('600101010011')),
        ], limit: 1);

        self::assertSame(1, $report->summary()['applied']);
        self::assertSame(1, $report->summary()['remaining']);
    }

    public function testATableThatIsNotThereIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PatientCsvTask($this->storage(), new AuditLog($this->dataRoot . '/audit')))->options(['from' => $this->dataRoot . '/nope.csv']);
    }
}
