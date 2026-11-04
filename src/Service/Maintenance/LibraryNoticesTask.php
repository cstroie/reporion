<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Auth\SystemActor;
use Reporion\Exception\LibraryException;
use Reporion\Service\Library\Library;
use Reporion\Storage\AtomicWriter;
use RuntimeException;

/**
 * library:notices: every library source's DOI or PMID asked of CrossRef and PubMed (the identifier
 * only) for a retraction, an expression of concern or a correction (Library::checkNotice(),
 * Support\SourceNotice, 2026-11-04), the answer kept as the page's `notice`. Each change one revision
 * by `system:librarian` (D41), audited on behalf of whoever ran it. A source with no identifier
 * is skipped. Check asks and says what would change. --limit counts the sources asked, so it bounds
 * the requests; a source asked within the last 30 days is passed over (`--all` asks every one), so
 * repeated runs walk through a big library. When each was asked is a status note,
 * `data/maintenance/library-notices.json` (path => day), not data (invariant 1): lost, every source
 * is asked again. The progress line names a source by its path (a library key names no patient);
 * the run report by pid.
 */
final class LibraryNoticesTask implements MaintenanceTask, ProgressAware
{
    /** @var ?callable(string, array<string, mixed>): void */
    private $progress = null;

    /** Days a source's answer is trusted before it is asked again */
    public const FRESH_DAYS = 30;

    public function __construct(private readonly Library $library, private readonly ?string $stateFile = null)
    {
    }

    public function name(): string
    {
        return 'library:notices';
    }

    public function modes(): array
    {
        return [self::CHECK, self::APPLY];
    }

    public function setProgress(?callable $progress): void
    {
        $this->progress = $progress;
    }

    public function options(array $raw): array
    {
        $limit = $raw['limit'] ?? 0;

        return [
            'limit' => is_numeric($limit) && (int) $limit > 0 ? (int) $limit : 0,
            'all' => \in_array($raw['all'] ?? false, [true, '1', 'on', 'yes', 'true'], true),
        ];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $apply = $mode === self::APPLY;
        $limit = (int) $options['limit'];
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        foreach ([$apply ? 'flagged' : 'would_flag', $apply ? 'cleared' : 'would_clear', 'recent', 'unchanged', 'no_id', 'none', 'failed', 'unreadable', ...($apply ? ['remaining'] : [])] as $key) {
            $report->count($key, 0);
        }
        if (!$this->library->lookupOn()) {
            $report->note('The online lookups are off (Admin → Settings → Library): nothing was asked.');

            return $report;
        }
        $paths = $this->library->sourcePaths();
        $asked = 0;
        $state = $this->state();
        $fresh = date('Y-m-d', time() - self::FRESH_DAYS * 86400);
        foreach ($paths as $n => $path) {
            if ($options['all'] !== true && ($state[$path] ?? '') >= $fresh) {
                $report->count('recent');
                continue;
            }
            if ($limit > 0 && $asked >= $limit) {
                if ($apply) {
                    $report->count('remaining', \count($paths) - $n);
                }
                break;
            }
            // Up to three lookups a source, each with its own timeout
            @set_time_limit(60);
            $this->tell('start', ['n' => $n + 1, 'total' => \count($paths), 'label' => $path]);
            try {
                $done = $this->library->checkNotice($path, SystemActor::LIBRARIAN, !$apply, $actor);
            } catch (LibraryException|RuntimeException|\Reporion\Exception\PageNotFoundException) {
                $report->count('unreadable');
                $this->tell('done', ['status' => 'unreadable']);
                continue;
            }
            if ($apply && !\in_array($done['status'], ['failed'], true)) {
                $state[$path] = date('Y-m-d');
                $this->remember($state);
            }
            $this->tell('done', ['status' => $done['status'] . ($done['notice'] !== null ? ' (' . $done['notice'] . ')' : '')]);
            if ($done['status'] !== 'no_id') {
                ++$asked;
            }
            $key = match ($done['status']) {
                'flagged' => $apply ? 'flagged' : 'would_flag',
                'cleared' => $apply ? 'cleared' : 'would_clear',
                default => $done['status'],
            };
            $report->count($key);
            if (\in_array($done['status'], ['flagged', 'cleared'], true)) {
                $report->item($done['pid'], null, $key, (string) ($done['notice'] ?? 'no notice'));
            }
        }
        if (($report->summary()['failed'] ?? 0) > 0) {
            $report->note('PubMed or CrossRef did not answer for some sources (not reached, or too many requests): run it again later for them.');
        }

        return $report;
    }

    /** @return array<string, string> path => the day it was asked */
    private function state(): array
    {
        $json = $this->stateFile !== null && is_file($this->stateFile) ? json_decode((string) file_get_contents($this->stateFile), true) : null;

        return \is_array($json) ? array_filter($json, static fn (mixed $v, mixed $k): bool => \is_string($v) && \is_string($k), ARRAY_FILTER_USE_BOTH) : [];
    }

    /** @param array<string, string> $state */
    private function remember(array $state): void
    {
        if ($this->stateFile === null) {
            return;
        }
        try {
            if (!is_dir(\dirname($this->stateFile))) {
                @mkdir(\dirname($this->stateFile), 0775, true);
            }
            AtomicWriter::put($this->stateFile, (string) json_encode($state, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // A status note: asked again next time
        }
    }

    /** @param array<string, mixed> $info */
    private function tell(string $event, array $info): void
    {
        if ($this->progress !== null) {
            ($this->progress)($event, $info);
        }
    }
}
