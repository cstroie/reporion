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
 * `ask.sources`): the passages of the HIS's own texts that a question is
 * about, for the `ask-archive` prompt to answer from, as Service\ArchiveAsk
 * does for the wiki's reports. HippoBridge's passage search returns them
 * ranked, one document each, with the best passage(s) in the answer: no second
 * request per document.
 *
 *  - imaging studies: `GET /fhir/ImagingStudy?content=` (the text in
 *    `note[0].text`, the patient as `subject`: `Patient/{cnp}` and a name);
 *  - epicrisis: `GET /fhir/DocumentReference?content=&kind=epicrisis` (the text
 *    in `content[0].attachment.data`, the patient in `search-identifiers`).
 *
 * What is sent to HippoBridge is a handful of content words, never the
 * question: the search is a GET, so its words land in the bridge's access
 * log, and a patient name typed in a question must not (D39). Words that are
 * filler, a name word of a report's path, digit runs, and capitalised words
 * the wiki does not use are dropped first.
 *
 * What comes back names its patient: the name, the CNP and whatever else the
 * HIS page held (address, phone, email…) travel with each passage for the
 * Redactor to learn, and are never shown, audited or logged (invariant 8). A
 * document without a name is dropped: nothing could de-identify its text.
 */
final class Ask
{
    /** Documents asked of each endpoint */
    private const COUNT = 20;

    /** Longest passage text HippoBridge returns for a document (its `max-chars`) */
    private const MAX_CHARS = 900;

    /** Passages from one document at most */
    private const PER_DOCUMENT = 2;

    /** A passage shorter than this says nothing worth citing */
    private const MIN_CHARS = 15;

    /** The most a passage holds before it is cut by sentences */
    private const PASSAGE_CHARS = 900;

    /**
     * Words of a question that are no finding: a "usually" or a "recommended"
     * is in no report, and each makes a search ask for more than the text has
     */
    private const FILLER = [
        'obicei', 'uzual', 'frecvent', 'formulari', 'formulare', 'formulam', 'folosim', 'folosit', 'folosite', 'folosesc', 'avut', 'avea', 'avem', 'aveau',
        'controale', 'luni', 'saptamani', 'zile', 'recomanda', 'recomandam', 'recomandat', 'exista', 'intalnit', 'gasit', 'spune', 'spus', 'scris', 'notat',
        'mentionat', 'mentionata', 'cazuri', 'caz', 'exemple', 'exemplu', 'tipic', 'tipice', 'general', 'altfel', 'anume', 'diferit', 'diferite',
        'usually', 'commonly', 'common', 'wording', 'phrasing', 'phrases', 'found', 'mention', 'mentioned', 'cases', 'example', 'examples', 'typical', 'recommend', 'recommended',
    ];

    /** A capitalised word that begins a sentence stays when this many wiki pages use it: a finding word, not someone's name */
    private const KNOWN_IN_WIKI = 3;

    /** `search-identifiers` keys that name the patient: their values are learned as name parts */
    private const NAME_KEYS = ['name', 'family-name', 'given-name'];

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
     * when HippoBridge has no text search (an OperationOutcome), nothing
     * matches or no word of the question may be sent.
     *
     * @return list<array{n: int, kind: string, date: ?string, heading: string, text: string, names: list<string>, cnp: string, extra: list<string>}>
     *
     * @throws HisException
     */
    public function passages(string $question, ?User $principal = null): array
    {
        $terms = $this->terms($question, $principal);
        if ($terms === []) {
            return [];
        }
        // At least two of the words in one passage when the question has them; one when nothing has
        $documents = $this->search($terms, min(2, \count($terms)));
        if ($documents === [] && \count($terms) > 1) {
            $documents = $this->search($terms, 1);
        }
        $scored = [];
        foreach ($documents as $order => $document) {
            foreach ($document['passages'] as $passage) {
                foreach (self::cut($passage['text']) as $text) {
                    if (mb_strlen($text) >= self::MIN_CHARS) {
                        $scored[] = [
                            'matched' => \count(RecallTerms::matched($terms, $text)),
                            'order' => \count($scored),
                            'document' => $order,
                            'kind' => $document['kind'],
                            'date' => $document['date'],
                            'heading' => $passage['heading'],
                            'text' => $text,
                            'names' => $document['names'],
                            'cnp' => $document['cnp'],
                            'extra' => $document['extra'],
                        ];
                    }
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
                'heading' => $passage['heading'],
                'text' => $passage['text'],
                'names' => $passage['names'],
                'cnp' => $passage['cnp'],
                'extra' => $passage['extra'],
            ];
            if (\count($passages) === ArchiveAsk::SOURCES) {
                break;
            }
        }

        return $passages;
    }

    /**
     * Both endpoints side by side, the documents that name their patient, best
     * first (HippoBridge's `search-score`, the endpoints merged by it). Only
     * validated text (its `status` default). One endpoint failing leaves the
     * other's; both failing is the failure.
     *
     * @param list<string> $terms
     *
     * @return list<array{kind: string, date: ?string, score: float, passages: list<array{heading: string, text: string}>, names: list<string>, cnp: string, extra: list<string>}>
     *
     * @throws HisException
     */
    private function search(array $terms, int $minMatch): array
    {
        $query = ['content' => implode(' ', $terms), 'match' => 'any', 'min-match' => (string) $minMatch, '_count' => (string) self::COUNT, 'max-chars' => (string) self::MAX_CHARS, 'context' => '1'];
        $answers = $this->client->getMany([
            'imaging' => ['/fhir/ImagingStudy', $query],
            'epicrisis' => ['/fhir/DocumentReference', $query + ['kind' => 'epicrisis']],
        ]);
        $documents = [];
        $failure = null;
        $answered = false;
        foreach ($answers as $kind => $answer) {
            if ($answer instanceof HisException) {
                $failure ??= $answer;
                continue;
            }
            $answered = true;
            foreach (self::documentsOf($answer, (string) $kind) as $document) {
                $documents[] = $document;
            }
        }
        // Nothing answered: the failure is said (a notice); some answered: those are used
        if (!$answered && $failure !== null) {
            throw $failure;
        }
        usort($documents, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $documents;
    }

    /**
     * The documents of one answer (a searchset Bundle) that name their patient
     * and hold text
     *
     * @param array<string, mixed> $bundle
     *
     * @return list<array{kind: string, date: ?string, score: float, passages: list<array{heading: string, text: string}>, names: list<string>, cnp: string, extra: list<string>}>
     */
    private static function documentsOf(array $bundle, string $kind): array
    {
        $documents = [];
        foreach (\is_array($bundle['entry'] ?? null) ? $bundle['entry'] : [] as $entry) {
            $resource = \is_array($entry) && \is_array($entry['resource'] ?? null) ? $entry['resource'] : [];
            $type = $resource['resourceType'] ?? null;
            if ($type !== 'ImagingStudy' && $type !== 'DocumentReference') {
                continue;
            }
            $extensions = self::extensions($resource);
            $identifiers = self::identifiers($extensions);
            $names = [];
            foreach (self::NAME_KEYS as $key) {
                if (($identifiers[$key] ?? '') !== '') {
                    $names[] = $identifiers[$key];
                }
            }
            $cnp = $identifiers['cnp'] ?? '';
            // An ImagingStudy names its patient as its subject: `Patient/{cnp}` and a name
            $subject = \is_array($resource['subject'] ?? null) ? $resource['subject'] : [];
            if (\is_string($subject['display'] ?? null) && trim($subject['display']) !== '') {
                $names[] = trim($subject['display']);
            }
            if ($cnp === '' && preg_match('#(?:^|/)(\d{13})$#', (string) ($subject['reference'] ?? ''), $m) === 1) {
                $cnp = $m[1];
            }
            $names = array_values(array_unique($names));
            $text = $type === 'ImagingStudy' ? self::noteText($resource) : self::attachmentText($resource);
            if ($names === [] || trim($text) === '') {
                continue;
            }
            // The passages are joined by a blank line (none holds one); `search-passage` is the section of each, in order
            $sections = [];
            foreach ($extensions as $extension) {
                if (self::is($extension, 'search-passage')) {
                    $sections[] = \is_string($extension['valueString'] ?? null) ? trim($extension['valueString']) : '';
                }
            }
            $passages = [];
            foreach (preg_split('/\R[ \t]*\R+/u', trim($text)) ?: [] as $i => $passage) {
                if (trim($passage) !== '') {
                    $passages[] = ['heading' => $sections[$i] ?? '', 'text' => trim($passage)];
                }
            }
            $score = 0.0;
            foreach ($extensions as $extension) {
                if (self::is($extension, 'search-score') && is_numeric($extension['valueDecimal'] ?? null)) {
                    $score = (float) $extension['valueDecimal'];
                }
            }
            $when = (string) ($resource['started'] ?? $resource['date'] ?? '');
            $documents[] = [
                'kind' => $kind,
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}/', $when) === 1 ? substr($when, 0, 10) : null,
                'score' => $score,
                'passages' => $passages,
                'names' => $names,
                'cnp' => $cnp,
                // Everything else the HIS page held that points at the patient: learned, never sent anywhere
                'extra' => array_values(array_filter(array_diff_key($identifiers, array_flip([...self::NAME_KEYS, 'cnp'])), static fn (string $v): bool => $v !== '')),
            ];
        }

        return $documents;
    }

    /** An ImagingStudy's report text: its notes without a category (the first may be the order's justification) */
    private static function noteText(array $study): string
    {
        $lines = [];
        foreach (\is_array($study['note'] ?? null) ? $study['note'] : [] as $note) {
            if (\is_array($note) && \is_string($note['text'] ?? null) && !(\is_array($note['category'] ?? null) && $note['category'] !== [])) {
                $lines[] = self::plain($note['text']);
            }
        }

        return implode("\n\n", array_filter($lines, static fn (string $l): bool => $l !== ''));
    }

    /** A DocumentReference's text: its first attachment, plain text (not base64) */
    private static function attachmentText(array $reference): string
    {
        $content = \is_array($reference['content'][0] ?? null) ? $reference['content'][0] : [];
        $data = \is_array($content['attachment'] ?? null) && \is_string($content['attachment']['data'] ?? null) ? $content['attachment']['data'] : '';

        return self::plain($data);
    }

    /** Text with markup and Markdown emphasis out; paragraph breaks kept */
    private static function plain(string $text): string
    {
        $plain = html_entity_decode(strip_tags(preg_replace('~<\s*(?:br|/p|/div|/li)[^>]*>~i', "\n", $text) ?? $text), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/\*\*|__/', '', $plain));
    }

    /**
     * `search-identifiers`: its sub-extensions' url (name, cnp, address, phone…) and value
     *
     * @param list<array<string, mixed>> $extensions
     *
     * @return array<string, string>
     */
    private static function identifiers(array $extensions): array
    {
        $out = [];
        foreach ($extensions as $extension) {
            if (!self::is($extension, 'search-identifiers')) {
                continue;
            }
            foreach (self::extensions($extension) as $inner) {
                $value = $inner['valueString'] ?? null;
                if (\is_scalar($value) && \is_string($inner['url'] ?? null) && trim((string) $value) !== '') {
                    $out[basename($inner['url'])] = trim((string) $value);
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $extension */
    private static function is(array $extension, string $name): bool
    {
        return preg_match('#(?:^|/)' . preg_quote($name, '#') . '$#', (string) ($extension['url'] ?? '')) === 1;
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
