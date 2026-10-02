<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Maintenance;

use Reporion\Audit\AuditLog;
use Reporion\Index\Sqlite;
use Reporion\Schema\Loader;
use Reporion\Service\IndexMaintenance;
use Reporion\Service\Maintenance\IntegrityVerifyTask;
use Reporion\Service\Maintenance\MaintenanceReport;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Revisions;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * integrity:verify (roadmap phase 23): an intact archive reads clean; each
 * kind of damage done to the files behind Reporion's back is found and
 * named by pid, never by path; nothing is ever repaired. Fixture data is
 * fictitious (invariant 10).
 */
final class IntegrityVerifyTaskTest extends StorageTestCase
{
    private const SIGNED = 'reports:mri:mioveni:260920-test-unu';
    private const DRAFT = 'reports:ct:mioveni:260921-test-doi';

    /** A 1×1 PNG */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Sqlite $index;
    private FlatFile $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->storage = new FlatFile($this->dataRoot, $this->index);

        $this->storage->create(self::SIGNED, ['title' => 'TEST Unu', 'visibility' => 'private'], "# TEST Unu\n\nPrima.\n", 'owner');
        $page = $this->storage->read(self::SIGNED);
        $this->storage->save(self::SIGNED, $page->frontmatter, "# TEST Unu\n\nA doua.\n", $page->rev, 'owner');
        $this->storage->sign(self::SIGNED, 'owner', []);
        $this->storage->create(self::DRAFT, ['title' => 'TEST Doi', 'visibility' => 'private'], "# TEST Doi\n\nText.\n", 'owner');
        $this->storage->attachMedia(self::DRAFT, (string) base64_decode(self::PNG, true), 'scan.png', 'owner');
    }

    public function testAnIntactArchiveIsClean(): void
    {
        $report = $this->verify();

        self::assertSame(0, $report->exit());
        self::assertSame(0, $report->summary()['problems']);
        self::assertSame(2, $report->summary()['pages']);
        self::assertSame(3, $report->summary()['revisions']);
        self::assertSame(1, $report->summary()['signatures']);
        self::assertSame(1, $report->summary()['media']);
        self::assertSame([], $report->items());
    }

    public function testAChangedSignedRevisionBreaksBothItsHashAndItsSignature(): void
    {
        $this->rewriteRevision(self::SIGNED, 2, "---\ntitle: TEST Unu\nvisibility: private\n---\n\n# TEST Unu\n\nAltceva.\n");

        $report = $this->verify();

        self::assertSame(1, $report->exit());
        $outcomes = $this->outcomes($report);
        self::assertContains('rev_changed', $outcomes);
        self::assertContains('signature_mismatch', $outcomes);
        $pid = $this->storage->read(self::SIGNED)->pid;
        foreach ($report->items() as $item) {
            if (\in_array($item['outcome'], ['rev_changed', 'signature_mismatch'], true)) {
                self::assertSame($pid, $item['pid']);
                self::assertSame(2, $item['rev']);
            }
        }
        self::assertStringNotContainsString('test-unu', $report->toJson(), 'no path in the report (invariant 8)');
        self::assertStringContainsString('Altceva', (string) gzdecode((string) file_get_contents($this->revFile(self::SIGNED, 2))), 'nothing repaired');
    }

    public function testAMissingACorruptAndAnExtraRevisionAndAStaleCurrent(): void
    {
        unlink($this->revFile(self::SIGNED, 1));
        file_put_contents($this->revFile(self::DRAFT, 1), 'not gzip');
        file_put_contents($this->revFile(self::DRAFT, 7), (string) gzencode("stray\n"));
        file_put_contents($this->pageDir(self::SIGNED) . '/current.md', "---\ntitle: X\n---\n\nedited by hand\n");

        $outcomes = $this->outcomes($this->verify());

        self::assertContains('rev_missing', $outcomes);
        self::assertContains('rev_corrupt', $outcomes);
        self::assertContains('rev_extra', $outcomes);
        self::assertContains('current_stale', $outcomes);
    }

    public function testACorruptSignedRevisionFailsItsSignatureQuietly(): void
    {
        file_put_contents($this->revFile(self::SIGNED, 2), 'not gzip');

        $outcomes = $this->outcomes($this->verify());

        self::assertContains('rev_corrupt', $outcomes);
        self::assertContains('signature_mismatch', $outcomes);
    }

    public function testMediaThatChangedOrWentMissing(): void
    {
        $file = (glob($this->dataRoot . '/media/*/*.png') ?: [])[0];
        file_put_contents($file, 'other bytes');
        $this->assertContainsOutcome('media_changed', $this->verify());

        unlink($file);
        $this->assertContainsOutcome('media_missing', $this->verify());
    }

    public function testAnUnreadablePageIsNamedByItsPathHash(): void
    {
        file_put_contents($this->pageDir(self::DRAFT) . '/meta.json', '{broken');

        $report = $this->verify();

        $item = array_values(array_filter($report->items(), static fn (array $i): bool => $i['outcome'] === 'page_unreadable'))[0];
        self::assertNull($item['pid']);
        self::assertSame(AuditLog::pathHash(self::DRAFT), $item['data']['path_hash']);
    }

    public function testIndexDriftIsReported(): void
    {
        $this->index->remove($this->storage->read(self::DRAFT)->pid);

        $this->assertContainsOutcome('index_missing', $this->verify());
    }

    public function testABackupWithEverySignedRevisionPassesAndOneWithout(): void
    {
        $backup = $this->dataRoot . '/../' . basename($this->dataRoot) . '-backup';
        $this->copyTree($this->dataRoot, $backup);
        try {
            $clean = $this->verify(['backup' => $backup]);
            self::assertSame(0, $clean->exit());
            self::assertSame(1, $clean->summary()['backup_checked']);

            unlink($backup . '/pages/' . str_replace(':', '/', self::SIGNED) . '/rev/0002.md.gz');
            $this->assertContainsOutcome('backup_missing', $this->verify(['backup' => $backup]));

            file_put_contents($backup . '/pages/' . str_replace(':', '/', self::SIGNED) . '/rev/0002.md.gz', (string) gzencode("other\n"));
            $this->assertContainsOutcome('backup_differs', $this->verify(['backup' => $backup]));

            $this->assertContainsOutcome('backup_unreadable', $this->verify(['backup' => '/nonexistent/backup']));
        } finally {
            exec('rm -rf ' . escapeshellarg($backup));
        }
    }

    public function testTheRunnerKeepsTheRunAndItIsCheckOnly(): void
    {
        $runner = MaintenanceRunner::standard($this->storage, $this->index, new AuditLog($this->dataRoot . '/audit'), $this->dataRoot, 30);

        self::assertSame([MaintenanceTask::CHECK], $runner->tasks()['integrity:verify']->modes());
        $run = $runner->run('integrity:verify', MaintenanceTask::CHECK, 'cli', []);
        self::assertSame(0, $run['report']->exit());
        self::assertFileExists($this->dataRoot . '/maintenance/runs/' . $run['id'] . '.json');
    }

    /** @param array<string, mixed> $options */
    private function verify(array $options = []): MaintenanceReport
    {
        $task = new IntegrityVerifyTask(
            $this->storage,
            new Revisions($this->storage, new Loader(\dirname(__DIR__, 3) . '/conf/schema')),
            new IndexMaintenance($this->storage, $this->index, $this->dataRoot, $this->dataRoot . '/audit'),
            $this->dataRoot,
        );

        return $task->run(MaintenanceTask::CHECK, 'owner', $task->options($options));
    }

    /** @return list<string> */
    private function outcomes(MaintenanceReport $report): array
    {
        return array_column($report->items(), 'outcome');
    }

    private function assertContainsOutcome(string $outcome, MaintenanceReport $report): void
    {
        self::assertContains($outcome, $this->outcomes($report));
        self::assertSame(1, $report->exit());
    }

    private function pageDir(string $path): string
    {
        return $this->dataRoot . '/pages/' . str_replace(':', '/', $path);
    }

    private function revFile(string $path, int $rev): string
    {
        return \sprintf('%s/rev/%04d.md.gz', $this->pageDir($path), $rev);
    }

    private function rewriteRevision(string $path, int $rev, string $document): void
    {
        file_put_contents($this->revFile($path, $rev), (string) gzencode($document));
        file_put_contents($this->pageDir($path) . '/current.md', $document);
    }

    private function copyTree(string $from, string $to): void
    {
        exec('cp -a ' . escapeshellarg($from) . ' ' . escapeshellarg($to));
    }
}
