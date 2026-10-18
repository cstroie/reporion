<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The words of a report that say what it is about, and the passages of a
 * report that say it: shared by the assistant's {snippets}
 * (Service\Ai\FtsExamples) and the editor's Recall panel (Service\Recall,
 * D48). Pure: text in, words and passages out; no stemmer (D28), only a
 * stem by prefix, so that `leziuni` finds `leziunea`.
 */
final class RecallTerms
{
    /** Words too common in a report to say anything about it */
    public const STOP = ['aspect', 'normal', 'normala', 'normale', 'dimensiuni', 'semnal', 'structuri', 'nivelul', 'fara', 'pentru', 'dreapta', 'stanga', 'modificari', 'examinare', 'prezinta', 'bilateral', 'limite', 'nivel', 'cazul', 'sunt', 'este'];

    /** Short words a line's terms leave out besides STOP: four letters and no meaning of their own */
    private const LINE_STOP = ['care', 'cele', 'cate', 'catre', 'doua', 'acest', 'aceasta', 'acesta', 'aceste', 'precum', 'avand', 'unei', 'unui', 'unor', 'prin', 'spre', 'dupa', 'intre', 'mult', 'multe', 'multiple', 'putin', 'foarte', 'usor', 'usoara', 'discret', 'discreta', 'eventual', 'posibil', 'probabil', 'aproximativ', 'imagine', 'imagini', 'examen', 'zona', 'nivelul', 'regiune', 'regiunea'];

    /** Imaging words shorter than four letters that still say something */
    private const SHORT = ['t1', 't2', 'pd', 'dwi', 'adc', 'swi', 'gre', 'tof', 'cor', 'sag', 'ax', 'fs', 'hu', 'iv', 'mip', 'mpr', 'flair', 'stir'];

    /** Function words a phrase may keep between two words: `efect de masă` */
    private const JOIN = ['de', 'in', 'la', 'cu', 'pe', 'si', 'al', 'a'];

    /** The most a passage holds before it is cut by sentences */
    private const PASSAGE_CHARS = 500;

    /** Lower case without diacritics: how words are compared */
    public static function fold(string $text): string
    {
        return strtr(mb_strtolower(trim($text)), [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ô' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ű' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    /**
     * The words that matter in a text: letters only, $min or more, folded,
     * the most frequent first, STOP left out ({snippets}' search words).
     *
     * @return list<string>
     */
    public static function frequent(string $text, int $min = 5, int $max = 12): array
    {
        preg_match_all('/\p{L}{' . $min . ',}/u', $text, $m);
        $counts = [];
        foreach ($m[0] as $word) {
            $folded = self::fold($word);
            if (!\in_array($folded, self::STOP, true)) {
                $counts[$folded] = ($counts[$folded] ?? 0) + 1;
            }
        }
        arsort($counts);

        return \array_slice(array_map('strval', array_keys($counts)), 0, $max);
    }

    /**
     * The words of one line the doctor wrote: folded, in their order, each
     * once: four letters or more, or a short imaging word (T2, DWI, FLAIR).
     *
     * @return list<string>
     */
    public static function ofLine(string $line, int $max = 8): array
    {
        $terms = [];
        foreach (self::words($line) as $word) {
            $folded = self::fold($word);
            if (self::meaningful($folded) && !\in_array($folded, $terms, true)) {
                $terms[] = $folded;
            }
        }

        return \array_slice($terms, 0, $max);
    }

    /**
     * What a word is matched by: its first letters, so a Romanian ending
     * does not matter (`leziuni` → `leziu`): no stemmer (D28)
     */
    public static function stem(string $folded): string
    {
        $length = mb_strlen($folded);

        return $length <= 4 ? $folded : mb_substr($folded, 0, max(4, $length - 2));
    }

    /**
     * The terms of $terms that $text has, by stem
     *
     * @param list<string> $terms folded
     *
     * @return list<string>
     */
    public static function matched(array $terms, string $text): array
    {
        $words = array_map(self::fold(...), self::words($text));
        $found = [];
        foreach ($terms as $term) {
            $stem = self::stem($term);
            foreach ($words as $word) {
                if (self::hasStem($word, $stem, $term)) {
                    $found[] = $term;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * A report's `###` sections, heading included, and the text before the
     * first one: without the `#`/`##` headings that title the report.
     *
     * @return list<string>
     */
    public static function sections(string $body): array
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

    /**
     * A report's passages: each paragraph or list item of its `###`
     * sections, with the heading it is under; a long one cut into a few
     * sentences at a time. Never the `#`/`##` headings (the patient's
     * name, the exam).
     *
     * @return list<array{heading: string, text: string}>
     */
    public static function passages(string $body): array
    {
        $passages = [];
        foreach (self::sections($body) as $section) {
            $heading = '';
            if (preg_match('/^###[ \t]+(.*)\R?/', $section, $m) === 1) {
                $heading = trim($m[1]);
                $section = (string) substr($section, \strlen($m[0]));
            }
            // A paragraph ends at a blank line; a list item is one too
            $blocks = preg_split('/\R[ \t]*\R+|\R(?=[ \t]*(?:[-*+]|\d+[.)])[ \t])/', $section) ?: [];
            foreach ($blocks as $block) {
                $text = trim((string) preg_replace('/^[ \t]*(?:[-*+]|\d+[.)])[ \t]+/', '', trim($block)));
                $text = trim((string) preg_replace('/\s+/u', ' ', $text));
                if ($text === '' || str_starts_with($text, '#')) {
                    continue;
                }
                foreach (self::cut($text) as $piece) {
                    $passages[] = ['heading' => $heading, 'text' => $piece];
                }
            }
        }

        return $passages;
    }

    /**
     * What several of $passages say that $written does not: words and short
     * phrases (`efect de masă`) found in at least $min of them, by stem,
     * none of $terms, none a common word; the most shared first, each as
     * it was first written.
     *
     * @param list<string> $passages
     * @param list<string> $terms the line's own, folded
     *
     * @return list<string>
     */
    public static function missing(array $passages, string $written, array $terms, int $min = 2, int $max = 8): array
    {
        $have = array_map(self::fold(...), self::words($written));
        $haveStem = static function (string $folded) use ($have): bool {
            $stem = self::stem($folded);
            foreach ($have as $word) {
                if (self::hasStem($word, $stem, $folded)) {
                    return true;
                }
            }

            return false;
        };
        $termStems = array_map(self::stem(...), $terms);

        /** @var array<string, array{count: int, surface: string, words: list<string>}> $seen by folded stem key */
        $seen = [];
        foreach ($passages as $passage) {
            $inThis = [];
            foreach (self::units($passage) as [$surface, $folded]) {
                $key = implode(' ', array_map(self::stem(...), $folded));
                if (isset($inThis[$key])) {
                    continue;
                }
                $inThis[$key] = true;
                $seen[$key] ??= ['count' => 0, 'surface' => $surface, 'words' => $folded];
                $seen[$key]['count']++;
            }
        }

        $chips = [];
        foreach ($seen as $key => $unit) {
            if ($unit['count'] < $min) {
                continue;
            }
            $new = array_filter($unit['words'], static fn (string $w): bool => !\in_array(self::stem($w), $termStems, true) && !$haveStem($w));
            // A phrase is missing only when none of its words is written; a word, when it is not
            if (\count($new) !== \count($unit['words'])) {
                continue;
            }
            $chips[$key] = $unit;
        }
        // A phrase as often seen as its words says it better: the words go
        foreach ($chips as $key => $unit) {
            if (\count($unit['words']) < 2) {
                continue;
            }
            foreach ($unit['words'] as $word) {
                $single = self::stem($word);
                if (isset($chips[$single]) && $chips[$single]['count'] <= $unit['count']) {
                    unset($chips[$single]);
                }
            }
        }
        uasort($chips, static fn (array $a, array $b): int => [$b['count'], \count($b['words'])] <=> [$a['count'], \count($a['words'])]);

        return \array_slice(array_values(array_map(static fn (array $u): string => $u['surface'], $chips)), 0, $max);
    }

    /**
     * The text with each word one of $terms finds marked, escaped for HTML
     *
     * @param list<string> $terms folded
     */
    public static function highlight(string $text, array $terms): string
    {
        $parts = preg_split('/([\p{L}\p{N}]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $html = '';
        foreach ($parts as $i => $part) {
            $escaped = htmlspecialchars($part, ENT_QUOTES);
            $hit = false;
            if ($i % 2 === 1) {
                $folded = self::fold($part);
                foreach ($terms as $term) {
                    if (self::hasStem($folded, self::stem($term), $term)) {
                        $hit = true;
                        break;
                    }
                }
            }
            $html .= $hit ? '<mark>' . $escaped . '</mark>' : $escaped;
        }

        return $html;
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $m);

        return $m[0];
    }

    private static function meaningful(string $folded): bool
    {
        if (\in_array($folded, self::SHORT, true)) {
            return true;
        }

        return preg_match('/^\p{L}{4,}$/u', $folded) === 1
            && !\in_array($folded, self::STOP, true)
            && !\in_array($folded, self::LINE_STOP, true);
    }

    /** A short term matches only itself; a longer one any word starting with its stem */
    private static function hasStem(string $word, string $stem, string $term): bool
    {
        return mb_strlen($term) <= 4 ? $word === $term : str_starts_with($word, $stem);
    }

    /**
     * The words and phrases of a passage: each meaningful word, and each
     * pair of them next to each other or with one JOIN word between
     *
     * @return list<array{0: string, 1: list<string>}> [as written, folded words]
     */
    private static function units(string $text): array
    {
        $words = self::words($text);
        $units = [];
        $previous = null;
        $joiner = null;
        foreach ($words as $word) {
            $folded = self::fold($word);
            if (self::meaningful($folded)) {
                $units[] = [$word, [$folded]];
                if ($previous !== null) {
                    $units[] = [$previous[0] . ($joiner !== null ? ' ' . $joiner : '') . ' ' . $word, [$previous[1], $folded]];
                }
                $previous = [$word, $folded];
                $joiner = null;
            } elseif ($previous !== null && $joiner === null && \in_array($folded, self::JOIN, true)) {
                $joiner = $word;
            } else {
                $previous = null;
                $joiner = null;
            }
        }

        return $units;
    }

    /**
     * A long paragraph in pieces of whole sentences, each under
     * PASSAGE_CHARS where a sentence allows
     *
     * @return list<string>
     */
    private static function cut(string $text): array
    {
        if (mb_strlen($text) <= self::PASSAGE_CHARS) {
            return [$text];
        }
        $pieces = [];
        $current = '';
        foreach (preg_split('/(?<=[.;!?])\s+(?=\p{Lu}|\d)/u', $text) ?: [] as $sentence) {
            if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($sentence) > self::PASSAGE_CHARS) {
                $pieces[] = $current;
                $current = '';
            }
            $current = $current === '' ? $sentence : $current . ' ' . $sentence;
        }
        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
