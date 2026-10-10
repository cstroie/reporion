<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Exception\MaintenanceBusyException;
use Reporion\Http\Request;
use Reporion\Index\Sqlite;
use Reporion\Schema\Loader;
use Reporion\Service\IndexMaintenance;
use Reporion\Service\Revisions;
use Reporion\Storage\AtomicWriter;
use Reporion\Storage\FlatFile;
use RuntimeException;
use Throwable;

/**
 * Runs maintenance tasks for Admin → Maintenance and bin/reporion alike:
 * validates mode and options, takes the maintenance lock for anything that
 * writes, audits the run (maintenance.run: task, mode, counts — no pages)
 * and keeps the report in data/maintenance/runs/{id}.json, so the admin
 * screen shows CLI runs too and a page refresh never re-runs a repair.
 *
 * run() is begin() then complete(). Admin → Maintenance calls them apart:
 * begin() with a placeholder — the run's file, not finished yet — answers
 * the form at once, and complete() runs the task after the response
 * (fastcgi_finish_request()) and writes the report over the placeholder.
 */
final class MaintenanceRunner
{
    private const KEEP_RUNS = 100;

    /** @var array<string, MaintenanceTask> */
    private array $tasks = [];

    /**
     * @param list<MaintenanceTask> $tasks
     */
    public function __construct(
        array $tasks,
        private readonly string $dataRoot,
        private readonly AuditLog $audit,
    ) {
        foreach ($tasks as $task) {
            $this->tasks[$task->name()] = $task;
        }
    }

    /**
     * The tasks this instance offers, for the front controller and bin/reporion alike;
     * $extra are plugin tasks (hook maintenance.tasks), only ever passed by bin/reporion.
     *
     * @param list<MaintenanceTask> $extra
     */
    public static function standard(FlatFile $storage, Sqlite $index, AuditLog $audit, string $dataRoot, int $trashPurgeDays, array $extra = []): self
    {
        return new self([
            new JournalReplayTask($storage),
            new IndexVerifyTask(new IndexMaintenance($storage, $index, $dataRoot, $audit->directory())),
            new IntegrityVerifyTask(
                $storage,
                new Revisions($storage, new Loader(\dirname(__DIR__, 3) . '/conf/schema')),
                new IndexMaintenance($storage, $index, $dataRoot, $audit->directory()),
                $dataRoot,
            ),
            new TrashPurgeTask($storage, $audit, $trashPurgeDays),
            ...$extra,
        ], $dataRoot, $audit);
    }

    /** @return array<string, MaintenanceTask> */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * @param array<string, mixed> $rawOptions
     *
     * @return array{report: MaintenanceReport, id: string}
     *
     * @throws InvalidArgumentException  for an unknown task or mode
     * @throws MaintenanceBusyException  when a writing run already holds the lock
     */
    public function run(string $taskName, string $mode, string $actor, array $rawOptions, ?Request $request = null): array
    {
        return $this->complete($this->begin($taskName, $mode, $actor, $rawOptions), $request);
    }

    /**
     * A run checked and ready: its options, the maintenance lock when it
     * writes and — with $placeholder — its id, its file already there as
     * "running" for the admin screen to show until complete() replaces it.
     *
     * @param array<string, mixed> $rawOptions
     *
     * @return array{task: MaintenanceTask, mode: string, actor: string, options: array<string, int|bool|string>, lock: resource|null, id: ?string}
     *
     * @throws InvalidArgumentException  for an unknown task or mode
     * @throws MaintenanceBusyException  when a writing run already holds the lock
     */
    public function begin(string $taskName, string $mode, string $actor, array $rawOptions, bool $placeholder = false): array
    {
        $task = $this->tasks[$taskName] ?? throw new InvalidArgumentException('Unknown maintenance task');
        if (!\in_array($mode, $task->modes(), true)) {
            throw new InvalidArgumentException('This task has no ' . $mode . ' mode');
        }
        $options = $task->options($rawOptions);
        $lock = $mode === MaintenanceTask::APPLY ? self::lock($this->dataRoot) : null;
        $id = $placeholder ? $this->store(new MaintenanceReport($taskName, $mode, $actor, $options, date('Y-m-d\TH:i:sP'))) : null;

        return ['task' => $task, 'mode' => $mode, 'actor' => $actor, 'options' => $options, 'lock' => $lock, 'id' => $id];
    }

    /**
     * Runs what begin() checked, releases its lock, audits it and keeps its
     * report — over its placeholder when it has one. A placeholder's run
     * happens after its response: a failure there has nobody to show it to,
     * so it ends the report (attention, a note) instead of being thrown.
     *
     * @param array{task: MaintenanceTask, mode: string, actor: string, options: array<string, int|bool|string>, lock: resource|null, id: ?string} $begun
     *
     * @return array{report: MaintenanceReport, id: string}
     */
    public function complete(array $begun, ?Request $request = null): array
    {
        $task = $begun['task'];
        try {
            $report = $task->run($begun['mode'], $begun['actor'], $begun['options'])->finish();
        } catch (Throwable $e) {
            if ($begun['id'] === null) {
                throw $e;
            }
            error_log('reporion: maintenance run ' . $task->name() . ' failed: ' . $e::class);
            $report = new MaintenanceReport($task->name(), $begun['mode'], $begun['actor'], $begun['options'], date('Y-m-d\TH:i:sP'));
            $report->note('Stopped: an internal error — see the server log.');
            $report->fail();
            $report->finish();
        } finally {
            if ($begun['lock'] !== null) {
                flock($begun['lock'], LOCK_UN);
                fclose($begun['lock']);
            }
        }

        $this->audit->record('maintenance.run', $begun['actor'], $request, outcome: $report->exit() === 0 ? 'ok' : 'error', extra: [
            'task' => $task->name(),
            'mode' => $begun['mode'],
            'summary' => $report->summary(),
        ]);

        return ['report' => $report, 'id' => $this->store($report, $begun['id'])];
    }

    /**
     * The lock every maintenance write takes — the runner's apply mode,
     * index:rebuild from the CLI and from Admin → Index — so a web repair
     * never races a CLI rebuild. Non-blocking: busy means busy.
     *
     * @return resource release with flock($lock, LOCK_UN) + fclose()
     *
     * @throws MaintenanceBusyException
     */
    public static function lock(string $dataRoot)
    {
        $dir = $dataRoot . '/maintenance';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the maintenance directory');
        }
        $lock = fopen($dir . '/.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the maintenance lock');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new MaintenanceBusyException('Another maintenance run is in progress');
        }

        return $lock;
    }

    public function load(string $id): ?MaintenanceReport
    {
        if (preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $id) !== 1) {
            return null;
        }
        $file = $this->runsDir() . '/' . $id . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return \is_array($data) ? MaintenanceReport::fromArray($data) : null;
    }

    /**
     * The most recent runs, newest first.
     *
     * @return list<array{id: string, report: MaintenanceReport}>
     */
    public function recent(int $limit = 20): array
    {
        $files = glob($this->runsDir() . '/*.json') ?: [];
        rsort($files);
        $runs = [];
        foreach (\array_slice($files, 0, $limit) as $file) {
            $id = basename($file, '.json');
            $report = $this->load($id);
            if ($report !== null) {
                $runs[] = ['id' => $id, 'report' => $report];
            }
        }

        return $runs;
    }

    private function store(MaintenanceReport $report, ?string $id = null): string
    {
        $dir = $this->runsDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the maintenance runs directory');
        }
        $id ??= date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        AtomicWriter::put($dir . '/' . $id . '.json', $report->toJson() . "\n");

        $files = glob($dir . '/*.json') ?: [];
        rsort($files);
        foreach (\array_slice($files, self::KEEP_RUNS) as $old) {
            @unlink($old);
        }

        return $id;
    }

    private function runsDir(): string
    {
        return $this->dataRoot . '/maintenance/runs';
    }
}
