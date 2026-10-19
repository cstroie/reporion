<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Hipobridge;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Service\ArchiveAsk;
use Reporion\Storage\StorageInterface;
use Reporion\Support\RecallTerms;
use Reporion\Support\ReportPath;

/**
 * The HIS's side of Ask the archive (D38, D53; Plugin::askSources(), hook
 * `ask.sources`): the passages of the HIS's own texts (epicrisis
 * and imaging reports HippoBridge has indexed, found by its FHIR full-text
 * search `GET /fhir/Patient?content=` and read as Encounter / ImagingStudy) that a question is about, for
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
    private const PASSAGE_CHARS = 900;

    /**
     * Words of a question that are no finding: HippoBridge wants every word in
     * one text, so a "usually" or a "recommended" makes a good search find nothing
     */
    private const FILLER = [
        'obicei', 'uzual', 'frecvent', 'formulari', 'formulare', 'formulam', 'folosim', 'folosit', 'folosite', 'folosesc', 'avut', 'avea', 'avem', 'aveau',
        'controale', 'luni', 'saptamani', 'zile', 'recomanda', 'recomandam', 'recomandat', 'exista', 'intalnit', 'gasit', 'spune', 'spus', 'scris', 'notat',
        'mentionat', 'mentionata', 'cazuri', 'caz', 'exemple', 'exemplu', 'tipic', 'tipice', 'general', 'altfel', 'anume', 'diferit', 'diferite',
        'usually', 'commonly', 'common', 'wording', 'phrasing', 'phrases', 'found', 'mention', 'mentioned', 'cases', 'example', 'examples', 'typical', 'recommend', 'recommended',
    ];

    /** A capitalised word that begins a sentence stays when this many wiki pages use it: a finding word, not someone's name */
    private const KNOWN_IN_WIKI = 3;

    /** Words searched alone when all of them together find nothing */
    private const FALLBACK_TERMS = 3;

    /** Documents HippoBridge keeps by kind, and where they are read: path and query (the spec: epicrisis = Encounter id with type=checkout, imaging = request id of an ImagingStudy) */
    private const ROUTES = ['imaging' => ['/fhir/ImagingStudy/', []], 'epicrisis' => ['/fhir/Encounter/', ['type' => 'checkout']]];

    public function __construct(
        private readonly Client $client,
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
    ) {
    }

    /**
     * The words of $question that may leave the box: ArchiveAsk's terms less
     * filler, a patient's name and anything number-like. A capitalised word in
     * the middle of a sentence is a name (or a proper noun nothing here needs)
     * and goes; one that begins a sentence goes too unless the wiki itself
     * uses it in at least KNOWN_IN_WIKI pages (a name of a patient who is in no
     * report of ours is in none, and without a caller nothing is known).
     *
     * @return list<string>
     */
    public function terms(string $question, ?User $principal = null): array
    {
        $blocked = $this->nameWords();
        $tokens = preg_split('/\s+/u', trim($question)) ?: [];
        foreach ($tokens as $i => $token) {
            $word = trim($token, " \t.,;:!?()[]{}\"'«»„”");
            if (preg_match('/^\p{Lu}\p{Ll}{2,}/u', $word) !== 1) {
                continue;
            }
            $starts = $i === 0 || preg_match('/[.!?:]$/u', $tokens[$i - 1]) === 1;
            $folded = RecallTerms::fold($word);
            if (!$starts || $principal === null || $this->index->searchCount($folded, $principal) < self::KNOWN_IN_WIKI) {
                $blocked[$folded] = true;
            }
        }

        return array_values(array_filter(
            ArchiveAsk::terms($question),
            static fn (string $term): bool => !isset($blocked[$term]) && !\in_array($term, self::FILLER, true) && preg_match('/\d{4}/', $term) !== 1,
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
    public function passages(string $question, ?User $principal = null): array
    {
        $terms = $this->terms($question, $principal);
        if ($terms === []) {
            return [];
        }
        $hits = $this->search([$terms]);
        if ($hits === [] && \count($terms) > 2) {
            // Every word must match: a question in other words than the text's has a couple that do. The
            // longest few are asked alone, side by side, and what they find is merged
            usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            $hits = $this->search(array_map(static fn (string $t): array => [$t], \array_slice($terms, 0, self::FALLBACK_TERMS)));
            // The passages are chosen by what the whole question's words say
            $terms = array_values(array_unique(array_merge(...array_map(static fn (string $t): array => [$t], $terms))));
        }
        $requests = [];
        $about = [];
        foreach ($hits as $hit) {
            foreach ($hit['documents'] as $document) {
                $key = $document['kind'] . ':' . $document['id'];
                if (!isset($about[$key])) {
                    $about[$key] = $hit + ['kind' => $document['kind']];
                    $requests[$key] = [self::ROUTES[$document['kind']][0] . $document['id'], self::ROUTES[$document['kind']][1]];
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
            foreach (self::cut(self::text($answer, $hit['kind'])) as $text) {
                if (mb_strlen($text) >= self::MIN_CHARS) {
                    $scored[] = [
                        'matched' => \count(RecallTerms::matched($terms, $text)),
                        'order' => \count($scored),
                        'document' => (string) $key,
                        'kind' => $hit['kind'],
                        'date' => self::dateOf($answer) ?? $hit['date'],
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
     * The day a document says it is from (an ImagingStudy's `started`, an
     * Encounter's `period`, if it has them: the spec lists neither); the search
     * stub carries none
     *
     * @param array<mixed> $document
     */
    private static function dateOf(array $document): ?string
    {
        $period = \is_array($document['period'] ?? null) ? $document['period'] : [];
        foreach ([$document['started'] ?? null, $period['start'] ?? null, $period['end'] ?? null] as $value) {
            if (\is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
                return substr($value, 0, 10);
            }
        }

        return null;
    }

    /**
     * `GET /fhir/Patient?content=`: a searchset Bundle of Patient stubs (id =
     * CNP, name), each with its `search-document` extensions (kind, id) on
     * the resource; the hits that name their patient. One query per list of
     * words, side by side; several queries' hits are taken in turn (the first
     * of each, then the second…) and a patient found twice is kept once.
     *
     * @param list<list<string>> $queries
     *
     * @return list<array{documents: list<array{kind: string, id: string}>, date: ?string, names: list<string>, cnp: string}>
     *
     * @throws HisException
     */
    private function search(array $queries): array
    {
        $requests = [];
        foreach ($queries as $i => $words) {
            $requests[$i] = ['/fhir/Patient', ['content' => implode(' ', $words), 'snippet_length' => '12']];
        }
        $lists = [];
        $failure = null;
        foreach ($this->client->getMany($requests) as $i => $answer) {
            if ($answer instanceof HisException) {
                $failure ??= $answer;
                continue;
            }
            $lists[$i] = self::hitsOf($answer);
        }
        // Nothing answered: the failure is said (a notice); some answered: those are used
        if ($lists === [] && $failure !== null) {
            throw $failure;
        }
        $hits = [];
        $seen = [];
        for ($round = 0; \count($hits) < self::HITS; ++$round) {
            $any = false;
            foreach ($lists as $list) {
                if (!isset($list[$round])) {
                    continue;
                }
                $any = true;
                $key = $list[$round]['cnp'] . '|' . implode(' ', $list[$round]['names']);
                if (!isset($seen[$key]) && \count($hits) < self::HITS) {
                    $seen[$key] = true;
                    $hits[] = $list[$round];
                }
            }
            if (!$any) {
                break;
            }
        }

        return $hits;
    }

    /**
     * The hits of one answer that name their patient and have a document
     *
     * @param array<string, mixed> $answer
     *
     * @return list<array{documents: list<array{kind: string, id: string}>, date: ?string, names: list<string>, cnp: string}>
     */
    private static function hitsOf(array $answer): array
    {
        $hits = [];
        // The extensions may sit on the entry or on the Patient: both are read
        foreach (\is_array($answer['entry'] ?? null) ? $answer['entry'] : [] as $entry) {
            $patient = \is_array($entry) && \is_array($entry['resource'] ?? null) ? $entry['resource'] : [];
            if (($patient['resourceType'] ?? null) !== 'Patient') {
                continue;
            }
            $stub = Fhir::patient($patient);
            $documents = [];
            foreach ([...self::extensions($entry), ...self::extensions($patient)] as $extension) {
                if (preg_match('#(?:^|/)search-document$#', (string) ($extension['url'] ?? '')) === 1) {
                    $documents[] = self::documentOf($extension);
                }
            }
            $documents = self::documents(['documents' => array_filter($documents)]);
            if ($stub['name'] === '' || $documents === []) {
                continue;
            }
            $updated = \is_array($patient['meta'] ?? null) && \is_string($patient['meta']['lastUpdated'] ?? null) ? $patient['meta']['lastUpdated'] : '';
            $cnp = $stub['cnp'] !== '' ? $stub['cnp'] : (preg_match('/^\d{13}$/', $stub['id']) === 1 ? $stub['id'] : '');
            $hits[] = [
                'documents' => $documents,
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}/', $updated) === 1 ? substr($updated, 0, 10) : null,
                'names' => [$stub['name']],
                'cnp' => $cnp,
            ];
        }

        return $hits;
    }

    /**
     * A `search-document` extension as {kind, source_id}: nested
     * extensions (kind, id) or one `kind:id` / `kind/id` string
     *
     * @param array<string, mixed> $extension
     *
     * @return ?array{kind: string, source_id: string}
     */
    private static function documentOf(array $extension): ?array
    {
        $parts = [];
        foreach (self::extensions($extension) as $inner) {
            $value = $inner['valueString'] ?? $inner['valueCode'] ?? $inner['valueInteger'] ?? null;
            if (\is_scalar($value) && \is_string($inner['url'] ?? null)) {
                $parts[basename($inner['url'])] = (string) $value;
            }
        }
        if (isset($parts['kind'], $parts['id']) || isset($parts['kind'], $parts['source_id'])) {
            return ['kind' => $parts['kind'], 'source_id' => $parts['id'] ?? $parts['source_id']];
        }
        if (\is_string($extension['valueString'] ?? null) && preg_match('#^([a-z]+)[:/](.+)$#', $extension['valueString'], $m) === 1) {
            return ['kind' => $m[1], 'source_id' => $m[2]];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $holder
     *
     * @return list<array<string, mixed>>
     */
    private static function extensions(array $holder): array
    {
        return array_values(array_filter(\is_array($holder['extension'] ?? null) ? $holder['extension'] : [], '\is_array'));
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
     * The report text of an ImagingStudy or a checkout Encounter: its
     * `note[].text` (Markdown), as HippoBridge's spec says and indexes it.
     * An imaging study's category-less notes are the radiology reports; its
     * `clinical-indication` note is the order's justification, not indexed,
     * not read. An Encounter's notes are the epicrisis and the discharge
     * diagnosis. Markup and Markdown emphasis removed.
     *
     * @param array<mixed> $document
     */
    private static function text(array $document, string $kind): string
    {
        $lines = [];
        foreach (\is_array($document['note'] ?? null) ? $document['note'] : [] as $note) {
            if (!\is_array($note) || !\is_string($note['text'] ?? null)) {
                continue;
            }
            $categories = array_map(static fn (mixed $c): string => \is_array($c) && \is_string($c['text'] ?? null) ? $c['text'] : '', \is_array($note['category'] ?? null) ? $note['category'] : []);
            if ($kind === 'imaging' && array_filter($categories) !== []) {
                continue;
            }
            $plain = html_entity_decode(strip_tags(preg_replace('~<\s*(?:br|/p|/div|/li)[^>]*>~i', "\n", $note['text']) ?? $note['text']), ENT_QUOTES | ENT_HTML5);
            $lines[] = trim((string) preg_replace('/\*\*|__/', '', $plain));
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
