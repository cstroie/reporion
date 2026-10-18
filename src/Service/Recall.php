<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Service\Ai\Examples;
use Reporion\Service\Ai\Redactor;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\MetaText;
use Reporion\Support\PatientKey;
use Reporion\Support\RecallTerms;
use RuntimeException;

/**
 * Recall while writing (D48): for the line the doctor has just written,
 * the passages of finished reports that say the same thing at length: the
 * doctor's own first, then the others' they can read (invariant 6), never
 * this patient's; and what several of those passages say that the text
 * in front does not yet (`efect de masă`, `FLAIR`): a reminder, never a
 * change to the text. Full-text search on the index already here, no AI.
 * As Examples, the same passages de-identified are the assistant's
 * {recall} (the `expand` and `descriptors` prompts).
 */
final class Recall implements Examples
{
    /** Passages shown */
    public const PASSAGES = 5;

    /** Passages from one report at most */
    private const PER_REPORT = 2;

    /** Passages the missing aspects are read from */
    private const EVIDENCE = 8;

    /** A passage shorter than this says nothing worth recalling */
    private const MIN_CHARS = 15;

    /** A one-word line recalls only when the word is at least this long (`meningiom`) */
    private const ONE_WORD_MIN = 6;

    public function __construct(
        private readonly Sqlite $index,
        private readonly ?StorageInterface $storage = null,
    ) {
    }

    /**
     * @param string $line    the line just written
     * @param string $written the text in front (the exam pane, else the body): what is already said
     *
     * @return array{passages: list<array{pid: string, rev: int, heading: string, text: string, html: string, study_date: ?string, modality: ?string, mine: bool, href: string}>, missing: list<string>, terms: list<string>}
     */
    public function find(PageRecord $page, string $line, string $written, User $principal): array
    {
        $terms = RecallTerms::ofLine($line);
        $ranked = $this->ranked($page, $terms, $principal);
        $shown = array_map(static fn (array $p): array => [
            'pid' => $p['pid'],
            'rev' => $p['rev'],
            'heading' => $p['heading'],
            'text' => $p['text'],
            'html' => RecallTerms::highlight($p['text'], $terms),
            'study_date' => $p['study_date'],
            'modality' => $p['modality'],
            'mine' => $p['mine'],
            'href' => '/r/' . rawurlencode($p['pid']) . '/' . $p['rev'],
        ], \array_slice($ranked, 0, self::PASSAGES));

        $evidence = array_column(\array_slice($ranked, 0, self::EVIDENCE), 'text');

        return [
            'passages' => $shown,
            'missing' => RecallTerms::missing($evidence, $written . "\n" . $line, $terms),
            'terms' => $terms,
        ];
    }

    /**
     * {recall}: the passages for $text (one line), each de-identified by
     * its own report's frontmatter
     */
    public function for(PageRecord $page, string $text, ?User $principal, Redactor $redactor): array
    {
        if ($principal === null || $this->storage === null) {
            return [];
        }
        $out = [];
        $learned = [];
        foreach (\array_slice($this->ranked($page, RecallTerms::ofLine($text), $principal), 0, self::PASSAGES) as $passage) {
            if (!isset($learned[$passage['path']])) {
                try {
                    $source = $this->storage->read($passage['path']);
                } catch (RuntimeException) {
                    continue;
                }
                $redactor->learn($source->frontmatter, $source->path, $source->body);
                $learned[$passage['path']] = $source->frontmatter;
            }
            $out[] = trim($redactor->redact($passage['text'], $learned[$passage['path']]));
        }

        return array_values(array_filter($out, static fn (string $s): bool => $s !== ''));
    }

    /**
     * Every passage that matches enough of $terms, best first: most terms
     * matched, then the caller's own, then this modality's, then the
     * index's best match; at most PER_REPORT from one report, each
     * text once
     *
     * @param list<string> $terms
     *
     * @return list<array{pid: string, path: string, rev: int, heading: string, text: string, study_date: ?string, modality: ?string, mine: bool}>
     */
    private function ranked(PageRecord $page, array $terms, User $principal): array
    {
        if ($terms === [] || (\count($terms) === 1 && mb_strlen($terms[0]) < self::ONE_WORD_MIN)) {
            return [];
        }
        $fm = $page->frontmatter;
        $modalities = (array) ($fm['modality'] ?? []);
        $patient = \is_array($fm['patient'] ?? null) ? $fm['patient'] : [];
        $cnp = MetaText::text($patient['cnp'] ?? null);
        $name = MetaText::text($patient['name'] ?? null);
        $ask = fn (bool $mineOnly): array => $this->index->recallCandidates(
            $terms,
            $principal->username,
            \is_string($modalities[0] ?? null) ? $modalities[0] : '',
            $page->pid,
            $cnp !== '' ? PatientKey::strong($cnp) : null,
            $name !== '' ? PatientKey::weak($name, is_numeric($patient['born'] ?? null) ? (int) $patient['born'] : null, \is_string($patient['sex'] ?? null) ? $patient['sex'] : null) : null,
            $principal,
            $mineOnly,
        );
        // The caller's own best, then everyone's best: the own never crowded out
        $candidates = [];
        foreach ([...$ask(true), ...$ask(false)] as $candidate) {
            $candidates[$candidate['pid']] ??= $candidate;
        }

        $need = min(2, \count($terms));
        $scored = [];
        foreach (array_values($candidates) as $order => $candidate) {
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
        usort($scored, static fn (array $a, array $b): int => [$b['matched'], $b['mine'], $b['same'], $a['order']] <=> [$a['matched'], $a['mine'], $a['same'], $b['order']]);

        $ranked = [];
        $perReport = [];
        $seen = [];
        foreach ($scored as $passage) {
            $key = RecallTerms::fold($passage['text']);
            if (isset($seen[$key]) || ($perReport[$passage['pid']] ?? 0) >= self::PER_REPORT) {
                continue;
            }
            $seen[$key] = true;
            $perReport[$passage['pid']] = ($perReport[$passage['pid']] ?? 0) + 1;
            $ranked[] = [
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

        return $ranked;
    }
}
