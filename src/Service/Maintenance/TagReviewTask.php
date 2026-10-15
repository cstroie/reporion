<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Index\IndexInterface;
use Reporion\Service\Ai\Action;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\Assistant;
use Reporion\Service\TagDictionary;
use Reporion\Support\Rads;
use Reporion\Support\Slug;
use Reporion\Support\TagNormalize;
use Reporion\Support\TagProposals;

/**
 * tags:review (2026-10-15): proposals to tidy the tags in use, written
 * nowhere but this run's report. The owner ticks the ones to apply in Admin
 * → Tags (AdminTagsController::review()); a proposal is never applied by
 * itself (D8). Two sources:
 *
 * - the rule (Support\TagNormalize): near-duplicates and tags made too
 *   specific by a side or a spine level, no assistant;
 * - the assistant's reserved `tag-review` prompt (the profile serving
 *   `reports`, else the first report sub-namespace's that has one), asked about the vocabulary only (Context::vocabulary(): tag
 *   and page count, never a report, a tag that may name a patient withheld)
 *   in alphabetical chunks of CHUNK tags: merges, generalisations, renames
 *   and drops, each checked by Support\TagProposals.
 *
 * Proposals the owner said "never suggest" to (data/tags.yaml `_rejected`)
 * are not made again. A server that cannot be reached stops the assistant's
 * part; the rule's proposals are kept. Each proposal is one report item:
 * outcome `proposal`, its data the proposal and the page count of each tag.
 */
final class TagReviewTask implements MaintenanceTask, ProgressAware
{
    /** Tags per request: a small model keeps a few hundred lines in view */
    public const CHUNK = 200;

    /** Reasons that mean the chunk, not the server: the run carries on */
    private const CHUNK_ONLY = ['identifier_leak', 'provider_error'];

    /** Where the profile serving the reports is found */
    private const PROFILE_PATH = 'reports';

    /** @var ?callable(string, array<string, mixed>): void */
    private $progress = null;

    public function __construct(
        private readonly IndexInterface $index,
        private readonly TagDictionary $dictionary,
        private readonly ?Actions $actions = null,
        private readonly ?Assistant $assistant = null,
        private readonly int $timeout = 120,
    ) {
    }

    public function name(): string
    {
        return 'tags:review';
    }

    public function modes(): array
    {
        return [self::CHECK];
    }

    public function setProgress(?callable $progress): void
    {
        $this->progress = $progress;
    }

    public function options(array $raw): array
    {
        return ['ai' => !\in_array($raw['ai'] ?? true, [false, '0', 'off', 'no', 'false'], true)];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        foreach (['tags', 'proposals_rule', 'proposals_ai', 'withheld', 'failed'] as $key) {
            $report->count($key, 0);
        }
        if (!$this->dictionary->writable()) {
            $report->note(t('admin.maint.task.tags:prune.dictionary_unreadable'));
            $report->fail();

            return $report;
        }
        $counts = [];
        foreach ($this->index->tagCounts() as ['tag' => $tag, 'n' => $n]) {
            $counts[$tag] = $n;
        }
        $report->count('tags', \count($counts));
        $rejected = $this->dictionary->rejected();
        $notRejected = static fn (array $p): bool => !\in_array(TagProposals::key($p), $rejected, true);

        $rule = array_values(array_filter(TagNormalize::proposals($counts), $notRejected));
        $ai = $options['ai'] === true ? $this->ask($counts, $rejected, $actor, $report) : [];

        foreach (TagProposals::combine($rule, $ai) as $proposal) {
            $report->count($proposal['source'] === 'rule' ? 'proposals_rule' : 'proposals_ai');
            $uses = [];
            foreach ($proposal['from'] as $tag) {
                $uses[$tag] = $counts[$tag] ?? 0;
            }
            $report->item(null, null, 'proposal', implode(', ', $proposal['from']) . ' → ' . ($proposal['to'] !== '' ? $proposal['to'] : '∅'), $proposal + ['uses' => $uses]);
        }

        return $report;
    }

    /**
     * The assistant's proposals, chunk by chunk
     *
     * @param array<string, int> $counts
     * @param list<string>       $rejected
     *
     * @return list<array{op: string, from: list<string>, to: string, reason: string, source: string}>
     */
    private function ask(array $counts, array $rejected, string $actor, MaintenanceReport $report): array
    {
        $user = new User($actor, '', true, [], true, '', '');
        $action = $this->action($user);
        if ($action === null || $this->assistant === null) {
            $report->note(t('admin.maint.task.tags:review.no_prompt'));

            return [];
        }
        $send = array_filter($counts, static fn (string $tag): bool => !str_starts_with($tag, Rads::PREFIX), ARRAY_FILTER_USE_KEY);
        uksort($send, static fn (string $a, string $b): int => strcmp(Slug::fold($a), Slug::fold($b)) ?: strcmp($a, $b));
        $chunks = array_chunk($send, self::CHUNK, true);
        $curated = array_map('strval', array_keys($this->dictionary->all()));
        $out = [];
        foreach ($chunks as $i => $chunk) {
            @set_time_limit(Assistant::maxSeconds($this->timeout) + 30);
            $this->tell('start', ['n' => $i + 1, 'total' => \count($chunks), 'label' => 'tags ' . ($i * self::CHUNK + 1) . '–' . ($i * self::CHUNK + \count($chunk))]);
            try {
                $answer = $this->assistant->runVocabulary($action, $chunk, $user);
                foreach ($answer['contextSet'] as $part) {
                    if (preg_match('/^(\d+) tags withheld$/', $part, $m) === 1) {
                        $report->count('withheld', (int) $m[1]);
                    }
                }
                array_push($out, ...TagProposals::parse($answer['result'], $counts, $curated, $rejected));
                $this->tell('done', ['status' => 'ok']);
            } catch (AiException $e) {
                $report->count('failed');
                $report->note(t('admin.maint.task.tags:review.chunk_failed', [$i + 1, $e->reason]));
                $this->tell('done', ['status' => 'failed: ' . $e->reason]);
                if (!\in_array($e->reason, self::CHUNK_ONLY, true)) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * The `tag-review` prompt of the profile serving `reports`, else of the
     * first of its sub-namespaces (`reports:mri`, …) whose profile has one:
     * an instance may map only those (ai.profile_map)
     */
    private function action(User $user): ?Action
    {
        if ($this->actions === null) {
            return null;
        }
        $paths = [self::PROFILE_PATH];
        foreach ($this->index->listSubnamespaces(self::PROFILE_PATH, $user) as $row) {
            $paths[] = self::PROFILE_PATH . ':' . $row['name'];
        }
        foreach ($paths as $path) {
            $action = $this->actions->special($path, 'tag-review');
            if ($action !== null) {
                return $action;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $info */
    private function tell(string $event, array $info): void
    {
        if ($this->progress !== null) {
            ($this->progress)($event, $info);
        }
    }
}
