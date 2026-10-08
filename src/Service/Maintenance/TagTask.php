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
use Reporion\Service\TagDictionary;
use Reporion\Storage\FlatFile;
use Reporion\Support\ReportPath;
use Reporion\Support\TagList;
use Throwable;

/**
 * pages:tag — asks the assistant's reserved `tags` prompt (docs/FORMATS.md
 * §13) for the tags of each report under a namespace that has none
 * (--overwrite: all of them), and writes them as one new revision by the
 * actor (2026-10-08). Check lists what it would ask and sends nothing. Only
 * `draft` and `archived` reports: a signed one is counted, never rewritten
 * (D3). Each page goes through Assistant::run() like the Suggest tags
 * button — the whole report, de-identified (Context), audited `ai.call`, one
 * request at a time — and its answer through Support\TagList, the button's
 * rule. An answer that is no tags (NONE, prose) leaves the page as it is,
 * counted `no_tags`. --limit bounds a run, and the pages done have tags, so
 * the next run carries on. A server that cannot be reached stops the run.
 * Pages are named by pid, never by text. With no `tags` prompt there is
 * nothing to do: the run says so.
 */
final class TagTask implements MaintenanceTask, ProgressAware
{
    private const ACTION = 'tags';

    /** Reasons that mean the page, not the server: the run carries on */
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
        private readonly TagDictionary $dictionary,
        private readonly int $timeout = 120,
    ) {
    }

    public function name(): string
    {
        return 'pages:tag';
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
        foreach ([$apply ? 'tagged' : 'would_tag', 'has_tags', 'no_prompt', 'no_tags', 'signed', 'failed', ...($apply ? ['remaining'] : [])] as $key) {
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
            if (!$overwrite && array_filter((array) ($page->frontmatter['tags'] ?? []), static fn (mixed $t): bool => \is_string($t) && trim($t) !== '') !== []) {
                $report->count('has_tags');
                continue;
            }
            $todo[] = $page->path;
        }
        sort($todo);

        $user = new User($actor, '', true, [], true, '', '');
        $total = $limit > 0 ? min($limit, \count($todo)) : \count($todo);
        $dictionary = $this->dictionary->all();
        $streak = 0;
        $n = 0;
        foreach ($todo as $path) {
            $action = $this->actions->special($path, self::ACTION);
            if ($action === null) {
                $report->count('no_prompt');
                continue;
            }
            if ($limit > 0 && $n >= $limit) {
                $report->count($apply ? 'remaining' : 'would_tag');
                continue;
            }
            ++$n;
            if (!$apply) {
                $report->count('would_tag');
                continue;
            }

            @set_time_limit($this->timeout + 30);
            $page = $this->storage->read($path);
            $this->tell('start', ['n' => $n, 'total' => $total, 'label' => $page->pid]);
            try {
                $answer = '';
                // The whole report: the conclusion often lacks the exam and the region
                $this->assistant->run($action, $page, $page->body, 'text', null, '', $user, null, static function (string $piece) use (&$answer): void {
                    $answer .= $piece;
                });
                $tags = TagList::parse($answer, $dictionary);
                if ($tags === []) {
                    $report->count('no_tags');
                    $streak = 0;
                    $this->tell('done', ['status' => 'no tags']);
                    continue;
                }
                $frontmatter = $page->frontmatter;
                $frontmatter['tags'] = $tags;
                $saved = $this->storage->save($path, $frontmatter, $page->body, $page->rev, $actor, 'assisted: tags', auto: true);
                $this->audit->record('page.save', $actor, null, $saved->pid, $saved->path, $saved->rev, extra: ['reason' => 'ai-tags']);
                $report->count('tagged');
                $streak = 0;
                $this->tell('done', ['status' => 'ok']);
            } catch (AiException $e) {
                $report->count('failed');
                $report->item($page->pid, $page->rev, 'failed', $e->reason);
                $this->tell('done', ['status' => 'failed: ' . $e->reason]);
                if (!\in_array($e->reason, self::PAGE_ONLY, true)) {
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
        if ($report->summary()['no_prompt'] > 0) {
            $report->note('No tags prompt (ai:profiles:{profile}:tags) for ' . $report->summary()['no_prompt'] . ' report(s): nothing asked for them.');
        }

        return $report;
    }

    /**
     * What an early stop leaves for the next run, counted
     *
     * @param list<string> $todo
     */
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
