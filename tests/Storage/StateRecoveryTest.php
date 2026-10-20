<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use PDO;
use Reporion\Cli\DbRecoverCommand;
use Reporion\Cli\Output;
use Reporion\Storage\FileTree;
use Reporion\Storage\StateRecovery;

/**
 * db:recover (D54 §10): a lost or drifted state database made again from
 * the attic zips; what only the database held is gone, and said so.
 */
final class StateRecoveryTest extends StorageTestCase
{
    public function testALostStateDatabaseComesBackFromTheAtticZips(): void
    {
        $storage = new FileTree($this->dataRoot, new RecordingIndex());
        $report = $storage->create('reports:mri:a', ['title' => 'A', 'visibility' => 'unlisted'], 'one', 'owner');
        $storage->save('reports:mri:a', ['title' => 'A', 'visibility' => 'unlisted'], 'two', 1, 'editor');
        $storage->sign('reports:mri:a', 'owner', []);
        $gone = $storage->create('docs:old', ['title' => 'Old', 'visibility' => 'private'], 'old', 'owner');
        $storage->delete('docs:old', 'owner');
        unset($storage);
        foreach (glob($this->dataRoot . '/reporion.sqlite*') ?: [] as $file) {
            unlink($file);
        }

        $counts = (new StateRecovery($this->dataRoot))->run(false);

        self::assertSame(['zips' => 2, 'created' => 2, 'repaired' => 0, 'unchanged' => 0, 'trashed' => 1, 'unreadable' => 0, 'conflicts' => 0, 'rows_without_zip' => 0], $counts);
        $storage = new FileTree($this->dataRoot, new RecordingIndex());
        $page = $storage->read('reports:mri:a');
        self::assertSame([$report->pid, 2, 'signed', 'unlisted', 'owner'], [$page->pid, $page->rev, $page->status, $page->visibility, $page->meta['created_by']]);
        self::assertCount(1, $page->meta['signatures']);
        self::assertSame([], $storage->historyProblems('reports:mri:a'));
        self::assertSame([$gone->pid], array_column($storage->trash(), 'pid'));
        self::assertSame('docs:old', $storage->restore($gone->pid, 'owner')->path, 'the trash note kept its path');

        self::assertSame(2, (new StateRecovery($this->dataRoot))->run(false)['unchanged'], 'idempotent');
    }

    public function testADriftedMirrorIsPutRightAndADryRunChangesNothing(): void
    {
        $storage = new FileTree($this->dataRoot, new RecordingIndex());
        $storage->create('reports:mri:a', ['title' => 'A', 'visibility' => 'private'], 'one', 'owner');
        $storage->sign('reports:mri:a', 'owner', []);
        $state = new PDO('sqlite:' . $this->dataRoot . '/reporion.sqlite');
        $state->exec("DELETE FROM signature; UPDATE page SET status = 'draft'");
        self::assertContains(['rev' => null, 'problem' => 'mirror_drift'], $storage->historyProblems('reports:mri:a'));

        self::assertSame(1, (new StateRecovery($this->dataRoot))->run(true)['repaired']);
        self::assertContains(['rev' => null, 'problem' => 'mirror_drift'], $storage->historyProblems('reports:mri:a'), 'a dry run');

        self::assertSame(1, (new StateRecovery($this->dataRoot))->run(false)['repaired']);
        self::assertSame([], $storage->historyProblems('reports:mri:a'));
    }

    public function testAZipAtAPathAnotherPageHoldsIsAConflictNotAnOverwrite(): void
    {
        $storage = new FileTree($this->dataRoot, new RecordingIndex());
        $storage->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'a', 'owner');
        $storage->create('docs:b', ['title' => 'B', 'visibility' => 'private'], 'b', 'owner');
        // docs:b's zip copied in by hand at docs:a's place
        copy($this->dataRoot . '/attic/docs/b.zip', $this->dataRoot . '/attic/docs/a.zip');

        $command = new DbRecoverCommand(fn (): StateRecovery => new StateRecovery($this->dataRoot));
        [$code, $out] = $this->runCommand($command, []);

        self::assertSame(1, $code);
        self::assertStringContainsString('1 conflict(s)', $out);
        self::assertStringNotContainsString('docs:', $out, 'counts, never a path');
        self::assertSame("a\n", $storage->read('docs:a')->body);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string}
     */
    private function runCommand(DbRecoverCommand $command, array $args): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);
        $code = $command->run($args, new Output($stdout, $stderr));
        rewind($stdout);

        return [$code, (string) stream_get_contents($stdout)];
    }
}
