<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Support\RecallTerms;

/**
 * Ask the archive (D53, after Notion AI's Q&A): the passages of finished
 * reports (signed or archived) that a question is about, for the
 * `ask-archive` prompt to answer from and the screen to cite. Full-text
 * search on the index already here (Index\Sqlite::recallCandidates(), the
 * Recall query with no page and no patient to leave out), under the
 * visibility predicate (invariant 6): a caller is answered only from what
 * they can read. Passages as Recall cuts them (`###` sections' paragraphs,
 * never the `#`/`##` headings that carry the name and the exam). Nothing
 * is written. De-identifying them for the prompt is Ai\Context::archive()'s.
 */
final class ArchiveAsk
{
    /** Passages the answer is drawn from, and cited */
    public const SOURCES = 8;

    /** Passages from one report at most: eight sources, at least four reports */
    private const PER_REPORT = 2;

    /** Reports the index is asked for */
    private const CANDIDATES = 40;

    /** A passage shorter than this says nothing worth citing */
    private const MIN_CHARS = 15;

    /** The longest question */
    public const MAX_QUESTION = 1_000;

    /** A question's own words, which no report passage is about */
    private const QUESTION_STOP = [
        'what', 'which', 'when', 'where', 'does', 'have', 'with', 'from', 'that', 'this', 'there', 'about', 'usually', 'typically', 'describe', 'described', 'write', 'written',
        'cand', 'unde', 'cati', 'avem', 'exista', 'descrie', 'descris', 'descrisa', 'descriem', 'scriem', 'scris', 'scrisa', 'raport', 'rapoarte', 'rapoartele', 'pacient', 'pacienti', 'pacientii', 'pacientul', 'report', 'reports', 'patient', 'patients',
    ];

    /** Reciprocal-rank-fusion constant: how fast a lower rank counts for less */
    private const RRF = 60;

    /** Reports the semantic side is asked for */
    private const SEMANTIC = 20;

    /** Places the semantic side keeps when the keyword side would fill them all */
    private const SEMANTIC_PLACES = 2;

    /** Words one search holds at most (the question's and the expansion's) */
    private const MAX_TERMS = 24;

    /**
     * @param ?array{model: string, min: float, common: int} $vectors the embedding model of Admin → AI and how near
     *                                                                is near (Embedder::minScore(), commonMin()); null: keywords only
     */
    public function __construct(
        private readonly Sqlite $index,
        private readonly ?array $vectors = null,
    ) {
    }

    /** Whether reports can be found by meaning: an embedding model is set up */
    public function semantic(): bool
    {
        return $this->vectors !== null;
    }

    /**
     * The question's terms: its meaningful words (RecallTerms), not the question words
     *
     * @return list<string>
     */
    public static function terms(string $question): array
    {
        return array_values(array_filter(RecallTerms::ofLine($question, 12), static fn (string $t): bool => !\in_array($t, self::QUESTION_STOP, true)));
    }

    /**
     * The passages a question is about, best first, numbered from 1. Two
     * ways to find the reports, fused by rank (reciprocal rank fusion): their
     * words, and the words the `ask-expand` prompt adds (any of them, the
     * most matched first: the full-text index, D28 synonyms and all), and
     * their meaning ($vector: the question's, from the embedding model:
     * reports whose exam title and conclusion lie near it). Passages are
     * cut from the report as ever; a report found by meaning alone, whose
     * words the question does not use, gives its conclusion. A few places are kept
     * for what only meaning finds, so exact words do not crowd it out.
     *
     * @param list<string>      $extraTerms folded words the expansion adds
     * @param ?list<float>      $vector     unit length, the same model as the reports' vectors
     *
     * @return list<array{n: int, pid: string, path: string, rev: int, heading: string, text: string, study_date: ?string, modality: ?string, mine: bool}>
     */
    public function sources(string $question, User $principal, array $extraTerms = [], ?array $vector = null): array
    {
        $terms = \array_slice(array_values(array_unique([...self::terms($question), ...$extraTerms])), 0, self::MAX_TERMS);
        $keyword = $terms === [] ? [] : $this->index->recallCandidates($terms, $principal->username, '', '', null, null, $principal, false, self::CANDIDATES);
        $meaning = $vector !== null && $this->vectors !== null
            ? $this->index->vectorCandidates($vector, $this->vectors['model'], $principal->username, $principal, self::SEMANTIC, $this->vectors['min'], $this->vectors['common'])
            : [];
        $rrf = [];
        $candidates = [];
        foreach ([$keyword, $meaning] as $list) {
            foreach ($list as $rank => $candidate) {
                $rrf[$candidate['pid']] = ($rrf[$candidate['pid']] ?? 0.0) + 1 / (self::RRF + $rank + 1);
                $candidates[$candidate['pid']] ??= $candidate;
            }
        }
        if ($candidates === []) {
            return [];
        }
        $viaKeyword = array_flip(array_column($keyword, 'pid'));
        $scored = [];
        $order = 0;
        foreach ($candidates as $pid => $candidate) {
            ++$order;
            foreach (RecallTerms::passages($candidate['body']) as $passage) {
                if (mb_strlen($passage['text']) >= self::MIN_CHARS) {
                    $scored[] = ['matched' => \count(RecallTerms::matched($terms, $passage['text'])), 'rrf' => $rrf[$pid], 'order' => $order] + $passage + $candidate;
                }
            }
        }
        // Two terms in one passage when the question has them; one when it does not: a question in other
        // words than the report's ("usual description of X") has a single term a report would use
        $need = max(1, min(2, max(array_column($scored, 'matched') ?: [0])));
        $keywordPassages = array_values(array_filter($scored, static fn (array $p): bool => $p['matched'] >= $need));
        usort($keywordPassages, static fn (array $a, array $b): int => [$b['matched'], $b['rrf'], $b['mine'], $a['order']] <=> [$a['matched'], $a['rrf'], $a['mine'], $b['order']]);
        // What only meaning found: its conclusion, for a report none of whose passages holds the words
        $reported = array_flip(array_column($keywordPassages, 'pid'));
        $meaningPassages = [];
        foreach ($scored as $passage) {
            if (!isset($reported[$passage['pid']]) && !isset($viaKeyword[$passage['pid']]) && str_starts_with(RecallTerms::fold($passage['heading']), 'concluz')) {
                $meaningPassages[] = $passage;
            }
        }
        usort($meaningPassages, static fn (array $a, array $b): int => [$b['rrf'], $b['mine'], $a['order']] <=> [$a['rrf'], $a['mine'], $b['order']]);

        $sources = [];
        $perReport = [];
        $seen = [];
        $take = function (array $list, int $until) use (&$sources, &$perReport, &$seen): void {
            foreach ($list as $passage) {
                if (\count($sources) >= $until) {
                    return;
                }
                $key = RecallTerms::fold($passage['text']);
                if (isset($seen[$key]) || ($perReport[$passage['pid']] ?? 0) >= self::PER_REPORT) {
                    continue;
                }
                $seen[$key] = true;
                $perReport[$passage['pid']] = ($perReport[$passage['pid']] ?? 0) + 1;
                $sources[] = [
                    'n' => \count($sources) + 1,
                    'pid' => $passage['pid'],
                    'path' => $passage['path'],
                    'rev' => $passage['rev'],
                    'heading' => $passage['heading'],
                    'text' => $passage['text'],
                    'study_date' => $passage['study_date'],
                    'modality' => $passage['modality'],
                    'mine' => $passage['mine'],
                ];
            }
        };
        $reserved = min(self::SEMANTIC_PLACES, \count($meaningPassages));
        $take($keywordPassages, self::SOURCES - $reserved);
        $take($meaningPassages, self::SOURCES);
        $take($keywordPassages, self::SOURCES);

        return $sources;
    }
}
