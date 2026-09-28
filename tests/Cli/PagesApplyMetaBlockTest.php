<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Cli\Output;
use Reporion\Cli\PagesApplyMetaBlockCommand;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MetaBlockTask;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\RecordingIndex;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * pages:apply-meta-block — the CLI face of Service\Maintenance\MetaBlockTask
 * (TODO.md idea 10): the imported `~~META: … ~~` block filling empty
 * frontmatter and being stripped, through Storage, one revision per report.
 */
final class PagesApplyMetaBlockTest extends StorageTestCase
{
    private const PATH = 'reports:mr:mioveni:260927-test-unu';

    private const BLOCK = "~~META:\nnr       = G195\n&date    = 27.09.2026\n&name    = TEST Patient\n&age     = 45 ani\n&sex     = F\n"
        . "&section = Neurologie\n&medic   = Dr. Popescu\n&fo      = 4126\n&diag    = Cefalee\n"
        . "&exam    = IRM cerebral\n&secv    = T1 SAG, T2 COR; FLAIR TRS\n~~\n";

    private const BODY = "# TEST Patient\n\n" . self::BLOCK . "\n## IRM cerebral\n\nText.\n";

    private const FM = [
        'title' => 'TEST Patient', 'visibility' => 'private', 'status' => 'archived', 'modality' => ['MR'],
        'region' => ['neuro'], 'site' => 'mioveni', 'study_date' => '2026-09-27',
        'patient' => ['name' => 'TEST Patient'], 'imported_from' => 'fixture',
    ];

    public function testACheckWritesNothingAndApplyWritesOneRevision(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create(self::PATH, self::FM, self::BODY, 'importer');
        $command = $this->command($storage);

        $dry = $this->run2($command, []);
        self::assertStringContainsString('1 to apply', $dry);
        self::assertStringNotContainsString('test-unu', $dry, 'pages are named by pid');
        self::assertStringNotContainsString('TEST Patient', $dry, 'and never by the name');
        self::assertSame(1, $storage->read(self::PATH)->rev, 'a check writes nothing');

        $out = $this->run2($command, ['--apply', '--actor=owner']);
        self::assertStringContainsString('1 applied', $out);

        $page = $storage->read(self::PATH);
        self::assertSame(2, $page->rev, 'one new revision');
        self::assertSame("# TEST Patient\n\n## IRM cerebral\n\nText.\n", $page->body);
        self::assertSame('F', $page->frontmatter['patient']['sex']);
        self::assertSame('Dr. Popescu', $page->frontmatter['referrer']);
        self::assertStringContainsString('"reason":"meta-block-apply"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));

        self::assertStringContainsString('0 to apply (run with --apply --actor=<username>)', $this->run2($command, []), 'a second run has nothing to do');
    }

    public function testASignedReportIsListedButNotRewritten(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create(self::PATH, ['status' => 'draft'] + self::FM, self::BODY, 'owner');
        $storage->sign(self::PATH, 'owner', []);

        $out = $this->run2($this->command($storage), ['--apply', '--actor=owner']);

        self::assertStringContainsString('signed', $out);
        self::assertStringContainsString('0 applied', $out);
        self::assertSame(1, $storage->read(self::PATH)->rev);
    }

    public function testADisagreementIsListedForReviewAndLeftAlone(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $fm = self::FM;
        $fm['patient']['sex'] = 'M';
        $storage->create(self::PATH, $fm, self::BODY, 'importer');

        $out = $this->run2($this->command($storage), ['--apply', '--actor=owner']);

        self::assertStringContainsString('review', $out);
        self::assertStringContainsString('disagrees with patient.sex', $out);
        self::assertSame(1, $storage->read(self::PATH)->rev);
    }

    public function testApplyNeedsAnActor(): void
    {
        $command = $this->command(new FlatFile($this->dataRoot, new RecordingIndex()));

        self::assertSame(1, $command->run(['--apply'], new Output(fopen('php://memory', 'w+'), fopen('php://memory', 'w+'))));
    }

    private function command(FlatFile $storage): PagesApplyMetaBlockCommand
    {
        $audit = new AuditLog($this->dataRoot . '/audit');

        return new PagesApplyMetaBlockCommand(new MaintenanceRunner([new MetaBlockTask($storage, $audit)], $this->dataRoot, $audit));
    }

    /** @param list<string> $args */
    private function run2(PagesApplyMetaBlockCommand $command, array $args): string
    {
        $stdout = fopen('php://memory', 'w+');
        $command->run($args, new Output($stdout, fopen('php://memory', 'w+')));
        rewind($stdout);

        return (string) stream_get_contents($stdout);
    }
}
