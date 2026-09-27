<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\MetaText;
use Reporion\Support\PatientKey;
use Throwable;

/**
 * {snippets} by full-text search (roadmap phase 15e; vectors later): the
 * words that matter in the text being written — the longer, rarer ones —
 * find finished reports of the same modality (never this patient's, only
 * what the caller can read); from each, the `###` sections sharing most
 * words with the text are the examples, cut short and de-identified. It is
 * what DokuLLM's ChromaDB did with embeddings, on the index already here.
 */
final class FtsExamples implements Examples
{
    private const TERMS = 12;
    private const PAGES = 6;
    private const EXAMPLES = 6;
    private const MAX_CHARS = 900;

    /** Words too common in a report to say anything about it */
    private const STOP = ['aspect', 'normal', 'normala', 'normale', 'dimensiuni', 'semnal', 'structuri', 'nivelul', 'fara', 'pentru', 'dreapta', 'stanga', 'modificari', 'examinare', 'prezinta', 'bilateral', 'limite', 'nivel', 'cazul', 'sunt', 'este'];

    public function __construct(
        private readonly Sqlite $index,
        private readonly StorageInterface $storage,
    ) {
    }

    public function for(PageRecord $page, string $text, ?User $principal, Redactor $redactor): array
    {
        $terms = self::terms($text);
        $modalities = (array) ($page->frontmatter['modality'] ?? []);
        $modality = \is_string($modalities[0] ?? null) ? $modalities[0] : '';
        $patient = \is_array($page->frontmatter['patient'] ?? null) ? $page->frontmatter['patient'] : [];
        $cnp = MetaText::text($patient['cnp'] ?? null);
        $name = MetaText::text($patient['name'] ?? null);
        $paths = $this->index->styleExamples(
            $terms,
            $modality,
            $page->pid,
            $cnp !== '' ? PatientKey::strong($cnp) : null,
            $name !== '' ? PatientKey::weak($name, is_numeric($patient['born'] ?? null) ? (int) $patient['born'] : null, \is_string($patient['sex'] ?? null) ? $patient['sex'] : null) : null,
            $principal,
            self::PAGES,
        );

        $candidates = [];
        foreach ($paths as $path) {
            try {
                $example = $this->storage->read($path);
            } catch (Throwable) {
                continue;
            }
            $redactor->learn($example->frontmatter, $example->path);
            foreach (self::sections($example->body) as $section) {
                $score = \count(array_intersect($terms, self::terms($section)));
                if ($score > 0) {
                    $candidates[] = ['score' => $score, 'text' => $redactor->redact($section, $example->frontmatter)];
                }
            }
        }
        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $examples = [];
        foreach ($candidates as $candidate) {
            $snippet = trim($candidate['text']);
            if ($snippet === '' || \in_array($snippet, $examples, true)) {
                continue;
            }
            $examples[] = mb_strlen($snippet) > self::MAX_CHARS ? rtrim(mb_substr($snippet, 0, self::MAX_CHARS)) . '…' : $snippet;
            if (\count($examples) === self::EXAMPLES) {
                break;
            }
        }

        return $examples;
    }

    /**
     * The words that matter: letters only, five or more, folded, the most
     * frequent first, common report words left out.
     *
     * @return list<string>
     */
    private static function terms(string $text): array
    {
        preg_match_all('/\p{L}{5,}/u', $text, $m);
        $counts = [];
        foreach ($m[0] as $word) {
            $folded = Redactor::fold($word);
            if (!\in_array($folded, self::STOP, true)) {
                $counts[$folded] = ($counts[$folded] ?? 0) + 1;
            }
        }
        arsort($counts);

        return \array_slice(array_map('strval', array_keys($counts)), 0, self::TERMS);
    }

    /**
     * A report's `###` sections, heading included, and the text before the
     * first one — without the `#`/`##` headings that title the report.
     *
     * @return list<string>
     */
    private static function sections(string $body): array
    {
        $sections = [];
        foreach (preg_split('/^(?=###[ \t])/m', $body) ?: [] as $part) {
            // The name and exam headings title the report; they are no example
            $part = trim((string) preg_replace('/^#{1,2}[ \t].*\R?/m', '', $part));
            if ($part !== '') {
                $sections[] = $part;
            }
        }

        return $sections;
    }
}
