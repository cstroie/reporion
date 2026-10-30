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
 * until the budget is full.
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

    public function __construct(
        private readonly ?Embedder $embedder = null,
        private readonly ?Sqlite $index = null,
        private readonly float $minScore = Embedder::DEFAULT_MIN_SCORE,
    ) {
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

        $rrf = [];
        foreach ([$keyword, $meaningIds] as $list) {
            foreach ($list as $rank => $id) {
                $rrf[$id] = ($rrf[$id] ?? 0.0) + 1 / (self::RRF + $rank + 1);
            }
        }
        arsort($rrf);
        $viaKeyword = array_flip($keyword);
        $meaningOnly = array_values(array_filter($meaningIds, static fn (string $id): bool => !isset($viaKeyword[$id])));

        $chosen = [];
        $used = 0;
        $per = [];
        $take = function (array $ids, int $max) use (&$chosen, &$used, &$per, $all, $texts, $budget): int {
            $taken = 0;
            foreach ($ids as $id) {
                if ($taken >= $max) {
                    break;
                }
                $p = $all[$id];
                $path = $texts[$p['t']]['path'];
                if (isset($chosen[$id]) || ($per[$path] ?? 0) >= self::PER_SOURCE || $used + mb_strlen($p['text']) > $budget) {
                    continue;
                }
                $per[$path] = ($per[$path] ?? 0) + 1;
                $used += mb_strlen($p['text']);
                $chosen[$id] = true;
                ++$taken;
            }

            return $taken;
        };
        // The best passage by words first, so a small budget is never all meaning; then the places kept for meaning
        $take(\array_slice($keyword, 0, 1), 1);
        $semantic = $take($meaningOnly, self::SEMANTIC_PLACES);
        $take(array_keys($rrf), PHP_INT_MAX);

        $out = [];
        foreach (array_keys($rrf) as $id) {
            if (isset($chosen[$id])) {
                $p = $all[$id];
                $text = $texts[$p['t']];
                $out[] = ['key' => $text['key'], 'path' => $text['path'], 'title' => $text['title'], 'page' => $p['page'], 'text' => $p['text']];
            }
        }

        return ['passages' => $out, 'semantic' => $semantic, 'failed' => $failed];
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
