<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Auth\SystemActor;
use Reporion\Exception\LibraryException;
use Reporion\Service\Library\Library;
use RuntimeException;

/**
 * library:abstracts: the abstract page (Service\Library\Abstracts) of every library source that has
 * none, for the sources added before abstracts were kept (2026-11-01). The record's `csl.abstract`;
 * else, with the lookups on (Admin → Settings → Library) and not `offline`, the abstract PubMed or
 * CrossRef has for its DOI or PMID (only the identifier is sent, the record is not rewritten);
 * else the article's own, when its text marks it plainly. No AI. Each page one revision by
 * `system:librarian` (D41), audited on behalf of whoever ran it. Check says what would be made
 * and writes nothing (it does ask the lookups, as library:relookup's dry run). --limit counts
 * the pages made. The run report names sources by pid; the progress line by path (a library key names no patient).
 */
final class LibraryAbstractsTask implements MaintenanceTask, ProgressAware
{
    /** @var ?callable(string, array<string, mixed>): void */
    private $progress = null;

    public function __construct(private readonly Library $library)
    {
    }

    public function name(): string
    {
        return 'library:abstracts';
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
            'offline' => \in_array($raw['offline'] ?? false, [true, '1', 'on', 'yes', 'true'], true),
        ];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $apply = $mode === self::APPLY;
        $limit = (int) $options['limit'];
        $online = $options['offline'] !== true && $this->library->lookupOn();
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        foreach ([$apply ? 'made' : 'would_make', 'from_record', 'from_text', 'has', 'none', 'failed', ...($apply ? ['remaining'] : [])] as $key) {
            $report->count($key, 0);
        }
        $paths = $this->library->sourcePaths();
        $made = 0;
        foreach ($paths as $n => $path) {
            if ($limit > 0 && $made >= $limit) {
                if ($apply) {
                    $report->count('remaining', \count($paths) - $n);
                }
                break;
            }
            $this->tell('start', ['n' => $n + 1, 'total' => \count($paths), 'label' => $path]);
            try {
                $done = $this->library->fillAbstract($path, SystemActor::LIBRARIAN, $online, !$apply, $actor);
            } catch (LibraryException|RuntimeException) {
                $report->unreadable($path);
                $this->tell('done', ['status' => 'unreadable']);
                continue;
            }
            $this->tell('done', ['status' => $done['status'] . ($done['from'] !== null ? ' (' . $done['from'] . ')' : '')]);
            if ($done['status'] === 'made') {
                ++$made;
                $report->count($apply ? 'made' : 'would_make');
                $report->count('from_' . $done['from']);
                $report->item($done['pid'], null, $apply ? 'made' : 'would_make', 'from ' . $done['from']);
            } else {
                $report->count($done['status']);
                if ($done['status'] === 'failed') {
                    $report->item($done['pid'], null, 'failed', 'not written');
                }
            }
        }
        if ($online === false && $options['offline'] !== true) {
            $report->note('The online lookups are off (Admin → Settings → Library): only the records and the articles\' own texts were read.');
        }

        return $report;
    }

    /** @param array<string, mixed> $info */
    private function tell(string $event, array $info): void
    {
        if ($this->progress !== null) {
            ($this->progress)($event, $info);
        }
    }
}
