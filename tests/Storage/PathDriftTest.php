<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use PDO;
use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Service\IndexMaintenance;
use Reporion\Service\Maintenance\IntegrityVerifyTask;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Maintenance\PathRepairTask;
use Reporion\Service\Revisions;
use Reporion\Schema\Loader;
use Reporion\Storage\FlatFile;
use Reporion\Storage\Journal;

/**
 * A page directory moved on the filesystem by hand: the directory is where the
 * page is, meta.json's `path` is only a hint. Reads, the index and index:verify
 * follow the directory; a signed-in lookup that misses adopts the page; the
 * repair never pre-empts an unfinished move.
 */
final class PathDriftTest extends StorageTestCase
{
    private Sqlite $index;
    private FlatFile $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FlatFile($this->dataRoot, $this->index);
    }

    /** A page created at $from, then its directory renamed to $to by hand (the index is left as it was) */
    private function moveByHand(string $from, string $to, string $visibility = 'private'): string
    {
        $pid = $this->storage->create($from, ['title' => 'T', 'visibility' => $visibility], "Body.\n", 'owner')->pid;
        $target = $this->dataRoot . '/pages/' . str_replace(':', '/', $to);
        is_dir(\dirname($target)) || mkdir(\dirname($target), 0775, true);
        rename($this->dataRoot . '/pages/' . str_replace(':', '/', $from), $target);

        return $pid;
    }

    private function owner(): User
    {
        return new User('owner', '', true, [], true, '', '');
    }

    private function stranger(): User
    {
        return new User('stranger', '', false, [], true, '', '');
    }

    private function rows(): array
    {
        return (new PDO('sqlite:' . $this->dataRoot . '/index.sqlite'))->query('SELECT path FROM pages ORDER BY path')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testTheDirectoryNamesThePageNotMetaJson(): void
    {
        $this->moveByHand('ai:profiles:reports:summary', 'ai:profiles:reports:special:summary');

        $record = $this->storage->read('ai:profiles:reports:special:summary');
        self::assertSame('ai:profiles:reports:special:summary', $record->path);
        self::assertSame('ai:profiles:reports:summary', $this->storage->storedPath('ai:profiles:reports:special:summary'));
        self::assertSame('ai:profiles:reports:special:summary', $this->storage->snapshotOf('ai:profiles:reports:special:summary')->path);
    }

    public function testRebuildIndexesAtTheDirectoryAndVerifyNoticesAStaleRow(): void
    {
        $this->moveByHand('a:b:old', 'a:b:new');
        $maintenance = new IndexMaintenance($this->storage, $this->index, $this->dataRoot, '');

        self::assertSame(['drifted' => 1], array_filter(array_map('count', $maintenance->verify()), static fn (int $n): bool => $n > 0), 'the index still has the old path');

        $maintenance->rebuild();
        self::assertSame(['a:b:new'], $this->rows());
        self::assertSame([[], [], []], array_values($maintenance->verify()));
    }

    public function testASignedInLookupAdoptsThePageAndRepairsMetaJson(): void
    {
        $pid = $this->moveByHand('a:b:old', 'a:b:new');
        $this->index->onMiss($this->storage->adopt(...));

        self::assertNull($this->index->findByPath('a:b:new', null), 'an anonymous lookup writes nothing');
        self::assertSame('a:b:old', $this->storage->storedPath('a:b:new'));

        $row = $this->index->findByPath('a:b:new', $this->owner());
        self::assertSame($pid, $row['pid'] ?? null);
        self::assertSame(['a:b:new'], $this->rows(), 'one row, at the new path');
        self::assertSame('a:b:new', $this->storage->storedPath('a:b:new'));
        $moves = $this->storage->read('a:b:new')->meta['moves'] ?? [];
        self::assertSame([['a:b:old', 'a:b:new']], array_map(static fn (array $m): array => [$m['from'], $m['to']], $moves));
        self::assertSame(1, $this->storage->read('a:b:new')->rev, 'no revision written');
    }

    public function testAdoptingNeverWidensWhoMaySeeIt(): void
    {
        $this->moveByHand('a:b:old', 'a:b:new', 'private');
        $this->index->onMiss($this->storage->adopt(...));

        self::assertNull($this->index->findByPath('a:b:new', $this->stranger()), 'indexed now, still invisible to one with no grant');
        self::assertNotNull($this->index->findByPath('a:b:new', $this->owner()));
    }

    public function testAPageThatIsIndexedButHiddenIsNotTouched(): void
    {
        $this->storage->create('a:b:p', ['title' => 'T', 'visibility' => 'private'], "Body.\n", 'owner');
        $before = file_get_contents($this->dataRoot . '/pages/a/b/p/meta.json');
        $this->index->onMiss($this->storage->adopt(...));

        self::assertNull($this->index->findByPath('a:b:p', $this->stranger()));
        self::assertSame($before, file_get_contents($this->dataRoot . '/pages/a/b/p/meta.json'));
    }

    public function testACopyIsNotAdoptedAndARebuildSurvivesIt(): void
    {
        $this->storage->create('a:b:orig', ['title' => 'T', 'visibility' => 'private'], "Body.\n", 'owner');
        $copy = $this->dataRoot . '/pages/a/b/copy';
        mkdir($copy);
        foreach (['meta.json', 'current.md'] as $file) {
            copy($this->dataRoot . '/pages/a/b/orig/' . $file, $copy . '/' . $file);
        }
        $this->index->onMiss($this->storage->adopt(...));

        self::assertNull($this->index->findByPath('a:b:copy', $this->owner()), 'the same pid is already indexed at a path that still holds it');
        self::assertSame(['a:b:orig'], $this->rows());

        (new IndexMaintenance($this->storage, $this->index, $this->dataRoot, ''))->rebuild();
        self::assertCount(1, $this->rows(), 'one pid, one row');
    }

    public function testRepairWaitsForAnUnfinishedMoveAndReplayStillFinishesIt(): void
    {
        $pid = $this->storage->create('a:b:old', ['title' => 'T', 'visibility' => 'private'], "Body.\n", 'owner')->pid;
        // The crash: renamed, the journal says move, the stub and meta.json not written
        rename($this->dataRoot . '/pages/a/b/old', $this->dataRoot . '/pages/a/b/new');
        (new Journal($this->dataRoot . '/journal'))->appendIntent('move', $pid, 'a:b:new', 1, null, '', 'owner', ['from' => 'a:b:old']);

        self::assertSame(FlatFile::PATH_BUSY, $this->storage->reconcilePath('a:b:new', 'system:admin'));
        self::assertFalse($this->storage->adopt('a:b:new'));
        self::assertSame('a:b:old', $this->storage->storedPath('a:b:new'), 'left as it was');

        $this->storage->replayJournal(0);
        self::assertSame('a:b:new', $this->storage->storedPath('a:b:new'));
        self::assertSame('a:b:new', $this->storage->redirectTarget('a:b:old'), 'the stub replay writes');
    }

    public function testTheRepairTaskListsThenRepairsAndIntegrityReportsTheDrift(): void
    {
        $this->moveByHand('a:b:old', 'a:b:new');
        $task = new PathRepairTask($this->storage);

        $check = $task->run(MaintenanceTask::CHECK, 'cli', []);
        self::assertSame(['drifted'], array_column($check->items(), 'outcome'));
        self::assertSame('a:b:old', $this->storage->storedPath('a:b:new'), 'check changes nothing');

        $integrity = new IntegrityVerifyTask($this->storage, new Revisions($this->storage, new Loader(\dirname(__DIR__, 2) . '/resources/schema')), new IndexMaintenance($this->storage, $this->index, $this->dataRoot, ''), $this->dataRoot);
        self::assertSame(1, $integrity->run(MaintenanceTask::CHECK, 'cli', ['backup' => ''])->summary()['path_drift'] ?? 0);

        $apply = $task->run(MaintenanceTask::APPLY, 'cli', []);
        self::assertSame(['repaired'], array_column($apply->items(), 'outcome'));
        self::assertSame(0, $apply->exit());
        self::assertSame('a:b:new', $this->storage->storedPath('a:b:new'));
        self::assertSame(['a:b:new'], $this->rows());
        self::assertSame([], $task->run(MaintenanceTask::CHECK, 'cli', [])->items(), 'nothing left');
    }

    public function testIntegrityReportsTwoDirectoriesWithOnePid(): void
    {
        $this->storage->create('a:b:orig', ['title' => 'T', 'visibility' => 'private'], "Body.\n", 'owner');
        $copy = $this->dataRoot . '/pages/a/b/copy';
        mkdir($copy);
        foreach (['meta.json', 'current.md'] as $file) {
            copy($this->dataRoot . '/pages/a/b/orig/' . $file, $copy . '/' . $file);
        }
        mkdir($copy . '/rev');
        foreach (glob($this->dataRoot . '/pages/a/b/orig/rev/*') ?: [] as $file) {
            copy($file, $copy . '/rev/' . basename($file));
        }

        $integrity = new IntegrityVerifyTask($this->storage, new Revisions($this->storage, new Loader(\dirname(__DIR__, 2) . '/resources/schema')), new IndexMaintenance($this->storage, $this->index, $this->dataRoot, ''), $this->dataRoot);
        $summary = $integrity->run(MaintenanceTask::CHECK, 'cli', ['backup' => ''])->summary();

        self::assertSame(1, $summary['duplicate_pid'] ?? 0);
        self::assertSame(1, $summary['path_drift'] ?? 0, 'the copy also names the original path');
    }
}
