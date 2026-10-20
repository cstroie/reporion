<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use PDO;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Index\Sqlite;
use Reporion\Service\IndexMaintenance;
use Reporion\Service\Maintenance\IntegrityVerifyTask;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Maintenance\PathRepairTask;
use Reporion\Service\Revisions;
use Reporion\Schema\Loader;
use Reporion\Storage\FileTree;
use Reporion\Storage\Journal;

/**
 * A page file moved on the filesystem by hand (D54 §5): where the file is
 * is where the page is, once it is rejoined to its row by the pid it
 * carries. A signed-in lookup that misses rejoins it; pages:repair-paths
 * does them all; the rejoin never pre-empts an unfinished move.
 */
final class PathDriftTest extends StorageTestCase
{
    private Sqlite $index;
    private FileTree $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
    }

    /** A page created at $from, then its page file renamed to $to by hand (its attic zip, row and index left as they were) */
    private function moveByHand(string $from, string $to, string $visibility = 'private'): string
    {
        $pid = $this->storage->create($from, ['title' => 'T', 'visibility' => $visibility], "Body.\n", 'owner')->pid;
        $target = $this->file($to);
        is_dir(\dirname($target)) || mkdir(\dirname($target), 0775, true);
        rename($this->file($from), $target);

        return $pid;
    }

    private function file(string $path): string
    {
        return $this->dataRoot . '/pages/' . str_replace(':', '/', $path) . '.md';
    }

    private function owner(): User
    {
        return new User('owner', '', true, [], true, '', '');
    }

    private function stranger(): User
    {
        return new User('stranger', '', false, [], true, '', '');
    }

    /** @return list<string> */
    private function rows(): array
    {
        return (new PDO('sqlite:' . $this->dataRoot . '/index.sqlite'))->query('SELECT path FROM pages ORDER BY path')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function integrity(): IntegrityVerifyTask
    {
        return new IntegrityVerifyTask($this->storage, new Revisions($this->storage, new Loader(\dirname(__DIR__, 2) . '/resources/schema')), new IndexMaintenance($this->storage, $this->index, $this->dataRoot, ''), $this->dataRoot);
    }

    public function testAMovedFileIsStrayUntilRejoined(): void
    {
        $this->moveByHand('ai:profiles:reports:summary', 'ai:profiles:reports:special:summary');

        self::assertSame(['ai:profiles:reports:special:summary'], $this->storage->strayPaths());
        self::assertSame('ai:profiles:reports:summary', $this->storage->storedPath('ai:profiles:reports:special:summary'));
        self::assertSame('moved', $this->storage->strayKind('ai:profiles:reports:special:summary'));
        self::assertSame(['ai:profiles:reports:summary'], $this->storage->missingPageFiles());
        $this->expectException(PageNotFoundException::class);
        $this->storage->read('ai:profiles:reports:special:summary');
    }

    public function testVerifyNoticesTheStaleRowAndARebuildDropsIt(): void
    {
        $this->moveByHand('a:b:old', 'a:b:new');
        $maintenance = new IndexMaintenance($this->storage, $this->index, $this->dataRoot, '');

        self::assertSame(['orphans' => 1], array_filter(array_map('count', $maintenance->verify()), static fn (int $n): bool => $n > 0), 'the index still has the old path');

        $maintenance->rebuild();
        self::assertSame([], $this->rows(), 'a rebuild indexes pages, it rejoins nothing');
        self::assertSame([[], [], []], array_values($maintenance->verify()));
    }

    public function testASignedInLookupRejoinsThePage(): void
    {
        $pid = $this->moveByHand('a:b:old', 'a:b:new');
        $this->index->onMiss($this->storage->adopt(...));

        self::assertNull($this->index->findByPath('a:b:new', null), 'an anonymous lookup writes nothing');
        self::assertSame('a:b:old', $this->storage->storedPath('a:b:new'));

        $row = $this->index->findByPath('a:b:new', $this->owner());
        self::assertSame($pid, $row['pid'] ?? null);
        self::assertSame(['a:b:new'], $this->rows(), 'one row, at the new path');
        self::assertSame('a:b:new', $this->storage->storedPath('a:b:new'));
        $moves = $this->storage->read('a:b:new')->meta['moves'];
        self::assertSame([['a:b:old', 'a:b:new']], array_map(static fn (array $m): array => [$m['from'], $m['to']], $moves));
        self::assertSame(1, $this->storage->read('a:b:new')->rev, 'no revision written');
        self::assertFileExists($this->dataRoot . '/attic/a/b/new.zip', 'its history went along');
        self::assertSame('a:b:new', $this->storage->redirectTarget('a:b:old'));
    }

    public function testAdoptingNeverWidensWhoMaySeeIt(): void
    {
        $this->moveByHand('a:b:old', 'a:b:new', 'private');
        $this->index->onMiss($this->storage->adopt(...));

        self::assertNull($this->index->findByPath('a:b:new', $this->stranger()), 'rejoined now, still invisible to one with no grant');
        self::assertNotNull($this->index->findByPath('a:b:new', $this->owner()));
    }

    public function testAPageThatIsIndexedButHiddenIsNotTouched(): void
    {
        $this->storage->create('a:b:p', ['title' => 'T', 'visibility' => 'private'], "Body.\n", 'owner');
        $before = file_get_contents($this->file('a:b:p'));
        $this->index->onMiss($this->storage->adopt(...));

        self::assertNull($this->index->findByPath('a:b:p', $this->stranger()));
        self::assertSame($before, file_get_contents($this->file('a:b:p')));
        self::assertSame(1, $this->storage->read('a:b:p')->rev);
    }

    public function testACopyIsNotAdoptedAndARebuildSurvivesIt(): void
    {
        $this->storage->create('a:b:orig', ['title' => 'T', 'visibility' => 'private'], "Body.\n", 'owner');
        copy($this->file('a:b:orig'), $this->file('a:b:copy'));
        $this->index->onMiss($this->storage->adopt(...));

        self::assertNull($this->index->findByPath('a:b:copy', $this->owner()), 'the pid is a live page\'s that still has its file');
        self::assertSame(['a:b:orig'], $this->rows());

        (new IndexMaintenance($this->storage, $this->index, $this->dataRoot, ''))->rebuild();
        self::assertSame(['a:b:orig'], $this->rows(), 'one pid, one row');
    }

    public function testRepairWaitsForAnUnfinishedMoveAndReplayStillFinishesIt(): void
    {
        $pid = $this->storage->create('a:b:old', ['title' => 'T', 'visibility' => 'private'], "Body.\n", 'owner')->pid;
        // The crash: the page file renamed, the journal says move, the zip, row and redirect not done
        rename($this->file('a:b:old'), $this->file('a:b:new'));
        (new Journal($this->dataRoot . '/journal'))->appendIntent('move', $pid, 'a:b:new', 1, null, '', 'owner', ['from' => 'a:b:old']);

        self::assertSame(FileTree::PATH_BUSY, $this->storage->reconcilePath('a:b:new', 'system:admin'));
        self::assertFalse($this->storage->adopt('a:b:new'));
        self::assertSame('a:b:old', $this->storage->storedPath('a:b:new'), 'left as it was');

        $this->storage->replayJournal(0);
        self::assertSame('a:b:new', $this->storage->storedPath('a:b:new'));
        self::assertSame('a:b:new', $this->storage->redirectTarget('a:b:old'), 'the redirect replay writes');
    }

    public function testTheRepairTaskListsThenRepairsAndIntegrityReportsTheDrift(): void
    {
        $this->moveByHand('a:b:old', 'a:b:new');
        $task = new PathRepairTask($this->storage);

        $check = $task->run(MaintenanceTask::CHECK, 'cli', []);
        self::assertSame(['moved'], array_column($check->items(), 'outcome'));
        self::assertSame('a:b:old', $this->storage->storedPath('a:b:new'), 'check changes nothing');

        $summary = $this->integrity()->run(MaintenanceTask::CHECK, 'cli', ['backup' => ''])->summary();
        self::assertSame(1, $summary['path_drift'] ?? 0);
        self::assertSame(1, $summary['page_file_missing'] ?? 0);

        $apply = $task->run(MaintenanceTask::APPLY, 'cli', []);
        self::assertSame(['repaired'], array_column($apply->items(), 'outcome'));
        self::assertSame(0, $apply->exit());
        self::assertSame('a:b:new', $this->storage->storedPath('a:b:new'));
        self::assertSame(['a:b:new'], $this->rows());
        self::assertSame([], $task->run(MaintenanceTask::CHECK, 'cli', [])->items(), 'nothing left');
        self::assertSame(0, $this->integrity()->run(MaintenanceTask::CHECK, 'cli', ['backup' => ''])->summary()['problems']);
    }

    public function testTheRepairTaskAdoptsAPageFileMadeByHand(): void
    {
        mkdir($this->dataRoot . '/pages/protocols', 0775, true);
        file_put_contents($this->file('protocols:knee'), "---\ntitle: Genunchi\nvisibility: private\n---\n\nProtocol.\n");

        self::assertSame(1, $this->integrity()->run(MaintenanceTask::CHECK, 'cli', ['backup' => ''])->summary()['stray_files'] ?? 0);
        $apply = (new PathRepairTask($this->storage))->run(MaintenanceTask::APPLY, 'cli', []);

        self::assertSame(['repaired'], array_column($apply->items(), 'outcome'));
        self::assertSame("Protocol.\n", $this->storage->read('protocols:knee')->body);
        self::assertSame(['protocols:knee'], $this->rows());
    }

    public function testIntegrityReportsACopyOfAPageFile(): void
    {
        $this->storage->create('a:b:orig', ['title' => 'T', 'visibility' => 'private'], "Body.\n", 'owner');
        copy($this->file('a:b:orig'), $this->file('a:b:copy'));

        $summary = $this->integrity()->run(MaintenanceTask::CHECK, 'cli', ['backup' => ''])->summary();

        self::assertSame(1, $summary['duplicate_pid'] ?? 0);
        self::assertSame(0, $summary['path_drift'] ?? 0);
        self::assertSame(['copy'], array_column((new PathRepairTask($this->storage))->run(MaintenanceTask::APPLY, 'cli', [])->items(), 'outcome'), 'left for the owner');
    }
}
