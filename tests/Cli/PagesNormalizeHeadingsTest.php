<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Cli\Output;
use Reporion\Cli\PagesNormalizeHeadingsCommand;
use Reporion\Service\Maintenance\HeadingNormalizeTask;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\RecordingIndex;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * pages:normalize-headings — the archive's headings to the one shape
 * (docs/FORMATS.md §11), through Storage, one revision per report.
 */
final class PagesNormalizeHeadingsTest extends StorageTestCase
{
    private const PATH = 'reports:ct:mioveni:240201-test-unu';
    private const FM = [
        'title' => 'TEST Patient Unu', 'visibility' => 'private', 'status' => 'archived', 'modality' => ['CT'],
        'region' => ['neuro', 'chest'], 'site' => 'mioveni', 'study_date' => '2024-02-01',
        'patient' => ['name' => 'TEST Patient Unu', 'sex' => 'M'], 'imported_from' => 'fixture',
    ];
    private const BODY = "## TEST Patient Unu\n\n**Politraumatism**\n\n### CT Cerebral\n\nA.\n\n### CT Torace\n\nB.\n\n### Concluzii\n\nC.\n";

    public function testACheckWritesNothingAndApplyWritesOneRevision(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create(self::PATH, self::FM, self::BODY, 'importer');
        $command = $this->command($storage);

        $dry = $this->run2($command, []);
        self::assertStringContainsString('1 to normalize', $dry);
        self::assertStringNotContainsString('test-unu', $dry, 'pages are named by pid');
        self::assertStringNotContainsString('TEST Patient', $dry, 'and never by the name');
        self::assertSame(1, $storage->read(self::PATH)->rev, 'a check writes nothing');

        $out = $this->run2($command, ['--apply', '--actor=owner']);
        self::assertStringContainsString('1 normalized', $out);

        $page = $storage->read(self::PATH);
        self::assertSame(2, $page->rev, 'one new revision');
        self::assertSame("# TEST Patient Unu\n\n**Politraumatism**\n\n## CT Cerebral\n\nA.\n\n## CT Torace\n\nB.\n\n## Concluzii\n\nC.\n", $page->body);
        self::assertSame('CT Cerebral + CT Torace', $page->frontmatter['exam_title']);
        self::assertSame('archived', $page->status, 'an imported report stays archived');
        self::assertStringContainsString('"reason":"heading-normalize"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));

        self::assertStringContainsString('0 to normalize (run with --apply --actor=<username>); 1 unchanged', $this->run2($command, []), 'a second run has nothing to do');
    }

    public function testAnExistingExamTitleIsKept(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create(self::PATH, ['exam_title' => 'CT politraumă'] + self::FM, self::BODY, 'importer');

        $this->run2($this->command($storage), ['--apply', '--actor=owner']);

        self::assertSame('CT politraumă', $storage->read(self::PATH)->frontmatter['exam_title']);
    }

    public function testASignedReportIsListedButNotRewritten(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create(self::PATH, ['status' => 'draft'] + self::FM, self::BODY, 'owner');
        $storage->sign(self::PATH, 'owner', []);

        $out = $this->run2($this->command($storage), ['--apply', '--actor=owner']);

        self::assertStringContainsString('signed', $out);
        self::assertStringContainsString('0 normalized', $out);
        self::assertSame(1, $storage->read(self::PATH)->rev);
    }

    public function testWhatTheRulesCannotPlaceIsListedAndLeftAlone(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $body = "## Politraumatism\n\n### CT Cerebral\n\nA.\n";
        $storage->create(self::PATH, self::FM, $body, 'importer');

        $out = $this->run2($this->command($storage), ['--apply', '--actor=owner']);

        self::assertStringContainsString('review — O2 E3 — first heading is not the patient name', $out);
        self::assertStringContainsString('1 to review by hand', $out);
        self::assertSame($body, $storage->read(self::PATH)->body);
    }

    public function testOnlyReportsAreTouched(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create('docs:protocols:ct-cerebral', ['title' => 'Protocol', 'visibility' => 'private'], "## Protocol\n\n### Tehnică\n", 'owner');

        self::assertStringContainsString('0 to normalize (run with --apply --actor=<username>); 0 unchanged; 0 to review', $this->run2($this->command($storage), []));
    }

    public function testTheLimitLeavesTheRestForTheNextRun(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create(self::PATH, self::FM, self::BODY, 'importer');
        $storage->create('reports:ct:mioveni:240202-test-doi', ['patient' => ['name' => 'TEST Patient Doi']] + self::FM, str_replace('Unu', 'Doi', self::BODY), 'importer');
        $command = $this->command($storage);

        self::assertStringContainsString('1 normalized; 0 unchanged; 0 to review by hand; 0 signed (correct and re-sign by hand); 1 left for the next run', $this->run2($command, ['--apply', '--actor=owner', '--limit=1']));
        self::assertStringContainsString('1 normalized; 1 unchanged', $this->run2($command, ['--apply', '--actor=owner', '--limit=1']));
    }

    public function testApplyNeedsAnActor(): void
    {
        $command = $this->command(new FlatFile($this->dataRoot, new RecordingIndex()));

        self::assertSame(1, $command->run(['--apply'], new Output(fopen('php://memory', 'w+'), fopen('php://memory', 'w+'))));
    }

    private function command(FlatFile $storage): PagesNormalizeHeadingsCommand
    {
        $audit = new AuditLog($this->dataRoot . '/audit');

        return new PagesNormalizeHeadingsCommand(new MaintenanceRunner([new HeadingNormalizeTask($storage, $audit)], $this->dataRoot, $audit));
    }

    /** @param list<string> $args */
    private function run2(PagesNormalizeHeadingsCommand $command, array $args): string
    {
        $stdout = fopen('php://memory', 'w+');
        $command->run($args, new Output($stdout, fopen('php://memory', 'w+')));
        rewind($stdout);

        return (string) stream_get_contents($stdout);
    }
}
