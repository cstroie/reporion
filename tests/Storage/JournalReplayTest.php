<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use Reporion\Cli\JournalReplayCommand;
use Reporion\Cli\Output;
use Reporion\Storage\FlatFile;
use Reporion\Storage\Journal;
use Reporion\Audit\AuditLog;
use Reporion\Service\Maintenance\JournalReplayTask;
use Reporion\Service\Maintenance\MaintenanceRunner;

/**
 * Crash recovery that actually runs (decided 2026-09-26): the front
 * controller replays intents older than a minute on boot, reading the
 * journal incrementally, and journal:replay does the same for an operator.
 */
final class JournalReplayTest extends StorageTestCase
{
    private const PATH = 'reports:mri:mioveni:x';

    /**
     * Intents already open when boot replay first runs are a backlog of
     * unknown age: journal:replay's (with --dry-run first), never replayed
     * unseen by whichever request comes first.
     */
    public function testTheFirstScanStartsAtTheEndLeavingTheBacklogToTheOperator(): void
    {
        $journal = new Journal($this->dataRoot . '/journal');
        $journal->appendIntent('save', 'OLD', 'a:b', 2, 1, 'sha', 'owner');

        self::assertSame([], $journal->openIntentsSinceCheckpoint());
        self::assertCount(1, $journal->openIntents(), 'still there for journal:replay');

        $journal->appendIntent('save', 'NEW', 'a:c', 5, 4, 'sha', 'owner');
        self::assertSame(['NEW'], array_column($journal->openIntentsSinceCheckpoint(), 'pid'));
    }

    public function testTheIncrementalScanFollowsIntentsAndDonesAcrossAppends(): void
    {
        $journal = new Journal($this->dataRoot . '/journal');
        $journal->appendDone('BASE', 1);
        $journal->openIntentsSinceCheckpoint();

        $journal->appendIntent('save', 'P1', 'a:b', 2, 1, 'sha', 'owner');
        self::assertSame(['P1'], array_column($journal->openIntentsSinceCheckpoint(), 'pid'));

        $journal->appendIntent('save', 'P2', 'a:c', 5, 4, 'sha', 'owner');
        $journal->appendDone('P1', 2);
        self::assertSame(['P2'], array_column($journal->openIntentsSinceCheckpoint(), 'pid'));
        self::assertSame($journal->openIntents(), $journal->openIntentsSinceCheckpoint(), 'agrees with a full scan');
    }

    public function testALineStillBeingWrittenIsLeftForTheNextScan(): void
    {
        $journal = new Journal($this->dataRoot . '/journal');
        $journal->appendDone('BASE', 1);
        $journal->openIntentsSinceCheckpoint();
        $journal->appendIntent('save', 'P1', 'a:b', 2, 1, 'sha', 'owner');
        $file = $journal->files()[0];
        file_put_contents($file, '{"pid":"P1","rev":2,"sta', FILE_APPEND);

        self::assertCount(1, $journal->openIntentsSinceCheckpoint(), 'half a done line closes nothing');

        file_put_contents($file, 'te":"done"}' . "\n", FILE_APPEND);
        self::assertSame([], $journal->openIntentsSinceCheckpoint(), 'once complete, it is read from where the scan stopped');
    }

    /**
     * The bug this guards against: recovering a save whose "done" line was
     * lost, after later saves went through, rewrote current.md with that
     * older revision — harmless while replay never ran, a silent rollback
     * of the report once it runs on boot.
     */
    public function testAnIntentALaterWriteSupersededNeverRollsCurrentBack(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $page = $storage->create(self::PATH, $this->frontmatter(), 'v1 body', 'owner');
        $storage->save(self::PATH, $this->frontmatter(), 'v2 body', 1, 'owner');
        $storage->save(self::PATH, $this->frontmatter(), 'v3 body', 2, 'owner');
        $this->appendOldIntent('save', $page->pid, self::PATH, 2);

        $outcomes = $storage->replayJournal();

        self::assertSame('superseded', $outcomes[0]['outcome']);
        self::assertStringContainsString('v3 body', $storage->read(self::PATH)->body);
        self::assertSame([], $storage->replayJournal(), 'and it is closed');
    }

    public function testBootReplayLeavesARecentIntentAloneAndRecoversAnOldOne(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $page = $storage->create(self::PATH, $this->frontmatter(), 'v1 body', 'owner');
        $document = $storage->readRevision(self::PATH, 1);
        file_put_contents($this->dataRoot . '/pages/reports/mri/mioveni/x/rev/0002.md.gz', gzencode(str_replace('v1 body', 'v2 body', $document), 9));
        $storage->replayCrashedWrites(60); // the first run only takes its bearings

        // Just now: may be a write still running
        (new Journal($this->dataRoot . '/journal'))->appendIntent('save', $page->pid, self::PATH, 2, 1, 'sha', 'owner');
        self::assertSame([], $storage->replayCrashedWrites(60));
        self::assertSame(1, $storage->read(self::PATH)->rev);

        // Five minutes ago: crashed
        $this->appendOldIntent('save', $page->pid, self::PATH, 2);
        $outcomes = $storage->replayCrashedWrites(60);

        self::assertSame('recovered', $outcomes[0]['outcome']);
        self::assertSame(2, $storage->read(self::PATH)->rev);
        self::assertStringContainsString('v2 body', $storage->read(self::PATH)->body);
        self::assertSame([], $storage->replayCrashedWrites(60));
    }

    public function testBootReplayDoesNothingWhileAnotherRequestHoldsTheLock(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $page = $storage->create(self::PATH, $this->frontmatter(), 'v1 body', 'owner');
        $storage->replayCrashedWrites(60); // the first run only takes its bearings
        $this->appendOldIntent('save', $page->pid, self::PATH, 2);

        $lock = fopen($this->dataRoot . '/journal/.replay.lock', 'c');
        self::assertNotFalse($lock);
        flock($lock, LOCK_EX);
        try {
            self::assertSame([], $storage->replayCrashedWrites(60));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertSame('discarded', $storage->replayCrashedWrites(60)[0]['outcome'], 'no rev file: the write never happened');
    }

    public function testTheCommandListsByPidOnDryRunThenReplays(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $page = $storage->create(self::PATH, $this->frontmatter(), 'v1 body', 'owner');
        $this->appendOldIntent('save', $page->pid, self::PATH, 2);
        $command = new JournalReplayCommand(new MaintenanceRunner([new JournalReplayTask($storage)], $this->dataRoot, new AuditLog($this->dataRoot . '/audit')));

        [$code, $dry] = $this->runCommand($command, ['--dry-run']);
        self::assertSame(0, $code);
        self::assertStringContainsString('pid ' . $page->pid . ' rev 2', $dry);
        self::assertStringContainsString('1 unfinished write(s) older than 60s', $dry);
        self::assertStringNotContainsString('mioveni', $dry, 'pages are named by pid, never by path');
        self::assertCount(1, $storage->staleIntents(60), 'a dry run changes nothing');

        [$code, $out] = $this->runCommand($command, []);
        self::assertSame(0, $code);
        self::assertStringContainsString('replayed: 1 discarded', $out);
        self::assertSame([], $storage->staleIntents(0));
    }

    /** @return array<string, mixed> */
    private function frontmatter(): array
    {
        return ['title' => 'RM cerebral nativ', 'modality' => 'MR', 'visibility' => 'private'];
    }

    /** --json prints the run report, for tools: one shape for every maintenance command */
    public function testJsonOutputIsTheRunReportByPid(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $page = $storage->create(self::PATH, $this->frontmatter(), 'v1 body', 'owner');
        $this->appendOldIntent('save', $page->pid, self::PATH, 2);
        $command = new JournalReplayCommand(new MaintenanceRunner([new JournalReplayTask($storage)], $this->dataRoot, new AuditLog($this->dataRoot . '/audit')));

        [$code, $out] = $this->runCommand($command, ['--dry-run', '--json']);

        self::assertSame(0, $code);
        $report = json_decode($out, true);
        self::assertSame('journal:replay', $report['task']);
        self::assertSame('check', $report['mode']);
        self::assertSame(['min_age' => 60], $report['options']);
        self::assertSame(['unfinished' => 1], $report['summary']);
        self::assertSame(['pid' => $page->pid, 'rev' => 2, 'outcome' => 'save'], array_intersect_key($report['items'][0], ['pid' => 1, 'rev' => 1, 'outcome' => 1]));
        self::assertStringNotContainsString('mioveni', $out, 'pages by pid, never by path');
        self::assertCount(1, glob($this->dataRoot . '/maintenance/runs/*.json') ?: [], 'CLI runs are kept too, for the admin screen');
    }

    private function appendOldIntent(string $op, string $pid, string $path, int $rev): void
    {
        $line = [
            'ts' => date('Y-m-d\TH:i:sP', time() - 300), 'op' => $op, 'pid' => $pid, 'path' => $path,
            'rev' => $rev, 'base_rev' => $rev - 1, 'body_sha' => 'sha', 'actor' => 'owner', 'state' => 'intent',
        ];
        @mkdir($this->dataRoot . '/journal', 0775, true);
        file_put_contents($this->dataRoot . '/journal/' . date('Y-m-d') . '.ndjson', json_encode($line) . "\n", FILE_APPEND);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private function runCommand(JournalReplayCommand $command, array $args): array
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
