<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Audit\AuditLog;
use Reporion\Exception\PageNotFoundException;
use Reporion\Service\IndexMaintenance;
use Reporion\Service\Revisions;
use Reporion\Storage\FileTree;
use Throwable;

/**
 * integrity:verify: the archive still holds what it says it holds
 * (roadmap phase 23; the 2.0 layout, D54). Check only: nothing is ever
 * repaired here; a failure is the owner's to look at.
 *
 * - every page's attic zip holds one entry per revision, each inflating to
 *   the bytes its revlog recorded, the chain unbroken, nothing extra; the
 *   page file is the newest revision, byte for byte (D2); the state
 *   database's revision and signature rows mirror the zip (`mirror_drift`);
 * - every signature's digest, recomputed from its stored revision, matches
 *   (Service\Revisions::signature(), every signed revision. D3);
 * - every file in data/media/ hashes to its own name, and every page's
 *   media row points at a file that exists (D10/D27);
 * - reporion.sqlite passes SQLite's integrity and foreign-key checks;
 * - no journal intent is older than the replay age (invariant 7);
 * - no page row lacks its page file, no attic zip lacks its row, and every
 *   page file under pages/ is a page: one moved by hand is `path_drift`, a
 *   copy `duplicate_pid` (pages:repair-paths), one no page is known for
 *   `stray_files` (pages:repair-paths adopts it);
 * - the index agrees with the disk (index:verify);
 * - with `backup`: every signed revision exists, byte for byte, in that
 *   copy of data/ (a mounted rsync snapshot, D22): read only.
 *
 * Pages are named by pid; a page that cannot be read has no pid, and is
 * named by its path hash, as the audit trail does (invariant 8).
 */
final class IntegrityVerifyTask implements MaintenanceTask
{
    /** The age past which an open journal intent counts as a crash left behind */
    private const JOURNAL_AGE = 60;

    /** Attic::problems()' codes as this report has always named them */
    private const ATTIC_CODES = [
        'entry_missing' => ['rev_missing', 'The revision is not in the attic zip'],
        'entry_corrupt' => ['rev_corrupt', 'The revision does not inflate or fails its CRC'],
        'sha_mismatch' => ['rev_changed', 'The revision bytes are not the ones the revlog recorded'],
        'size_mismatch' => ['rev_changed', 'The revision bytes are not the ones the revlog recorded'],
        'entry_unexpected' => ['rev_extra', 'The attic zip holds an entry beyond the revisions'],
        'chain_broken' => ['chain_broken', 'The revision history chain does not follow from the revision before (a revision or the revlog was changed or removed outside Reporion)'],
        'chain_missing' => ['chain_missing', 'The revlog entry lost its chain link'],
        'signature_orphan' => ['signature_orphan', 'A signature names a revision that is not there'],
        'current_stale' => ['current_stale', 'The page file is not the newest revision (D2)'],
        'mirror_drift' => ['mirror_drift', 'The state database\'s revisions or signatures disagree with the attic zip: db:recover'],
    ];

    public function __construct(
        private readonly FileTree $storage,
        private readonly Revisions $revisions,
        private readonly IndexMaintenance $index,
        private readonly string $dataRoot,
    ) {
    }

    public function name(): string
    {
        return 'integrity:verify';
    }

    public function modes(): array
    {
        return [self::CHECK];
    }

    public function options(array $raw): array
    {
        $backup = \is_string($raw['backup'] ?? null) ? trim($raw['backup']) : '';

        return ['backup' => rtrim($backup, '/')];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        foreach (['pages', 'revisions', 'signatures', 'media', 'problems'] as $key) {
            $report->count($key, 0);
        }
        $backup = (string) $options['backup'];
        if ($backup !== '') {
            $report->count('backup_checked', 0);
            if (!str_starts_with($backup, '/') || !is_dir($backup . '/attic')) {
                $this->problem($report, null, null, 'backup_unreadable', 'Not a copy of data/ (no attic/ directory there)');
                $backup = '';
            }
        }

        foreach ($this->storage->stateProblems() as $finding) {
            $this->problem($report, null, null, 'state_corrupt', 'reporion.sqlite: ' . $finding);
        }
        foreach ($this->storage->allPaths() as $path) {
            $this->page($report, $path, $backup);
        }
        $this->media($report);
        $this->stray($report);
        $this->journal($report);
        $this->indexDrift($report);

        if ($report->summary()['problems'] > 0) {
            $report->note('Nothing was repaired. A revision or signature problem means the files changed outside Reporion: restore that page from a backup. Index drift is fixed by rebuilding the index; a page file moved, copied or made by hand by pages:repair-paths; a mirror drift by db:recover.');
            $report->fail();
        }

        return $report;
    }

    /** One page: its attic zip and page file, signatures, media and, with a backup, its signed revisions there */
    private function page(MaintenanceReport $report, string $path, string $backup): void
    {
        $report->count('pages');
        try {
            $page = $this->storage->read($path);
            $found = $this->storage->historyProblems($path);
        } catch (Throwable) {
            $this->problem($report, null, null, 'page_unreadable', 'The page file or its attic zip does not read', ['path_hash' => AuditLog::pathHash($path)]);

            return;
        }
        $report->count('revisions', \count($page->revlog));

        $broken = [];
        foreach ($found as $finding) {
            [$code, $detail] = self::ATTIC_CODES[$finding['problem']] ?? ['attic_unreadable', 'The attic zip does not read'];
            $this->problem($report, $page->pid, $finding['rev'], $code, $detail);
            if ($finding['rev'] !== null && \in_array($code, ['rev_missing', 'rev_corrupt', 'rev_changed'], true)) {
                $broken[$finding['rev']] = true;
            }
        }

        // Signatures: recomputed from the stored revision (D3)
        $signedRevs = [];
        foreach ((array) ($page->meta['signatures'] ?? []) as $signature) {
            if (\is_array($signature) && isset($signature['rev'])) {
                $signedRevs[(int) $signature['rev']] = true;
            }
        }
        foreach (array_keys($signedRevs) as $rev) {
            $report->count('signatures');
            // A revision already reported missing or corrupt cannot be checked, nor be valid
            $check = isset($broken[$rev]) ? null : $this->revisions->signature($page, $rev);
            if ($check === null || !$check['matches']) {
                $this->problem($report, $page->pid, $rev, 'signature_mismatch', 'The signed revision no longer gives the recorded digest');
            }
        }

        try {
            $media = $this->storage->mediaOf($path);
        } catch (PageNotFoundException) {
            $this->problem($report, $page->pid, null, 'page_unreadable', 'The page went away while it was being checked', ['path_hash' => AuditLog::pathHash($path)]);

            return;
        }
        foreach ($media as $entry) {
            if ($this->storage->mediaFile($entry['sha256'], $entry['ext']) === null) {
                $this->problem($report, $page->pid, null, 'media_missing', 'The page lists a file that is not in data/media', ['file' => $entry['sha256'] . '.' . $entry['ext']]);
            }
        }

        // The backup: every signed revision there, byte for byte
        if ($backup !== '') {
            foreach (array_keys($signedRevs) as $rev) {
                $report->count('backup_checked');
                $copy = $this->storage->revisionInCopy($backup, $path, $rev);
                if ($copy === null) {
                    $this->problem($report, $page->pid, $rev, 'backup_missing', 'The signed revision is not in the backup');
                } elseif (isset($broken[$rev]) || $copy !== $this->storage->readRevision($path, $rev)) {
                    $this->problem($report, $page->pid, $rev, 'backup_differs', 'The backup holds other bytes for this signed revision');
                }
            }
        }
    }

    /** Every file in data/media/ hashes to its name (content-addressed, D10) */
    private function media(MaintenanceReport $report): void
    {
        foreach (glob($this->dataRoot . '/media/[0-9][0-9][0-9][0-9]/*') ?: [] as $file) {
            if (!is_file($file) || preg_match('/^([0-9a-f]{64})\.([a-z0-9]+)$/', basename($file), $m) !== 1) {
                continue;
            }
            $report->count('media');
            if (hash_file('sha256', $file) !== $m[1]) {
                $this->problem($report, null, null, 'media_changed', 'The file does not hash to its name', ['file' => basename($file)]);
            }
        }
    }

    /** Page files that are no page, page rows with no file, attic zips with no row */
    private function stray(MaintenanceReport $report): void
    {
        $what = [
            'moved' => ['path_drift', 'A page file moved by hand: pages:repair-paths puts the page where its file is'],
            'copy' => ['duplicate_pid', 'A copy of another page\'s file (the same pid): remove it, or re-create it as a page'],
            'new' => ['stray_files', 'A page file no page is known for: pages:repair-paths adopts it as a page'],
        ];
        foreach ($this->storage->strayPaths() as $path) {
            [$code, $detail] = $what[$this->storage->strayKind($path)];
            $this->problem($report, null, null, $code, $detail, ['path_hash' => AuditLog::pathHash($path)]);
        }
        foreach ($this->storage->missingPageFiles() as $path) {
            $this->problem($report, null, null, 'page_file_missing', 'A page whose file is not where it should be (moved away by hand?)', ['path_hash' => AuditLog::pathHash($path)]);
        }
        foreach ($this->storage->orphanAttics() as $path) {
            $this->problem($report, null, null, 'attic_orphan', 'An attic zip no page has: a crash, or one copied in by hand', ['path_hash' => AuditLog::pathHash($path)]);
        }
    }

    private function journal(MaintenanceReport $report): void
    {
        foreach ($this->storage->staleIntents(self::JOURNAL_AGE) as $intent) {
            $this->problem(
                $report,
                isset($intent['pid']) ? (string) $intent['pid'] : null,
                isset($intent['rev']) ? (int) $intent['rev'] : null,
                'journal_open',
                'A write a crash left unfinished. Admin → Maintenance → Unfinished writes',
            );
        }
    }

    private function indexDrift(MaintenanceReport $report): void
    {
        try {
            $found = $this->index->verify();
        } catch (Throwable) {
            // A page index:verify cannot read is already reported above
            $this->problem($report, null, null, 'index_unchecked', 'The index could not be compared: a page does not read');

            return;
        }
        foreach (['orphans' => 'index_orphan', 'missing' => 'index_missing', 'drifted' => 'index_drifted'] as $key => $outcome) {
            foreach ($found[$key] as $pid) {
                $this->problem($report, (string) $pid, null, $outcome, 'The index disagrees with the disk: rebuild it');
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function problem(MaintenanceReport $report, ?string $pid, ?int $rev, string $outcome, string $detail, array $data = []): void
    {
        $report->item($pid, $rev, $outcome, $detail, $data);
        $report->count('problems');
        $report->count($outcome);
    }
}
