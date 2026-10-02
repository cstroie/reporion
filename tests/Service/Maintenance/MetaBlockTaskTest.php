<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Maintenance;

use Reporion\Audit\AuditLog;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Maintenance\MetaBlockTask;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\RecordingIndex;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * pages:apply-meta-block (TODO.md idea 10): the imported `~~META: … ~~`
 * block filling empty frontmatter, most disagreements sent to review
 * rather than guessed at, `&date`/`&exam` winning theirs instead (the
 * block outranks the importer's own guess there), and the block itself
 * stripped once applied. Fixture data is fictitious throughout (invariant
 * 10).
 */
final class MetaBlockTaskTest extends StorageTestCase
{
    private const PATH = 'reports:mr:mioveni:260927-test-unu';

    private const BLOCK = "~~META:\nnr       = G195\n&date    = 27.09.2026\n&name    = TEST Patient\n&age     = 45 ani\n&sex     = F\n"
        . "&section = Neurologie\n&medic   = Dr. Popescu\n&fo      = 4126\n&diag    = Cefalee\n"
        . "&exam    = IRM cerebral\n&secv    = T1 SAG, T2 COR; FLAIR TRS\n~~\n";

    private const FM = [
        'title' => 'TEST Patient', 'visibility' => 'private', 'status' => 'archived', 'modality' => ['MR'],
        'region' => ['neuro'], 'site' => 'mioveni', 'study_date' => '2026-09-27',
        'patient' => ['name' => 'TEST Patient'], 'imported_from' => 'fixture',
    ];

    private function task(FlatFile $storage): MetaBlockTask
    {
        return new MetaBlockTask($storage, new AuditLog($this->dataRoot . '/audit'));
    }

    public function testCheckWritesNothing(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create(self::PATH, self::FM, "# TEST Patient\n\n" . self::BLOCK . "\n## IRM cerebral\n\nText.\n", 'importer');

        $report = $this->task($storage)->run(MaintenanceTask::CHECK, 'owner', ['limit' => 0]);

        self::assertSame(1, $report->summary()['would_apply']);
        self::assertSame(1, $storage->read(self::PATH)->rev, 'a check writes nothing');
    }

    public function testApplyFillsEmptyFieldsAndStripsTheBlock(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create(self::PATH, self::FM, "# TEST Patient\n\n" . self::BLOCK . "\n## IRM cerebral\n\nText.\n", 'importer');

        $report = $this->task($storage)->run(MaintenanceTask::APPLY, 'owner', ['limit' => 0]);

        self::assertSame(1, $report->summary()['applied']);
        $page = $storage->read(self::PATH);
        self::assertSame(2, $page->rev);
        self::assertSame("# TEST Patient\n\n## IRM cerebral\n\nText.\n", $page->body, 'the block is gone, one blank line where it was');
        self::assertSame('F', $page->frontmatter['patient']['sex']);
        self::assertSame(1981, $page->frontmatter['patient']['born']);
        self::assertSame('Dr. Popescu', $page->frontmatter['referrer']);
        self::assertSame('Cefalee', $page->frontmatter['indication']);
        self::assertSame('IRM cerebral', $page->frontmatter['exam_title']);
        self::assertSame(['T1 SAG', 'T2 COR', 'FLAIR TRS'], $page->frontmatter['exams'][0]['sequences'], 'on its exam (phase 27)');
        self::assertArrayNotHasKey('fo', $page->frontmatter, '&fo has no frontmatter field and is dropped with the rest of the block');
        self::assertArrayNotHasKey('nr', $page->frontmatter, 'neither does "nr"');
        self::assertStringContainsString('"reason":"meta-block-apply"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
    }

    public function testADisagreeingFieldSendsTheWholePageToReviewUntouched(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $fm = self::FM;
        $fm['patient']['sex'] = 'M';
        $storage->create(self::PATH, $fm, "# TEST Patient\n\n" . self::BLOCK . "\n## IRM cerebral\n\nText.\n", 'importer');

        $report = $this->task($storage)->run(MaintenanceTask::APPLY, 'owner', ['limit' => 0]);

        self::assertSame(1, $report->summary()['review']);
        self::assertStringContainsString('"&sex" (F) disagrees with patient.sex (M)', $report->items()[0]['detail']);
        self::assertSame(1, $storage->read(self::PATH)->rev, 'a disagreement is never applied');
    }

    public function testDateAndExamOverrideRatherThanReview(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $fm = self::FM;
        $fm['study_date'] = '2026-01-05';
        $fm['exam_title'] = 'CT abdomen';
        $storage->create(self::PATH, $fm, "# TEST Patient\n\n" . self::BLOCK . "\n## IRM cerebral\n\nText.\n", 'importer');

        $report = $this->task($storage)->run(MaintenanceTask::APPLY, 'owner', ['limit' => 0]);

        self::assertSame(0, $report->summary()['review'], '&date/&exam win instead of blocking the page');
        self::assertSame(1, $report->summary()['applied']);
        $page = $storage->read(self::PATH);
        self::assertSame('2026-09-27', substr((string) $page->frontmatter['study_date'], 0, 10));
        self::assertSame('IRM cerebral', $page->frontmatter['exam_title']);
    }

    public function testAMalformedBlockIsListedUnparseableAndLeftAlone(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $broken = "~~META:\n&date    = 27.09.2026\n&fo      =&diag    = Sensitive text\n~~\n";
        $storage->create(self::PATH, self::FM, "# TEST Patient\n\n" . $broken . "\n## IRM cerebral\n\nText.\n", 'importer');

        $report = $this->task($storage)->run(MaintenanceTask::APPLY, 'owner', ['limit' => 0]);

        self::assertSame(1, $report->summary()['unparseable']);
        self::assertSame(1, $storage->read(self::PATH)->rev);
    }

    public function testASignedReportIsListedButNotRewritten(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create(self::PATH, ['status' => 'draft'] + self::FM, "# TEST Patient\n\n" . self::BLOCK . "\n## IRM cerebral\n\nText.\n", 'owner');
        $storage->sign(self::PATH, 'owner', []);

        $report = $this->task($storage)->run(MaintenanceTask::APPLY, 'owner', ['limit' => 0]);

        self::assertSame(1, $report->summary()['signed']);
        self::assertSame(1, $storage->read(self::PATH)->rev);
    }

    public function testAPageWithNoBlockIsSkippedSilently(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create(self::PATH, self::FM, "# TEST Patient\n\n## IRM cerebral\n\nText.\n", 'importer');

        $report = $this->task($storage)->run(MaintenanceTask::APPLY, 'owner', ['limit' => 0]);

        self::assertSame(0, $report->summary()['applied']);
        self::assertSame(0, $report->summary()['review']);
    }
}
