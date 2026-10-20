<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Maintenance;

use DateTimeImmutable;
use PDO;
use Reporion\Audit\AuditLog;
use Reporion\Index\Sqlite;
use Reporion\Schema\Loader;
use Reporion\Service\IndexMaintenance;
use Reporion\Service\Maintenance\IntegrityVerifyTask;
use Reporion\Service\Maintenance\MaintenanceReport;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Revisions;
use Reporion\Storage\FileTree;
use Reporion\Support\Zip;
use Reporion\Support\ZipEntry;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * integrity:verify (roadmap phase 23, the 2.0 layout of D54): an intact
 * archive reads clean; each kind of damage done behind Reporion's back, to
 * an attic zip, a page file or the state database, is found and named by
 * pid, never by path; nothing is ever repaired. Fixture data is fictitious
 * (invariant 10).
 */
final class IntegrityVerifyTaskTest extends StorageTestCase
{
    private const SIGNED = 'reports:mri:mioveni:260920-test-unu';
    private const DRAFT = 'reports:ct:mioveni:260921-test-doi';

    /** A 1×1 PNG */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Sqlite $index;
    private FileTree $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);

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
        $this->setEntry(self::SIGNED, '0002.md', "---\ntitle: TEST Unu\nvisibility: private\n---\n\n# TEST Unu\n\nAltceva.\n");

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
        self::assertStringContainsString('Altceva', $this->entries(self::SIGNED)['0002.md']->bytes(), 'nothing repaired');
    }

    public function testAMissingACorruptAndAnExtraRevisionAndAStalePageFile(): void
    {
        $this->setEntry(self::SIGNED, '0001.md', null);
        $entries = $this->entries(self::DRAFT);
        $broken = $entries['0001.md'];
        $entries['0001.md'] = new ZipEntry('0001.md', ZipEntry::DEFLATED, $broken->crc, $broken->size, 'not deflate', $broken->dosTime, $broken->dosDate);
        $entries['0007.md'] = ZipEntry::deflated('0007.md', "stray\n", new DateTimeImmutable());
        $this->writeEntries(self::DRAFT, $entries);
        file_put_contents($this->pageFile(self::SIGNED), "---\ntitle: X\n---\n\nedited by hand\n");

        $outcomes = $this->outcomes($this->verify());

        self::assertContains('rev_missing', $outcomes);
        self::assertContains('rev_corrupt', $outcomes);
        self::assertContains('rev_extra', $outcomes);
        self::assertContains('current_stale', $outcomes);
    }

    public function testARevisionRemovedFromTheMiddleOfTheChainIsFound(): void
    {
        // Three revisions; drop the middle revlog entry the way a hand edit of revlog.json would
        $page = $this->storage->read(self::SIGNED);
        $this->storage->save(self::SIGNED, $page->frontmatter, "# TEST Unu\n\nA treia.\n", $page->rev, 'owner');
        self::assertSame([], $this->outcomes($this->verify()));

        $this->editHistory(self::SIGNED, static function (array $history): array {
            unset($history['revlog'][1]);
            $history['revlog'] = array_values($history['revlog']);
            foreach ($history['revlog'] as $i => $entry) {
                $history['revlog'][$i]['n'] = $i + 1;
            }

            return $history;
        });

        self::assertContains('chain_broken', $this->outcomes($this->verify()));
    }

    public function testAChangedEntryAndAStrippedLinkAreFound(): void
    {
        $this->editHistory(self::SIGNED, static function (array $history): array {
            $history['revlog'][0]['by'] = 'someone-else';

            return $history;
        });
        self::assertContains('chain_broken', $this->outcomes($this->verify()));

        $this->editHistory(self::SIGNED, static function (array $history): array {
            unset($history['revlog'][1]['chain']);

            return $history;
        });
        self::assertContains('chain_missing', $this->outcomes($this->verify()));
    }

    public function testHistoryWrittenBeforeTheChainIsAnchoredNotFlagged(): void
    {
        // A page as it was before 2026-10-17: no `chain` anywhere, then one new revision
        $this->editHistory(self::DRAFT, static function (array $history): array {
            unset($history['revlog'][0]['chain']);

            return $history;
        });
        self::assertSame([], $this->outcomes($this->verify()));

        $page = $this->storage->read(self::DRAFT);
        $this->storage->save(self::DRAFT, $page->frontmatter, "# TEST Doi\n\nAltceva.\n", $page->rev, 'owner');
        self::assertSame([], $this->outcomes($this->verify()));
        $page = $this->storage->read(self::DRAFT);
        self::assertArrayNotHasKey('chain', $page->revlog[0]);
        self::assertArrayHasKey('chain', $page->revlog[1]);

        // A minor edit re-links the newest entry and the chain still holds
        $this->storage->save(self::DRAFT, $page->frontmatter, "# TEST Doi\n\nSi mai altceva.\n", $page->rev, 'owner', null, false, true);
        self::assertSame([], $this->outcomes($this->verify()));
    }

    public function testACorruptSignedRevisionFailsItsSignatureQuietly(): void
    {
        $entries = $this->entries(self::SIGNED);
        $signed = $entries['0002.md'];
        $entries['0002.md'] = new ZipEntry('0002.md', ZipEntry::DEFLATED, $signed->crc, $signed->size, 'not deflate', $signed->dosTime, $signed->dosDate);
        $this->writeEntries(self::SIGNED, $entries);

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

    public function testAStateMirrorThatDisagreesWithTheAtticIsReported(): void
    {
        (new PDO('sqlite:' . $this->dataRoot . '/reporion.sqlite'))->exec('DELETE FROM signature');

        $this->assertContainsOutcome('mirror_drift', $this->verify());
    }

    public function testAnUnreadablePageIsNamedByItsPathHash(): void
    {
        file_put_contents($this->pageFile(self::DRAFT), '{broken');

        $report = $this->verify();

        $item = array_values(array_filter($report->items(), static fn (array $i): bool => $i['outcome'] === 'page_unreadable'))[0];
        self::assertNull($item['pid']);
        self::assertSame(AuditLog::pathHash(self::DRAFT), $item['data']['path_hash']);
    }

    public function testAnAtticZipWithNoPageIsReported(): void
    {
        copy($this->zipFile(self::DRAFT), $this->dataRoot . '/attic/reports/ct/mioveni/260921-orphan.zip');

        $this->assertContainsOutcome('attic_orphan', $this->verify());
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

            $this->setEntry(self::SIGNED, '0002.md', null, $backup);
            $this->assertContainsOutcome('backup_missing', $this->verify(['backup' => $backup]));

            // Other bytes the backup's own record agrees with: a different revision, not a damaged zip
            $this->copyTree($this->dataRoot, $backup . '-2');
            $this->setEntry(self::SIGNED, '0002.md', "other\n", $backup . '-2');
            $this->editHistory(self::SIGNED, static function (array $history): array {
                $history['revlog'][1]['sha256'] = hash('sha256', "other\n");
                $history['revlog'][1]['bytes'] = 6;

                return $history;
            }, $backup . '-2');
            $this->assertContainsOutcome('backup_differs', $this->verify(['backup' => $backup . '-2']));

            $this->assertContainsOutcome('backup_unreadable', $this->verify(['backup' => '/nonexistent/backup']));
        } finally {
            exec('rm -rf ' . escapeshellarg($backup) . ' ' . escapeshellarg($backup . '-2'));
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
            new Revisions($this->storage, new Loader(\dirname(__DIR__, 3) . '/resources/schema')),
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

    private function pageFile(string $path): string
    {
        return $this->dataRoot . '/pages/' . str_replace(':', '/', $path) . '.md';
    }

    private function zipFile(string $path, ?string $root = null): string
    {
        return ($root ?? $this->dataRoot) . '/attic/' . str_replace(':', '/', $path) . '.zip';
    }

    /** @return array<string, ZipEntry> */
    private function entries(string $path, ?string $root = null): array
    {
        return Zip::read((string) file_get_contents($this->zipFile($path, $root)));
    }

    /** @param array<string, ZipEntry> $entries */
    private function writeEntries(string $path, array $entries, ?string $root = null): void
    {
        file_put_contents($this->zipFile($path, $root), Zip::assemble(array_values($entries)));
    }

    /** An entry replaced by other bytes, or removed (null), behind Storage's back */
    private function setEntry(string $path, string $name, ?string $bytes, ?string $root = null): void
    {
        $entries = $this->entries($path, $root);
        if ($bytes === null) {
            unset($entries[$name]);
        } else {
            $entries[$name] = ZipEntry::deflated($name, $bytes, new DateTimeImmutable());
        }
        $this->writeEntries($path, $entries, $root);
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    private function editHistory(string $path, callable $change, ?string $root = null): void
    {
        $history = json_decode($this->entries($path, $root)['revlog.json']->bytes(), true);
        $this->setEntry($path, 'revlog.json', (string) json_encode($change($history), JSON_UNESCAPED_UNICODE), $root);
    }

    private function copyTree(string $from, string $to): void
    {
        exec('cp -a ' . escapeshellarg($from) . ' ' . escapeshellarg($to));
    }
}
