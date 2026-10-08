<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Cli\Output;
use Reporion\Cli\PagesApplyPatientCsvCommand;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\PatientCsvTask;
use Reporion\Storage\FlatFile;
use Reporion\Support\Cnp;
use Reporion\Tests\Storage\RecordingIndex;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * pages:apply-patient-csv — the CLI face of Service\Maintenance\PatientCsvTask:
 * check by default, --apply needing an actor, a bad table refused cleanly,
 * --loose-names reaching the task. Names and CNPs are made up (invariant 10).
 */
final class PagesApplyPatientCsvCommandTest extends StorageTestCase
{
    private const PATH = 'reports:mr:polimed:221215-pacient-test-unu';

    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $first12 = '501020340123';
        $this->table = $this->dataRoot . '/table.csv';
        file_put_contents($this->table, "\"DATA\",\"NUME\",\"PRENUME\",\"CNP\",\"SEGMENT ANALIZAT\",\"DATE CLINICE\"\n"
            . '"15.12.2022","PACIENT","TEST UNU","' . $first12 . Cnp::checksum($first12) . "\",\"IRM CEREBRAL NATIV\",\"CEFALEE;\"\n");
    }

    private function storage(): FlatFile
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create(self::PATH, [
            'title' => 'Test', 'visibility' => 'private', 'status' => 'archived', 'modality' => ['MR'], 'region' => ['neuro'],
            'site' => 'polimed', 'study_date' => '2022-12-15', 'patient' => ['name' => 'TEST', 'born' => null, 'sex' => null, 'cnp' => null],
        ], "# Test\n\n## IRM Cerebral\n\nText.\n", 'importer');

        return $storage;
    }

    private function command(FlatFile $storage): PagesApplyPatientCsvCommand
    {
        $audit = new AuditLog($this->dataRoot . '/audit');

        return new PagesApplyPatientCsvCommand(new MaintenanceRunner([new PatientCsvTask($storage, $audit)], $this->dataRoot, $audit));
    }

    /** @param list<string> $args */
    private function exec(PagesApplyPatientCsvCommand $command, array $args, ?int &$code = null): string
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $code = $command->run($args, new Output($stdout, $stderr));
        rewind($stdout);
        rewind($stderr);

        return (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr);
    }

    public function testACheckWritesNothingAndApplyNeedsAnActor(): void
    {
        $storage = $this->storage();
        $command = $this->command($storage);

        $out = $this->exec($command, ['--from=' . $this->table, '--namespace=reports:mr:polimed'], $code);
        self::assertSame(0, $code);
        self::assertStringContainsString('1 to apply', $out);
        self::assertSame(1, $storage->read(self::PATH)->rev);

        $out = $this->exec($command, ['--from=' . $this->table, '--apply'], $code);
        self::assertSame(1, $code);
        self::assertStringContainsString('--actor', $out);
        self::assertSame(1, $storage->read(self::PATH)->rev);

        $out = $this->exec($command, ['--from=' . $this->table, '--namespace=reports:mr:polimed', '--apply', '--actor=owner'], $code);
        self::assertStringContainsString('1 applied', $out);
        self::assertSame(2, $storage->read(self::PATH)->rev);
    }

    public function testATableThatCannotBeReadExitsOneWithoutAStackTrace(): void
    {
        $out = $this->exec($this->command($this->storage()), ['--from=' . $this->dataRoot . '/nope.csv'], $code);

        self::assertSame(1, $code);
        self::assertStringContainsString('--from', $out);
    }

    public function testLooseNamesReachesTheTask(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create('reports:mr:polimed:221215-pacient-test-unu-doi', [
            'title' => 'Test', 'visibility' => 'private', 'status' => 'archived', 'modality' => ['MR'], 'region' => ['neuro'],
            'site' => 'polimed', 'study_date' => '2022-12-15', 'patient' => ['name' => 'TEST'],
        ], "# Test\n\n## IRM Cerebral\n\nText.\n", 'importer');
        $command = $this->command($storage);
        $args = ['--from=' . $this->table, '--namespace=reports:mr:polimed'];

        self::assertStringContainsString('0 to apply', $this->exec($command, $args));
        self::assertStringContainsString('1 to apply', $this->exec($command, [...$args, '--loose-names']));
    }
}
