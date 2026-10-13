<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Auth\SystemActor;
use Reporion\Index\IndexInterface;
use Reporion\Service\TagDictionary;
use Reporion\Service\Tags;
use Reporion\Support\Rads;

/**
 * tags:prune: takes a tag off the reports when it is carried by no more
 * than max_uses reports (1 to 5, default 1: the tags that occur once, mostly
 * a stray word from the assistant). Each page changes by an ordinary new
 * revision by system:admin (D41), audited with on_behalf_of; a signed
 * report keeps its tag (D3) and is counted. Never touched: the RADS
 * categories (`rads:…`, a rare category is still true) and the tags of
 * Admin → Tags (the dictionary is curated, and is not edited here). A
 * dictionary that does not parse is read as empty, so then nothing is
 * pruned.
 */
final class TagPruneTask implements MaintenanceTask
{
    public const MAX = 5;

    public function __construct(
        private readonly IndexInterface $index,
        private readonly Tags $tags,
        private readonly TagDictionary $dictionary,
    ) {
    }

    public function name(): string
    {
        return 'tags:prune';
    }

    public function modes(): array
    {
        return [self::CHECK, self::APPLY];
    }

    public function options(array $raw): array
    {
        $max = $raw['max_uses'] ?? 1;

        return ['max_uses' => is_numeric($max) ? max(1, min(self::MAX, (int) $max)) : 1];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $max = (int) $options['max_uses'];
        $apply = $mode === self::APPLY;
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        $report->count($apply ? 'tags_removed' : 'tags_to_remove', 0);
        $report->count('signed_pages_kept', 0);
        $report->count('pages_changed', 0);

        if (!$this->dictionary->writable()) {
            $report->note(t('admin.maint.task.tags:prune.dictionary_unreadable'));
            $report->fail();

            return $report;
        }
        $curated = $this->dictionary->all();

        $gone = [];
        foreach ($this->index->tagCounts() as ['tag' => $tag, 'n' => $n]) {
            if ($n > $max || str_starts_with($tag, Rads::PREFIX) || isset($curated[$tag])) {
                continue;
            }
            $gone[] = $tag;
            $report->count($apply ? 'tags_removed' : 'tags_to_remove');
            $report->item(null, null, $apply ? 'removed' : 'would_remove', $tag . ' · ' . t('admin.maint.task.tags:prune.uses', [$n]), ['tag' => $tag, 'uses' => $n]);
        }
        if ($apply && $gone !== []) {
            // One revision per page, whatever number of its tags go (a page with five rare tags is not five revisions)
            $result = $this->tags->removeMany($gone, SystemActor::ADMIN, onBehalfOf: $actor);
            $report->count('pages_changed', $result['changed']);
            $report->count('signed_pages_kept', $result['skippedSigned']);
            if ($result['conflicts'] > 0) {
                $report->count('conflicts', $result['conflicts']);
            }
        }

        return $report;
    }
}
