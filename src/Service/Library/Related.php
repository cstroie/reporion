<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Support\Citation;
use Reporion\Support\LibrarySource;

/**
 * Sources related to a source (docs/library.md, D60; Zotero's "Related", Mendeley's suggestions),
 * from what the library already knows, without asking anyone:
 *
 * - **cited with it**: the pages that cite it also cite these (co-citation, from the index's links);
 * - **near in meaning**: its passages' vectors averaged, the other sources' passages nearest to that
 *   (`passage_vectors`, only when `index:vectors` has run for the source);
 * - **tags**: the tags they share.
 *
 * The three rankings are fused by rank (reciprocal rank fusion, k = 60, as Ask the archive). Every
 * source is read under the caller's visibility (invariant 6), the citing pages too; nothing is
 * written, nothing is sent anywhere.
 */
final class Related
{
    public const LIMIT = 8;

    private const RRF = 60;

    /** Passages asked of the vectors before they are folded into sources */
    private const PASSAGES = 120;

    public function __construct(private readonly Sqlite $index, private readonly string $namespace = 'library', private readonly float $minScore = 0.0)
    {
    }

    /**
     * @return list<array{pid: string, path: string, key: string, title: string, authors: string, year: ?int, why: list<string>}> best first
     */
    public function of(string $pid, User $principal): array
    {
        $rank = [];
        $why = [];
        $add = static function (string $kind, array $pids) use (&$rank, &$why): void {
            foreach (array_values($pids) as $i => $other) {
                $rank[$other] = ($rank[$other] ?? 0.0) + 1 / (self::RRF + $i + 1);
                $why[$other][] = $kind;
            }
        };

        $add('cited', array_column($this->index->coCited($pid, $this->namespace, $principal, 30), 'pid'));
        $add('meaning', $this->byMeaning($pid, $principal));

        // The sources and the tags they share with this one
        $listing = [];
        $mine = [];
        foreach ($this->index->listNamespaceMeta($this->namespace, $principal) as $row) {
            if (!LibrarySource::isSource($row['meta']) || str_contains(substr($row['path'], \strlen($this->namespace) + 1), ':')) {
                continue;
            }
            $listing[$row['pid']] = $row;
            if ($row['pid'] === $pid) {
                $mine = array_values(array_filter(\is_array($row['meta']['tags'] ?? null) ? $row['meta']['tags'] : [], 'is_string'));
            }
        }
        if ($mine !== []) {
            $shared = [];
            foreach ($listing as $other => $row) {
                $theirs = array_values(array_filter(\is_array($row['meta']['tags'] ?? null) ? $row['meta']['tags'] : [], 'is_string'));
                $common = array_intersect($mine, $theirs);
                if ($other !== $pid && $common !== []) {
                    // Jaccard: a source with the same few tags beats one that has a hundred
                    $shared[$other] = \count($common) / \count(array_unique([...$mine, ...$theirs]));
                }
            }
            arsort($shared);
            $add('tags', array_keys(\array_slice($shared, 0, 30, true)));
        }

        arsort($rank);
        $out = [];
        foreach (array_keys($rank) as $other) {
            if (!isset($listing[$other]) || $other === $pid) {
                continue;
            }
            $row = $listing[$other];
            $csl = \is_array($row['meta']['csl'] ?? null) ? $row['meta']['csl'] : [];
            $out[] = [
                'pid' => $other,
                'path' => $row['path'],
                'key' => substr($row['path'], \strlen($this->namespace) + 1),
                'title' => $row['title'] !== '' ? $row['title'] : (string) ($csl['title'] ?? ''),
                'authors' => Citation::authorsShort($csl),
                'year' => Citation::year($csl),
                'why' => array_values(array_unique($why[$other])),
            ];
            if (\count($out) >= self::LIMIT) {
                break;
            }
        }

        return $out;
    }

    /**
     * The sources whose passages are nearest the average of this one's, best first (sources, not passages)
     *
     * @return list<string>
     */
    private function byMeaning(string $pid, User $principal): array
    {
        $own = $this->index->passageVectors($pid);
        if ($own === null) {
            return [];
        }
        $dim = \count($own['vectors'][0]);
        $centroid = array_fill(0, $dim, 0.0);
        foreach ($own['vectors'] as $vector) {
            if (\count($vector) !== $dim) {
                return [];
            }
            foreach ($vector as $i => $v) {
                $centroid[$i] += $v;
            }
        }
        $length = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $centroid)));
        if ($length <= 0.0) {
            return [];
        }
        $centroid = array_map(static fn (float $v): float => $v / $length, $centroid);
        $best = [];
        foreach ($this->index->passageCandidates($centroid, $own['model'], null, $principal, self::PASSAGES, $this->minScore) as $hit) {
            if ($hit['pid'] !== $pid) {
                $best[$hit['pid']] = max($best[$hit['pid']] ?? 0.0, $hit['score']);
            }
        }
        arsort($best);

        return array_keys(\array_slice($best, 0, 30, true));
    }
}
