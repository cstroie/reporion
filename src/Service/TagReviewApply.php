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
 *
 * @phpstan-import-type Proposal from \Reporion\Support\TagNormalize
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
     * A finished run's proposals by their index in the run: the one place that
     * reads them out of the report's items
     *
     * @return array<int, Proposal>
     */
    public static function proposals(MaintenanceReport $report): array
    {
        $out = [];
        foreach ($report->items() as $i => $item) {
            $p = $item['data'];
            if ($item['outcome'] !== 'proposal' || !\in_array($p['op'] ?? '', TagProposals::OPS, true) || !\is_array($p['from'] ?? null)) {
                continue;
            }
            $out[$i] = [
                'op' => (string) $p['op'],
                'from' => array_values(array_map('strval', $p['from'])),
                'to' => (string) ($p['to'] ?? ''),
                'reason' => (string) ($p['reason'] ?? ''),
                'source' => (string) ($p['source'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{proposal: Proposal, to: ?string}> $picks   the proposals to apply, each with the target the owner typed in its row (null: the proposed one)
     * @param list<Proposal>                               $rejects the proposals never to suggest again
     *
     * @return array{applied: int, rejected: int, changed: int, skippedSigned: int, conflicts: int}
     *
     * @throws MaintenanceBusyException when a writing run holds the lock
     * @throws InvalidArgumentException for a malformed target name
     */
    public function apply(array $picks, array $rejects, string $username, ?Request $request = null): array
    {
        $inUse = [];
        foreach ($this->index->tagCounts() as $row) {
            $inUse[$row['tag']] = true;
        }

        $done = $this->maintenance->withWriteLock(function () use ($picks, $rejects, $inUse, $username, $request): array {
            $total = ['changed' => 0, 'skippedSigned' => 0, 'conflicts' => 0];
            $applied = 0;
            foreach ($picks as ['proposal' => $proposal, 'to' => $typed]) {
                $from = array_values(array_filter($proposal['from'], static fn (string $t): bool => isset($inUse[$t])));
                if ($from === []) {
                    continue;
                }
                if ($proposal['op'] === 'drop') {
                    $result = $this->tags->removeMany($from, SystemActor::ADMIN, $request, $username, 'tags-review');
                } else {
                    $to = TagProposals::tag($typed ?? $proposal['to']);
                    if ($to === '') {
                        continue;
                    }
                    // A merge turned the other way round (the target one of its own tags): the proposed target is merged in
                    // too. Any other new target takes the proposal's tags only
                    if ($proposal['to'] !== $to && \in_array($to, $from, true) && isset($inUse[$proposal['to']])) {
                        $from[] = $proposal['to'];
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
            foreach ($rejects as $proposal) {
                $this->dictionary->reject(TagProposals::key($proposal));
            }

            return ['applied' => $applied, 'rejected' => \count($rejects)] + $total;
        });
        $this->audit->record('tags.review', $username, $request, extra: ['applied' => $done['applied'], 'rejected' => $done['rejected'], 'pages' => $done['changed']]);

        return $done;
    }
}
