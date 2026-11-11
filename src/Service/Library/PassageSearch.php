<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Index\Sqlite;
use Reporion\Service\Ai\Embedder;
use Reporion\Support\RecallTerms;
use Reporion\Support\SourcePassages;

/**
 * The passages of a notebook and its sources that a question (or a page to check) is about, when
 * they do not all fit one request (docs/library.md, phases 5 and 7). Two rankings, fused by rank
 * (reciprocal rank fusion, k = 60, as Ask the archive, D53): the passages holding the most of the
 * words, and, when an embedding model is set and `index:vectors` has run, the library sources'
 * passages nearest in meaning (passage_vectors, under the caller's visibility). A Romanian question
 * finds an English article's passage by meaning when it shares no word with it, so a few places
 * are kept for what only meaning finds. A vector is used only for the text it was made from (its
 * sha against the passage cut now, Support\SourcePassages). At most PER_SOURCE passages a text,
 * more for a notebook of few sources (find()), until the budget is full.
 */
final class PassageSearch
{
    /** The most passages a text gives */
    public const PER_SOURCE = 4;

    /** Reciprocal rank fusion's constant */
    private const RRF = 60;

    /** Places kept for passages only meaning finds */
    public const SEMANTIC_PLACES = 2;

    /** Passages asked of the vectors */
    private const SEMANTIC = 40;
    /** Passages asked of passage_fts, then ranked again by how many search words each holds */
    private const WORDS = 300;
    /** The most passages one answer is given (a notebook citing many sources, the whole library) */
    public const MAX_PASSAGES = 24;

    public function __construct(
        private readonly ?Embedder $embedder = null,
        private readonly ?Sqlite $index = null,
        private readonly float $minScore = Embedder::DEFAULT_MIN_SCORE,
    ) {
    }

    /**
     * How many characters each source's passages hold (passage_fts), without reading a source;
     * empty with no index
     *
     * @param list<string> $pids
     *
     * @return array<string, int>
     */
    public function lengths(array $pids): array
    {
        return $this->index?->passageLengths($pids) ?? [];
    }

    /** Whether passages can be found by meaning: an embedding model is set */
    public function semantic(): bool
    {
        return $this->embedder !== null && $this->index !== null;
    }

    /**
     * @param list<array{key: ?string, path: string, title: string, text: string, record?: \Reporion\Storage\PageRecord}> $texts
     * @param list<string> $terms   folded
     * @param string       $meaning what to find by meaning, already safe to send (de-identified); '' for words only
     *
     * @return array{passages: list<array{key: ?string, path: string, title: string, page: ?int, text: string}>, semantic: int, failed: bool}
     *         `semantic`: passages chosen by meaning alone; `failed`: the embedding server did not answer (words only then)
     */
    public function choose(array $texts, array $terms, int $budget, string $meaning, ?User $principal): array
    {
        $all = [];
        $keyword = [];
        $byPid = [];
        foreach ($texts as $t => $text) {
            if (isset($text['record'])) {
                $byPid[$text['record']->pid] = $t;
            }
            foreach (SourcePassages::cut($text['text']) as $p) {
                $id = $t . ':' . $p['n'];
                $all[$id] = ['t' => $t, 'n' => $p['n'], 'page' => $p['page'], 'text' => $p['text']];
                $score = \count(RecallTerms::matched($terms, RecallTerms::fold($p['text'])));
                if ($score > 0) {
                    $keyword[] = ['id' => $id, 'score' => $score, 't' => $t];
                }
            }
        }
        usort($keyword, static fn (array $a, array $b): int => [$b['score'], $a['t']] <=> [$a['score'], $b['t']]);
        $keyword = array_column($keyword, 'id');

        [$meaningIds, $failed] = $this->byMeaning($meaning, $byPid, $all, $principal);

        return $this->pack($keyword, $meaningIds, array_map(static fn (array $p): array => ['path' => $texts[$p['t']]['path'], 'key' => $texts[$p['t']]['key'], 'title' => $texts[$p['t']]['title'], 'page' => $p['page'], 'text' => $p['text']], $all), $budget, PHP_INT_MAX) + ['failed' => $failed];
    }

    /**
     * The passages a question is about, read from the index (2026-11-02): a notebook's own texts
     * ($own, cut and scored here, as choose()) and the library sources' passages, by words from
     * passage_fts and by meaning from passage_vectors, only of $pids (a notebook's sources) or of
     * every source the caller can see (null: Ask the library). No source is read whole. At most
     * $max passages within $budget characters, PER_SOURCE a source, or an even share of $max when
     * a notebook cites few sources (two sources: twelve each), so a long guideline is not cut to
     * four passages while the budget stays empty (2026-11-11).
     *
     * The passages found by words are ranked by how many of the search words each holds, the
     * question's own ($primary) counting twice, full-text order breaking ties: bm25 over a wide OR
     * puts first the passages that repeat the commonest word (`head` in a guideline on head CT),
     * not the one that answers (`Age groups for head exams: …`).
     *
     * @param list<array{key: ?string, path: string, title: string, text: string}> $own
     * @param ?list<string> $pids
     * @param list<string>  $terms   folded
     * @param string        $meaning already safe to send; '' for words only
     * @param list<string>  $primary folded, the terms of $terms the question itself has
     *
     * @return array{passages: list<array{key: ?string, path: string, title: string, page: ?int, text: string}>, semantic: int, failed: bool}
     */
    public function find(array $own, ?array $pids, array $terms, int $budget, string $meaning, ?User $principal, int $max = self::MAX_PASSAGES, array $primary = []): array
    {
        $weight = array_fill_keys($primary, 2);
        $score = static function (string $text) use ($terms, $weight): int {
            $sum = 0;
            foreach (RecallTerms::matched($terms, RecallTerms::fold($text)) as $term) {
                $sum += $weight[$term] ?? 1;
            }

            return $sum;
        };
        $all = [];
        $ownIds = [];
        foreach ($own as $t => $text) {
            $scored = [];
            foreach (SourcePassages::cut($text['text']) as $p) {
                $id = 'own' . $t . ':' . $p['n'];
                $all[$id] = ['path' => $text['path'], 'key' => $text['key'], 'title' => $text['title'], 'page' => $p['page'], 'text' => $p['text']];
                $points = $score($p['text']);
                if ($points > 0) {
                    $scored[] = ['id' => $id, 'score' => $points];
                }
            }
            usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $ownIds = [...$ownIds, ...array_column($scored, 'id')];
        }
        $scored = [];
        foreach ($this->index?->passagesAbout($terms, $pids, $principal, self::WORDS) ?? [] as $rank => $p) {
            $id = $p['pid'] . ':' . $p['n'];
            $all[$id] = ['path' => $p['path'], 'key' => null, 'title' => $p['title'], 'page' => $p['page'], 'text' => $p['text']];
            $scored[] = ['id' => $id, 'score' => $score($p['text']), 'rank' => $rank];
        }
        usort($scored, static fn (array $a, array $b): int => [$b['score'], $a['rank']] <=> [$a['score'], $b['rank']]);
        $sourceIds = array_column($scored, 'id');
        // The notebook's own passages and the sources' by turns: neither crowds the other out
        $keyword = [];
        for ($i = 0, $n = max(\count($ownIds), \count($sourceIds)); $i < $n; ++$i) {
            array_push($keyword, ...\array_slice($ownIds, $i, 1), ...\array_slice($sourceIds, $i, 1));
        }

        $meaningIds = [];
        $failed = false;
        if ($this->embedder !== null && $this->index !== null && trim($meaning) !== '' && $this->index->hasPassages($this->embedder->model, $pids)) {
            try {
                $vector = $this->embedder->embed([$meaning])[0] ?? [];
                $hits = $this->index->passageCandidates($vector, $this->embedder->model, $pids, $principal, self::SEMANTIC, $this->minScore);
                $missing = array_values(array_filter($hits, static fn (array $h): bool => !isset($all[$h['pid'] . ':' . $h['n']])));
                foreach ($this->index->passagesAt(array_map(static fn (array $h): array => ['pid' => $h['pid'], 'n' => $h['n']], $missing), $principal) as $id => $p) {
                    $all[$id] = ['path' => $p['path'], 'key' => null, 'title' => $p['title'], 'page' => $p['page'], 'text' => $p['text']];
                }
                foreach ($hits as $hit) {
                    $id = $hit['pid'] . ':' . $hit['n'];
                    // A vector only for the text it was made from
                    if (isset($all[$id]) && Embedder::sha($this->embedder->model, $all[$id]['text']) === $hit['sha']) {
                        $meaningIds[] = $id;
                    }
                }
            } catch (AiException) {
                $failed = true;
            }
        }

        $texts = \count($own) + \count($pids ?? []);
        $perSource = $pids !== null && $texts > 0 ? max(self::PER_SOURCE, intdiv($max, $texts)) : self::PER_SOURCE;

        return $this->pack($keyword, $meaningIds, $all, $budget, $max, $perSource) + ['failed' => $failed];
    }

    /**
     * The two rankings fused by rank and packed: the best passage by words first (so a small
     * budget is never all meaning), then the places kept for what only meaning finds, then the
     * rest, $perSource a source (by path), within $budget characters and $max passages; in fused order
     *
     * @param list<string> $keyword
     * @param list<string> $meaning
     * @param array<string, array{path: string, key: ?string, title: string, page: ?int, text: string}> $all
     *
     * @return array{passages: list<array{key: ?string, path: string, title: string, page: ?int, text: string}>, semantic: int}
     */
    private function pack(array $keyword, array $meaning, array $all, int $budget, int $max, int $perSource = self::PER_SOURCE): array
    {
        $rrf = [];
        foreach ([$keyword, $meaning] as $list) {
            foreach ($list as $rank => $id) {
                $rrf[$id] = ($rrf[$id] ?? 0.0) + 1 / (self::RRF + $rank + 1);
            }
        }
        arsort($rrf);
        $viaKeyword = array_flip($keyword);
        $meaningOnly = array_values(array_filter($meaning, static fn (string $id): bool => !isset($viaKeyword[$id])));

        $chosen = [];
        $used = 0;
        $per = [];
        $take = function (array $ids, int $limit) use (&$chosen, &$used, &$per, $all, $budget, $max, $perSource): int {
            $taken = 0;
            foreach ($ids as $id) {
                if ($taken >= $limit || \count($chosen) >= $max) {
                    break;
                }
                $p = $all[$id];
                if (isset($chosen[$id]) || ($per[$p['path']] ?? 0) >= $perSource || $used + mb_strlen($p['text']) > $budget) {
                    continue;
                }
                $per[$p['path']] = ($per[$p['path']] ?? 0) + 1;
                $used += mb_strlen($p['text']);
                $chosen[$id] = true;
                ++$taken;
            }

            return $taken;
        };
        $take(\array_slice($keyword, 0, 1), 1);
        $semantic = $take($meaningOnly, self::SEMANTIC_PLACES);
        $take(array_map('strval', array_keys($rrf)), PHP_INT_MAX);

        $out = [];
        foreach (array_keys($rrf) as $id) {
            if (isset($chosen[$id])) {
                $out[] = $all[$id];
            }
        }

        return ['passages' => $out, 'semantic' => $semantic];
    }

    /**
     * The passages nearest to $meaning among the sources', best first; a vector only for the text
     * it was made from
     *
     * @param array<string, int>                                                  $byPid pid => its text's place
     * @param array<string, array{t: int, n: int, page: ?int, text: string}>     $all
     *
     * @return array{0: list<string>, 1: bool} the passage ids, whether the embedding server failed
     */
    private function byMeaning(string $meaning, array $byPid, array $all, ?User $principal): array
    {
        if ($this->embedder === null || $this->index === null || trim($meaning) === '' || $byPid === []
            || !$this->index->hasPassages($this->embedder->model, array_map('strval', array_keys($byPid)))) {
            return [[], false];
        }
        try {
            $vector = $this->embedder->embed([$meaning])[0] ?? [];
        } catch (AiException) {
            return [[], true];
        }
        $ids = [];
        foreach ($this->index->passageCandidates($vector, $this->embedder->model, array_map('strval', array_keys($byPid)), $principal, self::SEMANTIC, $this->minScore) as $hit) {
            $id = $byPid[$hit['pid']] . ':' . $hit['n'];
            if (isset($all[$id]) && Embedder::sha($this->embedder->model, $all[$id]['text']) === $hit['sha']) {
                $ids[] = $id;
            }
        }

        return [$ids, false];
    }
}
