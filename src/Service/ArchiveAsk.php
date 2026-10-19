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

    public function __construct(
        private readonly Sqlite $index,
    ) {
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
     * The passages a question is about, best first: most terms matched,
     * then the caller's own reports, then the index's rank; numbered from 1
     *
     * @return list<array{n: int, pid: string, path: string, rev: int, heading: string, text: string, study_date: ?string, modality: ?string, mine: bool}>
     */
    public function sources(string $question, User $principal): array
    {
        $terms = self::terms($question);
        if ($terms === []) {
            return [];
        }
        $candidates = $this->index->recallCandidates($terms, $principal->username, '', '', null, null, $principal, false, self::CANDIDATES);
        $need = min(2, \count($terms));
        $scored = [];
        foreach ($candidates as $order => $candidate) {
            foreach (RecallTerms::passages($candidate['body']) as $passage) {
                if (mb_strlen($passage['text']) < self::MIN_CHARS) {
                    continue;
                }
                $matched = \count(RecallTerms::matched($terms, $passage['text']));
                if ($matched >= $need) {
                    $scored[] = ['matched' => $matched, 'order' => $order] + $passage + $candidate;
                }
            }
        }
        usort($scored, static fn (array $a, array $b): int => [$b['matched'], $b['mine'], $a['order']] <=> [$a['matched'], $a['mine'], $b['order']]);

        $sources = [];
        $perReport = [];
        $seen = [];
        foreach ($scored as $passage) {
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
            if (\count($sources) === self::SOURCES) {
                break;
            }
        }

        return $sources;
    }
}
