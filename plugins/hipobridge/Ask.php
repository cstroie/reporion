<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Hipobridge;

use Reporion\Service\ArchiveAsk;
use Reporion\Storage\StorageInterface;
use Reporion\Support\RecallTerms;
use Reporion\Support\ReportPath;

/**
 * Ask HippoBridge (D38, D53): the passages of the HIS's own texts (epicrisis
 * and imaging reports HippoBridge has indexed) that a question is about, for
 * the `ask-archive` prompt to answer from, as Service\ArchiveAsk does for the
 * wiki's reports.
 *
 * What is sent to HippoBridge is a handful of content words, never the
 * question: the search is a GET, so its words land in the bridge's access
 * log, and a patient name typed in a question must not (D39). Words that are
 * a name word of a report's path, digit runs, and capitalised words in the
 * middle of a sentence are dropped first.
 *
 * What comes back names its patient (`patient_name`, `patient_cnp`): they
 * travel with each passage for the Redactor to learn, and are never shown,
 * audited or logged (invariant 8). A hit without a name is dropped: nothing
 * could de-identify its text.
 */
final class Ask
{
    /** Hits whose documents are read */
    private const HITS = 6;

    /** Documents read for one hit */
    private const PER_HIT = 2;

    /** Passages from one document at most */
    private const PER_DOCUMENT = 2;

    /** A passage shorter than this says nothing worth citing */
    private const MIN_CHARS = 15;

    /** The most a passage holds before it is cut by sentences */
    private const PASSAGE_CHARS = 500;

    /** The key of a value that names a person or an address: never read as text */
    private const SKIP_KEYS = '/name|nume|cnp|patient|pacient|medic|doctor|physician|validator|radiolog|address|adresa|phone|telefon|email|^uid$/i';

    /** Documents HippoBridge keeps by kind, and where they are read */
    private const ROUTES = ['imaging' => '/api/study/', 'epicrisis' => '/api/checkout/'];

    public function __construct(
        private readonly Client $client,
        private readonly StorageInterface $storage,
    ) {
    }

    /**
     * The words of $question that may leave the box: ArchiveAsk's terms less
     * a patient's name and anything number-like
     *
     * @return list<string>
     */
    public function terms(string $question): array
    {
        $blocked = $this->nameWords();
        // A capitalised word that does not begin a sentence is a name, or a proper noun nothing here needs
        $tokens = preg_split('/\s+/u', trim($question)) ?: [];
        foreach ($tokens as $i => $token) {
            $word = trim($token, " \t.,;:!?()[]{}\"'«»„”");
            if ($i > 0 && preg_match('/^\p{Lu}\p{Ll}{2,}/u', $word) === 1 && preg_match('/[.!?:]$/u', $tokens[$i - 1]) !== 1) {
                $blocked[RecallTerms::fold($word)] = true;
            }
        }

        return array_values(array_filter(
            ArchiveAsk::terms($question),
            static fn (string $term): bool => !isset($blocked[$term]) && preg_match('/\d{4}/', $term) !== 1,
        ));
    }

    /**
     * The passages the question is about, best first, numbered from 1; none
     * when HippoBridge has no text search (`enabled: false`), nothing matches
     * or no word of the question may be sent.
     *
     * @return list<array{n: int, kind: string, date: ?string, text: string, names: list<string>, cnp: string}>
     *
     * @throws HisException
     */
    public function passages(string $question): array
    {
        $terms = $this->terms($question);
        if ($terms === []) {
            return [];
        }
        $hits = $this->search($terms);
        if ($hits === [] && \count($terms) > 2) {
            // Every word must match: a question in other words than the text's has a couple that do
            usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            $hits = $this->search(\array_slice($terms, 0, 2));
        }
        $requests = [];
        $about = [];
        foreach ($hits as $hit) {
            foreach ($hit['documents'] as $document) {
                $key = $document['kind'] . ':' . $document['id'];
                if (!isset($about[$key])) {
                    $about[$key] = $hit + ['kind' => $document['kind']];
                    $requests[$key] = [self::ROUTES[$document['kind']] . $document['id']];
                }
            }
        }
        $scored = [];
        foreach ($this->client->getMany($requests) as $key => $answer) {
            // One document that cannot be read leaves the others
            if ($answer instanceof HisException) {
                continue;
            }
            $hit = $about[$key];
            foreach (self::cut(self::text($answer)) as $text) {
                if (mb_strlen($text) >= self::MIN_CHARS) {
                    $scored[] = [
                        'matched' => \count(RecallTerms::matched($terms, $text)),
                        'order' => \count($scored),
                        'document' => (string) $key,
                        'kind' => $hit['kind'],
                        'date' => $hit['date'],
                        'text' => $text,
                        'names' => $hit['names'],
                        'cnp' => $hit['cnp'],
                    ];
                }
            }
        }
        // As ArchiveAsk: two terms in one passage when the question has them, else one
        $need = min(2, max(array_column($scored, 'matched') ?: [0]));
        $scored = array_values(array_filter($scored, static fn (array $p): bool => $p['matched'] >= max(1, $need)));
        usort($scored, static fn (array $a, array $b): int => [$b['matched'], $a['order']] <=> [$a['matched'], $b['order']]);

        $passages = [];
        $perDocument = [];
        $seen = [];
        foreach ($scored as $passage) {
            $fold = RecallTerms::fold($passage['text']);
            if (isset($seen[$fold]) || ($perDocument[$passage['document']] ?? 0) >= self::PER_DOCUMENT) {
                continue;
            }
            $seen[$fold] = true;
            $perDocument[$passage['document']] = ($perDocument[$passage['document']] ?? 0) + 1;
            $passages[] = [
                'n' => \count($passages) + 1,
                'kind' => $passage['kind'],
                'date' => $passage['date'],
                'text' => $passage['text'],
                'names' => $passage['names'],
                'cnp' => $passage['cnp'],
            ];
            if (\count($passages) === ArchiveAsk::SOURCES) {
                break;
            }
        }

        return $passages;
    }

    /**
     * `GET /api/search/text`: the hits that name their patient
     *
     * @param list<string> $terms
     *
     * @return list<array{documents: list<array{kind: string, id: string}>, date: ?string, names: list<string>, cnp: string}>
     *
     * @throws HisException
     */
    private function search(array $terms): array
    {
        $answer = $this->client->get('/api/search/text', ['q' => implode(' ', $terms), 'snippet_length' => '12']);
        if (($answer['enabled'] ?? false) !== true || !\is_array($answer['results'] ?? null)) {
            return [];
        }
        $hits = [];
        foreach ($answer['results'] as $result) {
            if (!\is_array($result) || \count($hits) === self::HITS) {
                continue;
            }
            $name = trim(\is_string($result['patient_name'] ?? null) ? $result['patient_name'] : '');
            $documents = self::documents($result);
            if ($name === '' || $documents === []) {
                continue;
            }
            $updated = \is_string($result['updated_at'] ?? null) ? $result['updated_at'] : '';
            $hits[] = [
                'documents' => $documents,
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}/', $updated) === 1 ? substr($updated, 0, 10) : null,
                'names' => [$name],
                'cnp' => \is_scalar($result['patient_cnp'] ?? null) ? (string) $result['patient_cnp'] : '',
            ];
        }

        return $hits;
    }

    /**
     * The documents of a hit (its `documents[]`, else the hit itself) that
     * have a route: at most PER_HIT
     *
     * @param array<string, mixed> $hit
     *
     * @return list<array{kind: string, id: string}>
     */
    private static function documents(array $hit): array
    {
        $list = \is_array($hit['documents'] ?? null) && $hit['documents'] !== [] ? $hit['documents'] : [$hit];
        $documents = [];
        foreach ($list as $document) {
            $kind = \is_array($document) && \is_string($document['kind'] ?? null) ? $document['kind'] : '';
            $id = \is_array($document) && \is_scalar($document['source_id'] ?? null) ? (string) $document['source_id'] : '';
            if (isset(self::ROUTES[$kind]) && preg_match('/^(?!\.+$)[A-Za-z0-9._-]{1,64}$/', $id) === 1) {
                $documents[] = ['kind' => $kind, 'id' => $id];
            }
            if (\count($documents) === self::PER_HIT) {
                break;
            }
        }

        return $documents;
    }

    /**
     * The text a raw HippoData document holds: its long strings, whatever
     * their key (the shapes are not in the spec), less the keys that name a
     * person; markup removed
     *
     * @param array<mixed> $data
     */
    private static function text(array $data, int $depth = 0): string
    {
        $lines = [];
        foreach ($data as $key => $value) {
            if (\is_string($key) && preg_match(self::SKIP_KEYS, $key) === 1) {
                continue;
            }
            if (\is_array($value) && $depth < 6) {
                $lines[] = self::text($value, $depth + 1);
            } elseif (\is_string($value) && mb_strlen($value) >= self::MIN_CHARS * 2) {
                $plain = html_entity_decode(strip_tags(preg_replace('~<\s*(?:br|/p|/div|/li)[^>]*>~i', "\n", $value) ?? $value), ENT_QUOTES | ENT_HTML5);
                $lines[] = $plain;
            }
        }

        return trim(implode("\n", array_filter($lines, static fn (string $l): bool => $l !== '')));
    }

    /**
     * A text's passages: each line, a long one cut into whole sentences
     *
     * @return list<string>
     */
    private static function cut(string $text): array
    {
        $pieces = [];
        foreach (preg_split('/\R+/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line === '') {
                continue;
            }
            $current = '';
            foreach (preg_split('/(?<=[.;!?])\s+(?=\p{Lu}|\d)/u', $line) ?: [] as $sentence) {
                if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($sentence) > self::PASSAGE_CHARS) {
                    $pieces[] = $current;
                    $current = '';
                }
                $current = $current === '' ? $sentence : $current . ' ' . $sentence;
            }
            if ($current !== '') {
                $pieces[] = $current;
            }
        }

        return $pieces;
    }

    /** @return array<string, true> every name word (three letters or more) of a report's path, folded */
    private function nameWords(): array
    {
        $words = [];
        foreach ($this->storage->allPaths() as $path) {
            if (!ReportPath::isReport($path)) {
                continue;
            }
            $segments = explode(':', $path);
            $slug = explode('-', (string) end($segments));
            array_shift($slug);
            foreach ($slug as $word) {
                if (\strlen($word) >= 3 && ctype_alpha($word)) {
                    $words[RecallTerms::fold($word)] = true;
                }
            }
        }

        return $words;
    }
}
