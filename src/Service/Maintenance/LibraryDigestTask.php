<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Audit\AuditLog;
use Reporion\Auth\SystemActor;
use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Exception\LibraryException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Service\Ai\Assistant;
use Reporion\Service\Library\Analyses;
use Reporion\Service\Library\Library;
use Reporion\Service\Library\SourceAnalysis;
use Reporion\Storage\FileTree;
use Reporion\Support\LibrarySource;
use RuntimeException;

/**
 * library:digest (owner, 2026-11-15): every library source gets its overview without anyone
 * pressing a button. For each source that lacks one, the assistant's one-line summary (the
 * `summary` prompt, into the page's `summary`: what the list, the quick look and a citation's
 * card show) and its key points (`source-points`, kept as the saved analysis
 * `library:{key}:source-points`, Service\Library\Analyses). Both read the source's abstract page
 * when it has one (one request each), else its text as the buttons do. What is there already is
 * never made again. The revisions are `system:librarian`'s (D41), noted `assisted: {prompt}`,
 * audited on behalf of whoever ran it: an amendment of D8 for these two texts, which are about the
 * source and never part of its text. Check says what would be asked and sends nothing. --limit
 * counts the sources asked. A source too long to analyse, or whose answer is empty, is listed and
 * left; a server that cannot be reached stops the run. Sources are named by pid.
 */
final class LibraryDigestTask implements MaintenanceTask, ProgressAware
{
    /** Reasons that mean the source, not the server: the run goes on */
    private const SOURCE_ONLY = ['identifier_leak', 'provider_error'];

    private const MAX_STREAK = 5;

    /** @var ?callable(string, array<string, mixed>): void */
    private $progress = null;

    public function __construct(
        private readonly Library $library,
        private readonly FileTree $storage,
        private readonly AuditLog $audit,
        private readonly SourceAnalysis $analysis,
        private readonly Analyses $analyses,
        private readonly int $timeout = 120,
    ) {
    }

    public function name(): string
    {
        return 'library:digest';
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

        return ['limit' => is_numeric($limit) && (int) $limit > 0 ? (int) $limit : 0];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $apply = $mode === self::APPLY;
        $limit = (int) $options['limit'];
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        foreach ([$apply ? 'summarized' : 'would_summarize', $apply ? 'points_kept' : 'would_keep_points', 'complete', 'no_text', 'no_prompt', 'failed', ...($apply ? ['remaining'] : [])] as $key) {
            $report->count($key, 0);
        }
        // The assistant's requests are the librarian's: its name on the audit's ai.call lines and on the kept analysis
        $librarian = new User(SystemActor::LIBRARIAN, '', true, [], true, '', '');
        $paths = $this->library->sourcePaths();
        $asked = 0;
        $streak = 0;
        foreach ($paths as $n => $path) {
            try {
                $page = $this->storage->read($path);
            } catch (PageNotFoundException|RuntimeException) {
                $report->unreadable($path);
                continue;
            }
            if (!LibrarySource::isSource($page->frontmatter) || $page->status === 'signed') {
                continue;
            }
            $todo = $this->missing($page, $librarian);
            if ($todo === []) {
                $report->count('complete');
                continue;
            }
            $available = $this->analysis->available($path);
            $todo = array_values(array_filter($todo, static fn (string $task): bool => \in_array($task, $available, true)));
            if ($todo === []) {
                $report->count('no_prompt');
                continue;
            }
            try {
                // A record with no file and no abstract page: nothing to read it from
                if (trim($page->body) === '' && $this->analyses->abstractShown($page, $librarian) === null) {
                    throw new LibraryException(LibraryException::NO_TEXT);
                }
                foreach ($todo as $task) {
                    $this->analysis->plan($page, $task);
                }
            } catch (LibraryException) {
                // No text and no abstract, or too long to condense: nothing to read it from
                $report->count('no_text');
                continue;
            }
            if ($limit > 0 && $asked >= $limit) {
                if ($apply) {
                    $report->count('remaining');
                }
                continue;
            }
            ++$asked;
            if (!$apply) {
                foreach ($todo as $task) {
                    $report->count($task === 'summary' ? 'would_summarize' : 'would_keep_points');
                }
                $report->item($page->pid, null, 'would_make', implode(', ', $todo));
                continue;
            }
            $this->tell('start', ['n' => $asked, 'total' => $limit > 0 ? min($limit, \count($paths)) : \count($paths), 'label' => $page->pid]);
            $made = [];
            try {
                foreach ($todo as $task) {
                    @set_time_limit(Assistant::maxSeconds($this->timeout) * (SourceAnalysis::MAX_PARTS + 1) + 30);
                    $done = $this->analysis->run($task, $page, $librarian, null, static function (int $step, int $steps): void {
                    });
                    if ($task === 'summary') {
                        $line = self::line($done['result']);
                        if ($line === '') {
                            throw new AiException('provider_error', 'An empty summary');
                        }
                        $frontmatter = $page->frontmatter;
                        $frontmatter['summary'] = $line;
                        $page = $this->storage->save($path, $frontmatter, $page->body, $page->rev, SystemActor::LIBRARIAN, 'assisted: summary');
                        $this->audit->record('page.save', SystemActor::LIBRARIAN, null, $page->pid, $page->path, $page->rev, extra: ['reason' => 'ai-summary', 'on_behalf_of' => $actor]);
                        $report->count('summarized');
                    } elseif (trim($done['result']) === '') {
                        throw new AiException('provider_error', 'An empty answer');
                    } else {
                        $kept = $this->analyses->save($page, SourceAnalysis::TASKS[$task], 'Key points', $done['result'], $librarian, $done['provider'], [], $done['from']);
                        if ($kept !== null) {
                            $this->audit->record($kept['created'] ? 'page.create' : 'page.save', SystemActor::LIBRARIAN, null, $kept['pid'], $kept['path'], $kept['rev'], extra: ['assisted' => SourceAnalysis::TASKS[$task], 'on_behalf_of' => $actor]);
                            $report->count('points_kept');
                        }
                    }
                    $made[] = $task;
                }
                $streak = 0;
                $report->item($page->pid, null, 'made', implode(', ', $made));
                $this->tell('done', ['status' => implode(', ', $made)]);
            } catch (AiException $e) {
                $report->count('failed');
                $report->item($page->pid, null, 'failed', $e->reason);
                $this->tell('done', ['status' => 'failed: ' . $e->reason]);
                // The server, not this source (not reached, busy, not set up): no point asking for the next one
                if (!\in_array($e->reason, self::SOURCE_ONLY, true) || ++$streak >= self::MAX_STREAK) {
                    $report->note('The assistant did not answer (' . $e->reason . '): the run stopped; the sources done keep what was made, run it again for the rest.');
                    break;
                }
            } catch (LibraryException) {
                // Nothing to read it from (no text, no abstract), or too long
                $report->count('no_text');
                $this->tell('done', ['status' => 'no text']);
            } catch (RuntimeException) {
                $report->count('failed');
                $report->item($page->pid, null, 'failed', 'not written');
                $this->tell('done', ['status' => 'failed']);
            }
        }

        return $report;
    }

    /**
     * What $page lacks: its `summary`, its kept key points
     *
     * @return list<string> SourceAnalysis tasks
     */
    private function missing(\Reporion\Storage\PageRecord $page, User $reader): array
    {
        $todo = [];
        if (!\is_string($page->frontmatter['summary'] ?? null) || trim($page->frontmatter['summary']) === '') {
            $todo[] = 'summary';
        }
        if ($this->analyses->shown($page, $reader, [SourceAnalysis::TASKS['points']]) === []) {
            $todo[] = 'points';
        }

        return $todo;
    }

    /** One line, no marker or quotes, at most 160 characters: as the source card's *Use as summary* (assets/js/library.js) */
    public static function line(string $answer): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $answer) ?: []), static fn (string $l): bool => $l !== '' && !str_starts_with($l, '```')));
        $line = (string) preg_replace('/^(#{1,6}|[-*•]|\d+[.)])\s+/u', '', $lines[0] ?? '');
        $line = str_replace('**', '', $line);
        $line = (string) preg_replace('/^(rezumat|summary)\s*:\s*/iu', '', $line);
        $line = trim((string) preg_replace('/^["\'„“”«»`]+|["\'„“”«»`]+$/u', '', $line));

        return mb_strlen($line) > 160 ? (string) preg_replace('/\s+\S*$/u', '', mb_substr($line, 0, 159)) . '…' : $line;
    }

    /** @param array<string, mixed> $info */
    private function tell(string $event, array $info): void
    {
        if ($this->progress !== null) {
            ($this->progress)($event, $info);
        }
    }
}
