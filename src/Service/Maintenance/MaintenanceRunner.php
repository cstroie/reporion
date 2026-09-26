<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Exception\MaintenanceBusyException;
use Reporion\Http\Request;
use Reporion\Index\Sqlite;
use Reporion\Service\IndexMaintenance;
use Reporion\Storage\AtomicWriter;
use Reporion\Storage\FlatFile;
use RuntimeException;

/**
 * Runs maintenance tasks for Admin → Maintenance and bin/reporion alike:
 * validates mode and options, takes the maintenance lock for anything that
 * writes, audits the run (maintenance.run: task, mode, counts — no pages)
 * and keeps the report in data/maintenance/runs/{id}.json, so the admin
 * screen shows CLI runs too and a page refresh never re-runs a repair.
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

    /** The tasks this instance offers, for the front controller and bin/reporion alike */
    public static function standard(FlatFile $storage, Sqlite $index, AuditLog $audit, string $dataRoot, int $trashPurgeDays): self
    {
        return new self([
            new JournalReplayTask($storage),
            new FrontmatterCheckTask($storage, $audit),
            new IndexVerifyTask(new IndexMaintenance($storage, $index, $dataRoot, $audit->directory())),
            new TrashPurgeTask($storage, $audit, $trashPurgeDays),
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
        $task = $this->tasks[$taskName] ?? throw new InvalidArgumentException('Unknown maintenance task');
        if (!\in_array($mode, $task->modes(), true)) {
            throw new InvalidArgumentException('This task has no ' . $mode . ' mode');
        }
        $options = $task->options($rawOptions);

        $lock = $mode === MaintenanceTask::APPLY ? self::lock($this->dataRoot) : null;
        try {
            $report = $task->run($mode, $actor, $options)->finish();
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        $this->audit->record('maintenance.run', $actor, $request, outcome: $report->exit() === 0 ? 'ok' : 'error', extra: [
            'task' => $taskName,
            'mode' => $mode,
            'summary' => $report->summary(),
        ]);

        return ['report' => $report, 'id' => $this->store($report)];
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

    private function store(MaintenanceReport $report): string
    {
        $dir = $this->runsDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the maintenance runs directory');
        }
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
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
