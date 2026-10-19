<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * What the `ask-expand` prompt answers (D53): the question as a standalone
 * one, then the words a report would use for it, one per line. Parsed
 * defensively: a model may number its lines, quote them or add a lead-in. Pure.
 */
final class AskExpansion
{
    /** Most words the expansion adds to a search */
    public const MAX_TERMS = 20;

    /** Longest standalone question kept, as the question itself */
    public const MAX_QUESTION = 1_000;

    /**
     * @return array{question: string, terms: list<string>} question '' when the answer has none (keywords only)
     */
    public static function parse(string $answer): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', trim($answer)) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-*•]+|\d+[.)])\s*/u', '', trim($line)), " \t\"'«»„”`");
            if ($line !== '' && preg_match('/^(?:keywords|cuvinte|words|termeni)\s*:\s*$/iu', $line) !== 1) {
                $lines[] = $line;
            }
        }
        $question = '';
        if ($lines !== []) {
            $first = (string) preg_replace('/^(?:question|întrebare|intrebare)\s*:\s*/iu', '', $lines[0]);
            // A question: says so, or is a sentence; a keyword line is a word or two
            if (str_ends_with($first, '?') || preg_match('/^(?:question|întrebare|intrebare)\s*:/iu', $lines[0]) === 1 || str_word_count($first, 0, 'ăâîșşțţĂÂÎȘŞȚŢ') >= 4) {
                $question = mb_substr($first, 0, self::MAX_QUESTION);
                array_shift($lines);
            }
        }
        $terms = [];
        foreach ($lines as $line) {
            foreach (RecallTerms::ofLine($line, 6) as $term) {
                $terms[$term] = true;
            }
        }

        return ['question' => $question, 'terms' => \array_slice(array_keys($terms), 0, self::MAX_TERMS)];
    }
}
