<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\SystemActor;
use Reporion\Exception\MaintenanceBusyException;
use Reporion\Http\Request;
use Reporion\Index\IndexInterface;
use Reporion\Service\Maintenance\MaintenanceReport;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Support\TagProposals;

/**
 * Applies the proposals of a finished tags:review run that the owner ticked
 * (Admin → Tags, 2026-10-15), one by one as system:admin on the owner's behalf
 * (D41): a merge or rename keeps the old names as synonyms, a generalisation
 * does not, a drop takes the tag off; signed reports are left alone. The ones
 * turned down for good go to the dictionary's `_rejected`. Runs under the
 * maintenance lock, so no run writes the same pages meanwhile.
 */
final class TagReviewApply
{
    public function __construct(
        private readonly IndexInterface $index,
        private readonly Tags $tags,
        private readonly TagDictionary $dictionary,
        private readonly AuditLog $audit,
        private readonly MaintenanceRunner $maintenance,
    ) {
    }

    /**
     * @param list<int>        $picks   indexes of the proposals to apply
     * @param array<int, mixed> $targets the owner's own target per proposal index, when changed in the row
     * @param list<int>        $rejects indexes of the proposals never to suggest again
     *
     * @return array{applied: int, rejected: int, changed: int, skippedSigned: int, conflicts: int}
     *
     * @throws MaintenanceBusyException when a writing run holds the lock
     * @throws InvalidArgumentException for a malformed target name
     */
    public function apply(MaintenanceReport $report, array $picks, array $targets, array $rejects, string $username, ?Request $request = null): array
    {
        $items = $report->items();
        $inUse = [];
        foreach ($this->index->tagCounts() as $row) {
            $inUse[$row['tag']] = true;
        }

        $lock = $this->maintenance->lockWrites();
        $total = ['changed' => 0, 'skippedSigned' => 0, 'conflicts' => 0];
        $applied = 0;
        $rejected = 0;
        try {
            foreach ($picks as $i) {
                $proposal = $items[$i]['data'] ?? null;
                if (!\is_array($proposal) || !\in_array($proposal['op'] ?? '', TagProposals::OPS, true)) {
                    continue;
                }
                $from = array_values(array_filter(array_map('strval', (array) ($proposal['from'] ?? [])), static fn (string $t): bool => isset($inUse[$t])));
                if ($from === []) {
                    continue;
                }
                if ($proposal['op'] === 'drop') {
                    $result = $this->tags->removeMany($from, SystemActor::ADMIN, $request, $username, 'tags-review');
                } else {
                    // The owner may have changed the target in the row
                    $to = TagProposals::tag(\is_string($targets[$i] ?? null) ? $targets[$i] : (string) ($proposal['to'] ?? ''));
                    if ($to === '') {
                        continue;
                    }
                    // A merge turned the other way round (the target one of its own tags): the proposed target is merged in
                    // too. Any other new target takes the proposal's tags only
                    $proposed = (string) ($proposal['to'] ?? '');
                    if ($proposed !== $to && \in_array($to, $from, true) && isset($inUse[$proposed])) {
                        $from[] = $proposed;
                    }
                    $from = array_values(array_diff($from, [$to]));
                    if ($from === []) {
                        continue;
                    }
                    $result = $this->tags->merge($from, $to, SystemActor::ADMIN, $request, $username, 'tags-review');
                    $this->dictionary->merge($from, $to, keepNames: $proposal['op'] !== 'generalise');
                }
                foreach ($total as $key => $n) {
                    $total[$key] = $n + $result[$key];
                }
                ++$applied;
            }
            foreach ($rejects as $i) {
                $proposal = $items[$i]['data'] ?? null;
                if (\is_array($proposal) && \is_array($proposal['from'] ?? null)) {
                    $this->dictionary->reject(TagProposals::key(['op' => (string) ($proposal['op'] ?? ''), 'from' => array_map('strval', $proposal['from']), 'to' => (string) ($proposal['to'] ?? '')]));
                    ++$rejected;
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->audit->record('tags.review', $username, $request, extra: ['applied' => $applied, 'rejected' => $rejected, 'pages' => $total['changed']]);

        return ['applied' => $applied, 'rejected' => $rejected] + $total;
    }
}
