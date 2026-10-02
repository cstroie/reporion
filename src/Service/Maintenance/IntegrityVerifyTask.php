<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Audit\AuditLog;
use Reporion\Service\IndexMaintenance;
use Reporion\Service\Revisions;
use Reporion\Storage\FlatFile;
use Throwable;

/**
 * integrity:verify — the archive still holds what it says it holds
 * (roadmap phase 23). Check only: nothing is ever repaired here; a
 * failure is the owner's to look at.
 *
 * - every revision in a page's revlog has its `rev/NNNN.md.gz`, it
 *   gunzips, and its bytes hash to the revlog's sha256; no revision file
 *   exists beyond the newest; `current.md` is the newest revision, byte for
 *   byte (D2);
 * - every signature's digest, recomputed from its stored revision, matches
 *   (Service\Revisions::signature(), every signed revision — D3);
 * - every file in data/media/ hashes to its own name, and every page's
 *   media.json entry points at a file that exists (D10/D27);
 * - no journal intent is older than the replay age (invariant 7);
 * - the index agrees with the disk (index:verify);
 * - with `backup`: every signed revision exists, byte for byte, in that
 *   copy of data/ (a mounted rsync snapshot, D22) — read only.
 *
 * Pages are named by pid; a page whose meta.json cannot be read has no
 * pid, and is named by its path hash, as the audit trail does (invariant 8).
 */
final class IntegrityVerifyTask implements MaintenanceTask
{
    /** The age past which an open journal intent counts as a crash left behind */
    private const JOURNAL_AGE = 60;

    public function __construct(
        private readonly FlatFile $storage,
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
            if (!str_starts_with($backup, '/') || !is_dir($backup . '/pages')) {
                $this->problem($report, null, null, 'backup_unreadable', 'Not a copy of data/ (no pages/ directory there)');
                $backup = '';
            }
        }

        foreach ($this->storage->allPaths() as $path) {
            $this->page($report, $path, $backup);
        }
        $this->media($report);
        $this->journal($report);
        $this->indexDrift($report);

        if ($report->summary()['problems'] > 0) {
            $report->note('Nothing was repaired. A revision or signature problem means the files changed outside Reporion: restore that page from a backup. Index drift is fixed by rebuilding the index.');
            $report->fail();
        }

        return $report;
    }

    /**
     * One page: its revisions, current.md, signatures, media references
     * and, with a backup, its signed revisions there.
     */
    private function page(MaintenanceReport $report, string $path, string $backup): void
    {
        $report->count('pages');
        try {
            $page = $this->storage->read($path);
        } catch (Throwable) {
            $this->problem($report, null, null, 'page_unreadable', 'meta.json or current.md does not read', ['path_hash' => AuditLog::pathHash($path)]);

            return;
        }
        $dir = $this->dataRoot . '/pages/' . str_replace(':', '/', $path);

        // Revisions: each one listed, present, readable and the bytes the revlog recorded
        $listed = [];
        $broken = [];
        $newest = null;
        foreach ($page->revlog as $entry) {
            $n = (int) ($entry['n'] ?? 0);
            $listed[$n] = true;
            $report->count('revisions');
            // Read here, quietly: Storage's reader would warn on a corrupt file
            $file = \sprintf('%s/rev/%04d.md.gz', $dir, $n);
            if (!is_file($file)) {
                $this->problem($report, $page->pid, $n, 'rev_missing', 'The revision file is gone');
                $broken[$n] = true;

                continue;
            }
            $bytes = @gzdecode((string) file_get_contents($file));
            if ($bytes === false) {
                $this->problem($report, $page->pid, $n, 'rev_corrupt', 'The revision file does not gunzip');
                $broken[$n] = true;

                continue;
            }
            $sha = \is_string($entry['sha256'] ?? null) ? $entry['sha256'] : '';
            if ($sha !== '' && !hash_equals($sha, hash('sha256', $bytes))) {
                $this->problem($report, $page->pid, $n, 'rev_changed', 'The revision bytes are not the ones the revlog recorded');
            }
            if ($n === $page->rev) {
                $newest = $bytes;
            }
        }
        for ($n = 1; $n <= $page->rev; ++$n) {
            if (!isset($listed[$n])) {
                $this->problem($report, $page->pid, $n, 'revlog_gap', 'The revlog has no entry for this revision');
            }
        }
        foreach (glob($dir . '/rev/*.md.gz') ?: [] as $file) {
            $n = (int) basename($file, '.md.gz');
            if ($n > $page->rev) {
                $this->problem($report, $page->pid, $n, 'rev_extra', 'A revision file beyond the newest revision');
            }
        }
        if ($newest !== null && $newest !== (string) file_get_contents($dir . '/current.md')) {
            $this->problem($report, $page->pid, $page->rev, 'current_stale', 'current.md is not the newest revision (D2)');
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
            $found = isset($broken[$rev]) ? null : $this->revisions->signature($page, $rev);
            if ($found === null || !$found['matches']) {
                $this->problem($report, $page->pid, $rev, 'signature_mismatch', 'The signed revision no longer gives the recorded digest');
            }
        }

        // Media the page points at
        try {
            $media = $this->storage->mediaOf($path);
        } catch (Throwable) {
            $media = [];
        }
        foreach ($media as $entry) {
            $key = $entry['sha256'] . '.' . $entry['ext'];
            if ($this->storage->mediaFile($entry['sha256'], $entry['ext']) === null) {
                $this->problem($report, $page->pid, null, 'media_missing', 'media.json lists a file that is not in data/media', ['file' => $key]);
            }
        }

        // The backup: every signed revision there, byte for byte
        if ($backup !== '') {
            $copyDir = $backup . '/pages/' . str_replace(':', '/', $path);
            foreach (array_keys($signedRevs) as $rev) {
                $report->count('backup_checked');
                $local = \sprintf('%s/rev/%04d.md.gz', $dir, $rev);
                $copy = \sprintf('%s/rev/%04d.md.gz', $copyDir, $rev);
                if (!is_file($copy)) {
                    $this->problem($report, $page->pid, $rev, 'backup_missing', 'The signed revision is not in the backup');
                } elseif (!is_file($local) || hash_file('sha256', $copy) !== hash_file('sha256', $local)) {
                    $this->problem($report, $page->pid, $rev, 'backup_differs', 'The backup holds other bytes for this signed revision');
                }
            }
        }
    }

    /**
     * Every file in data/media/ hashes to its name (content-addressed, D10).
     */
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

    private function journal(MaintenanceReport $report): void
    {
        foreach ($this->storage->staleIntents(self::JOURNAL_AGE) as $intent) {
            $this->problem(
                $report,
                isset($intent['pid']) ? (string) $intent['pid'] : null,
                isset($intent['rev']) ? (int) $intent['rev'] : null,
                'journal_open',
                'A write a crash left unfinished — Admin → Maintenance → Unfinished writes',
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
                $this->problem($report, (string) $pid, null, $outcome, 'The index disagrees with the disk — rebuild it');
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
