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
use Reporion\Support\RecallTerms;
use RuntimeException;

/**
 * {snippets} by full-text search (roadmap phase 15e; vectors later): the
 * words that matter in the text being written: the longer, rarer ones
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

    public function __construct(
        private readonly Sqlite $index,
        private readonly StorageInterface $storage,
    ) {
    }

    public function for(PageRecord $page, string $text, ?User $principal, Redactor $redactor): array
    {
        $terms = RecallTerms::frequent($text, 5, self::TERMS);
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
            } catch (RuntimeException) {
                continue;
            }
            $redactor->learn($example->frontmatter, $example->path, $example->body);
            foreach (RecallTerms::sections($example->body) as $section) {
                $score = \count(array_intersect($terms, RecallTerms::frequent($section, 5, self::TERMS)));
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
}
