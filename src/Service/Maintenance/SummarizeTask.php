<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\Assistant;
use Reporion\Storage\FlatFile;
use Reporion\Support\Conclusion;
use Reporion\Support\ConclusionSummary;
use Reporion\Support\MetaText;
use Reporion\Support\ReportPath;
use Reporion\Support\SummaryLine;
use Throwable;

/**
 * pages:summarize — asks the assistant's reserved `summary` prompt
 * (docs/FORMATS.md §13) for the one-line `summary` of each report under a
 * namespace that has none (or only the save's stopgap first sentence, Support\ConclusionSummary), and writes it as one new revision by the actor
 * (TODO.md idea 12). Check lists what it would ask and sends nothing. Only
 * `draft` and `archived` reports: a signed one is counted, never rewritten
 * (D3). A page whose profile has no `summary` prompt page is skipped — the
 * feature is off there. Each page goes through Assistant::run() like the
 * Summarize button, so it is de-identified (Context), audited `ai.call`, and
 * one request at a time. The text sent is the report's conclusion section
 * when it has one (Support\Conclusion), else the whole body; --limit bounds a run, and the pages done have a
 * summary, so the next run carries on. A page that fails (the prompt still
 * held an identifier, the server's error, an empty answer) is listed with
 * its reason and left as it is; a server that cannot be reached (timeout,
 * refused, busy…) stops the run. Pages are named by pid, never by text.
 */
final class SummarizeTask implements MaintenanceTask, ProgressAware
{
    private const ACTION = 'summary';

    /** Reasons that mean the server, not the page: the run stops */
    private const PAGE_ONLY = ['identifier_leak', 'provider_error'];

    /** Pages in a row that failed on their own before the run gives up */
    private const MAX_STREAK = 5;

    /** @var ?callable(string, array<string, mixed>): void */
    private $progress = null;

    public function __construct(
        private readonly FlatFile $storage,
        private readonly AuditLog $audit,
        private readonly Actions $actions,
        private readonly Assistant $assistant,
        private readonly int $timeout = 120,
    ) {
    }

    public function name(): string
    {
        return 'pages:summarize';
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
        $namespace = strtolower(trim(\is_string($raw['namespace'] ?? null) ? $raw['namespace'] : '', ': '));
        if ($namespace !== '' && preg_match('/^[a-z0-9][a-z0-9_.-]*(:[a-z0-9][a-z0-9_.-]*)*$/', $namespace) !== 1) {
            throw new InvalidArgumentException('A namespace looks like reports:mri');
        }

        return [
            'namespace' => $namespace,
            'limit' => is_numeric($limit) && (int) $limit > 0 ? (int) $limit : 0,
            'overwrite' => \in_array($raw['overwrite'] ?? false, [true, '1', 'on', 'yes', 'true'], true),
        ];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $apply = $mode === self::APPLY;
        $limit = (int) $options['limit'];
        $namespace = (string) $options['namespace'];
        $overwrite = $options['overwrite'] === true;
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        foreach ([$apply ? 'summarized' : 'would_summarize', 'has_summary', 'signed', 'no_prompt', 'failed', ...($apply ? ['remaining'] : [])] as $key) {
            $report->count($key, 0);
        }

        $todo = [];
        foreach ($this->storage->allPaths() as $path) {
            if (!ReportPath::isReport($path) || ($namespace !== '' && !str_starts_with($path, $namespace . ':'))) {
                continue;
            }
            try {
                $page = $this->storage->read($path);
            } catch (Throwable) {
                continue;
            }
            if ($page->status === 'signed') {
                $report->count('signed');
                continue;
            }
            // A summary nobody wrote — the stopgap first sentence of the conclusion — counts as none
            $current = trim(MetaText::text($page->frontmatter['summary'] ?? null));
            if (!$overwrite && $current !== '' && !ConclusionSummary::isStopgap($current, $page->body)) {
                $report->count('has_summary');
                continue;
            }
            $todo[] = $page->path;
        }
        sort($todo);

        $user = new User($actor, '', true, [], true, '', '');
        $total = $limit > 0 ? min($limit, \count($todo)) : \count($todo);
        $streak = 0;
        $n = 0;
        foreach ($todo as $path) {
            $action = $this->actions->special($path, self::ACTION);
            if ($action === null) {
                $report->count('no_prompt');
                continue;
            }
            if ($limit > 0 && $n >= $limit) {
                $report->count($apply ? 'remaining' : 'would_summarize');
                continue;
            }
            ++$n;
            if (!$apply) {
                $report->count('would_summarize');
                continue;
            }

            @set_time_limit($this->timeout + 30);
            $page = $this->storage->read($path);
            $this->tell('start', ['n' => $n, 'total' => $total, 'label' => $page->pid]);
            try {
                $answer = '';
                // Of the conclusion when the report has one, else of its text
                $conclusion = Conclusion::of($page->body);
                $this->assistant->run($action, $page, $conclusion ?? $page->body, $conclusion !== null ? 'conclusion' : 'text', null, '', $user, null, static function (string $piece) use (&$answer): void {
                    $answer .= $piece;
                });
                $line = SummaryLine::tidy($answer);
                if ($line === '') {
                    throw new AiException('empty', 'The assistant gave no summary');
                }
                $frontmatter = $page->frontmatter;
                $frontmatter['summary'] = $line;
                $saved = $this->storage->save($path, $frontmatter, $page->body, $page->rev, $actor, 'assisted: summary', auto: true);
                $this->audit->record('page.save', $actor, null, $saved->pid, $saved->path, $saved->rev, extra: ['reason' => 'ai-summary']);
                $report->count('summarized');
                $streak = 0;
                $this->tell('done', ['status' => 'ok']);
            } catch (AiException $e) {
                $report->count('failed');
                $report->item($page->pid, $page->rev, 'failed', $e->reason);
                $this->tell('done', ['status' => 'failed: ' . $e->reason]);
                if (!\in_array($e->reason, [...self::PAGE_ONLY, 'empty'], true)) {
                    $report->note('Stopped: ' . $e->reason . ' — the server did not answer as it should; the next run carries on from here.');
                    $report->fail();

                    return $this->remaining($report, $todo, $n, $apply);
                }
                if (++$streak >= self::MAX_STREAK) {
                    $report->note('Stopped: ' . self::MAX_STREAK . ' pages in a row failed.');
                    $report->fail();

                    return $this->remaining($report, $todo, $n, $apply);
                }
            } catch (Throwable $e) {
                // A conflict (the page changed while the assistant worked) or a write error: that page only
                $report->count('failed');
                $report->item($page->pid, $page->rev, 'failed', $e::class);
                $this->tell('done', ['status' => 'failed']);
            }
        }

        return $report;
    }

    /** What an early stop leaves for the next run, counted */
    private function remaining(MaintenanceReport $report, array $todo, int $done, bool $apply): MaintenanceReport
    {
        if ($apply) {
            $report->count('remaining', max(0, \count($todo) - $done));
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
