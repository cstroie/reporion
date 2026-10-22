<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The checks a report's text is held to while it is written and before it
 * is signed (D51): one list for the editor's Checks section and the Sign
 * screen. Warnings, never a block (D7's spirit); rules, no assistant. Pure:
 * codes and their arguments out, the screen words them (`sign.check.{code}`).
 *
 * - Laterality's sides that disagree, and an exam with no conclusion;
 * - `sex_mismatch`: an organ of the other sex than `patient.sex`;
 * - `blank`: a fill-in left in the text: `[dimensiune]` (what Recall's
 *   Expand writes), a lone `xx`;
 * - `empty_section`: a `###` heading with nothing under it;
 * - `not_concluded`: a finding the description states (a finding word not
 *   negated, or a size) of which the conclusion says nothing;
 * - `not_described`: a finding the conclusion names that the description
 *   never does.
 *
 * Each carries `quote`, a short piece of the text exactly as written, for
 * the editor to find the line by.
 */
final class WritingChecks
{
    /** Per exam, at most this many of each finding check: reminders, not lints */
    private const CAP = 5;

    private const MAX_BLANKS = 10;

    private const QUOTE = 60;

    /** Words that name a finding (folded); matched by Support\RecallTerms' stem */
    private const FINDINGS = [
        'leziune', 'formatiune', 'nodul', 'nodular', 'chist', 'hernie', 'protruzie', 'fractura', 'fisura', 'ruptura',
        'colectie', 'stenoza', 'tumora', 'tumoral', 'masa', 'adenopatie', 'revarsat', 'hematom', 'abces', 'metastaza',
        'polip', 'calcul', 'litiaza', 'anevrism', 'tromb', 'tromboza', 'infarct', 'ischemie', 'edem', 'displazie',
        'dilatatie', 'ectazie', 'contuzie', 'luxatie', 'necroza', 'hemoragie', 'pneumotorax', 'emfizem', 'atelectazie',
        'condensare', 'opacitate', 'calcificare', 'osteofit', 'spondiloza', 'spondilolisteza', 'tendinoza', 'bursita', 'sinovita',
    ];

    /** Words that make the finding after them an absent one */
    private const NEGATIONS = ['fara', 'nu', 'absent', 'absenta', 'absente', 'exclus', 'exclusa', 'nici'];

    /** Words that make a sentence a normal one, for a size with no finding word */
    private const NORMAL = ['normal', 'normala', 'normale', 'normali', 'nemodificat', 'nemodificata', 'omogen', 'omogena', 'pastrat', 'pastrata'];

    /** Organs one sex does not have: the other sex's (folded word prefixes) */
    private const ORGANS = [
        'F' => ['prostat', 'testicul', 'scrot', 'epididim', 'seminal'],
        'M' => ['uter', 'ovar', 'endometr', 'salping'],
    ];

    /**
     * @param array<string, mixed> $frontmatter
     *
     * @return list<array{code: string, exam: string, sides: list<string>, other: list<string>, args: list<string>, quote: string}>
     */
    public static function check(string $body, array $frontmatter): array
    {
        $out = [];
        foreach (Laterality::check($body, $frontmatter) as $warning) {
            $out[] = $warning + ['args' => [], 'quote' => ''];
        }

        $text = ReportName::withoutNameHeading($body, $frontmatter);
        $prose = self::withoutFences($text);
        foreach (self::sexMismatch($prose, $frontmatter) as $word) {
            $out[] = self::warning('sex_mismatch', '', [$word], $word);
        }
        foreach (self::blanks($prose) as $blank) {
            $out[] = self::warning('blank', '', [$blank], $blank);
        }
        foreach (self::emptySections($prose) as [$heading, $line]) {
            $out[] = self::warning('empty_section', '', [$heading], $line);
        }

        $split = Exams::split($prose);
        $parts = Exams::checkParts($split, $frontmatter);
        foreach ($parts as $part) {
            $exam = trim($part['title']) !== '' ? trim($part['title']) : Exams::title($frontmatter);
            $sections = Laterality::sections($part['text']);
            if (!isset($sections['conclusion'])) {
                continue; // Laterality says so already
            }
            $description = $sections['description'] ?? '';
            $conclusion = $sections['conclusion'];
            $n = 0;
            foreach (self::sentences($description) as $sentence) {
                if ($n < self::CAP && !self::concluded($sentence, $conclusion)) {
                    $out[] = self::warning('not_concluded', $exam, [self::quote($sentence)], self::quote($sentence));
                    $n++;
                }
            }
            $n = 0;
            foreach (self::sentences($conclusion) as $sentence) {
                $missing = array_values(array_filter(self::findings($sentence), static fn (string $word): bool => !self::hasFinding($description, $word)));
                if ($n < self::CAP && $missing !== []) {
                    $out[] = self::warning('not_described', $exam, [$missing[0]], self::quote($sentence));
                    $n++;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<string> $args
     *
     * @return array{code: string, exam: string, sides: list<string>, other: list<string>, args: list<string>, quote: string}
     */
    private static function warning(string $code, string $exam, array $args, string $quote): array
    {
        return ['code' => $code, 'exam' => $exam, 'sides' => [], 'other' => [], 'args' => $args, 'quote' => $quote];
    }

    /** Fenced code is no prose: its lines are blanked, the rest kept as written */
    private static function withoutFences(string $text): string
    {
        $out = [];
        $fence = false;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s{0,3}(```|~~~)/', $line) === 1) {
                $fence = !$fence;
                $out[] = '';
                continue;
            }
            $out[] = $fence ? '' : $line;
        }

        return implode("\n", $out);
    }

    /**
     * The other sex's organs the text names, not negated, each once as written
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return list<string>
     */
    private static function sexMismatch(string $text, array $frontmatter): array
    {
        $sex = \is_array($frontmatter['patient'] ?? null) ? strtoupper(MetaText::text($frontmatter['patient']['sex'] ?? null)) : '';
        $prefixes = self::ORGANS[$sex] ?? [];
        if ($prefixes === []) {
            return [];
        }
        $found = [];
        foreach (self::sentences($text) as $sentence) {
            [$words, $folded] = self::words($sentence);
            foreach ($folded as $i => $word) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($word, $prefix) && !self::negated($folded, $i) && !\in_array($words[$i], $found, true)) {
                        $found[] = $words[$i];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Fill-ins left in the text: a `[word]` that is no link, reference,
     * footnote or task box, and a lone `xx`
     *
     * @return list<string>
     */
    private static function blanks(string $text): array
    {
        $found = [];
        foreach (preg_split('/\n/', $text) ?: [] as $line) {
            // A task box at the start of a list item is markdown, not a blank
            $scan = (string) preg_replace('/^(\s*(?:[-*+]|\d+[.)])\s+)\[[ xX]\]/', '$1', $line);
            if (preg_match('/^\s{0,3}\[[^\]]+\]:/', $scan) === 1) {
                continue; // a link reference definition
            }
            preg_match_all('/(?<![\\\\!\]])\[(?!\^)([^\[\]\n]{1,40})\](?![(\[:])/u', $scan, $m);
            foreach ($m[0] as $blank) {
                $found[] = $blank;
            }
            preg_match_all('/(?<![\p{L}\p{N}])xx(?![\p{L}\p{N}])/iu', $scan, $x);
            foreach ($x[0] as $blank) {
                $found[] = $blank;
            }
        }

        return \array_slice(array_values(array_unique($found)), 0, self::MAX_BLANKS);
    }

    /**
     * `###` (and deeper) headings with nothing under them before the next heading
     *
     * @return list<array{0: string, 1: string}> [heading text, the heading line as written]
     */
    private static function emptySections(string $text): array
    {
        $lines = preg_split('/\n/', $text) ?: [];
        $empty = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^ {0,3}#{3,6}[ \t]+(.+?)[ \t#]*$/u', $line, $m) !== 1) {
                continue;
            }
            $content = false;
            for ($j = $i + 1; $j < \count($lines); $j++) {
                if (preg_match('/^ {0,3}#{1,6}[ \t]/', $lines[$j]) === 1) {
                    break;
                }
                if (trim($lines[$j]) !== '') {
                    $content = true;
                    break;
                }
            }
            if (!$content) {
                $empty[] = [trim($m[1]), trim($line)];
            }
        }

        return $empty;
    }

    /**
     * A section's sentences: each line's, cut after . ; ! ?, without list
     * markers and emphasis; headings and blank lines left out
     *
     * @return list<string> each a piece of the text as written
     */
    private static function sentences(string $text): array
    {
        $out = [];
        foreach (preg_split('/\n/', $text) ?: [] as $line) {
            if (preg_match('/^ {0,3}#{1,6}[ \t]/', $line) === 1) {
                continue;
            }
            $line = (string) preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+(?:\[[ xX]\]\s+)?/', '', $line);
            foreach (preg_split('/(?<=[.;!?])\s+/u', $line) ?: [] as $sentence) {
                $sentence = trim($sentence, " \t*_");
                if (preg_match('/\p{L}/u', $sentence) === 1) {
                    $out[] = $sentence;
                }
            }
        }

        return $out;
    }

    /**
     * Whether the conclusion says something of a description's sentence: it
     * names a finding word of it, or, for a size with no finding word and no
     * word that calls it normal, any of its words; a sentence with neither
     * finding nor size needs nothing
     */
    private static function concluded(string $sentence, string $conclusion): bool
    {
        $findings = self::findings($sentence);
        if ($findings !== []) {
            foreach ($findings as $word) {
                if (self::hasFinding($conclusion, $word)) {
                    return true;
                }
            }

            return false;
        }
        if (Measurements::of($sentence) === []) {
            return true;
        }
        [, $folded] = self::words($sentence);
        if (array_intersect($folded, self::NORMAL) !== [] || array_intersect($folded, self::NEGATIONS) !== []) {
            return true;
        }
        $terms = RecallTerms::ofLine($sentence);

        return $terms === [] || RecallTerms::matched($terms, $conclusion) !== [];
    }

    /**
     * The finding words a sentence names that no negation just before takes back, folded
     *
     * @return list<string>
     */
    private static function findings(string $sentence): array
    {
        [, $folded] = self::words($sentence);
        $found = [];
        foreach ($folded as $i => $word) {
            foreach (self::FINDINGS as $finding) {
                if (self::is($word, $finding) && !self::negated($folded, $i) && !\in_array($finding, $found, true)) {
                    $found[] = $finding;
                }
            }
        }

        return $found;
    }

    /** Whether a text names a finding word, negated or not */
    private static function hasFinding(string $text, string $finding): bool
    {
        [, $folded] = self::words($text);
        foreach ($folded as $word) {
            if (self::is($word, $finding)) {
                return true;
            }
        }

        return false;
    }

    /** A word is a finding word by its stem; a short one only as itself */
    private static function is(string $word, string $finding): bool
    {
        return mb_strlen($finding) <= 4 ? $word === $finding : str_starts_with($word, RecallTerms::stem($finding));
    }

    /**
     * Taken back by a negation among the four words before it
     *
     * @param list<string> $folded
     */
    private static function negated(array $folded, int $i): bool
    {
        for ($j = max(0, $i - 4); $j < $i; $j++) {
            if (\in_array($folded[$j], self::NEGATIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: list<string>, 1: list<string>} the words as written, and folded
     */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $m);

        return [$m[0], array_map(RecallTerms::fold(...), $m[0])];
    }

    /** The start of a sentence as written, for the editor to find it by */
    private static function quote(string $sentence): string
    {
        return mb_strlen($sentence) <= self::QUOTE ? $sentence : rtrim(mb_substr($sentence, 0, self::QUOTE));
    }
}
