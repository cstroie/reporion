<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The pre-sign check that needs no assistant (roadmap phase 34a): a wrong
 * side is among the costliest slips in a report, and the words for it are
 * few. Per exam (Support\Exams::split(); a single-exam report is one), the
 * sides named in its title, the indication, the description and the
 * conclusion are compared:
 *
 * - `title_vs_conclusion` — the exam's title names one side, the
 *   conclusion only the other;
 * - `indication_vs_conclusion` — the same for the indication (its section,
 *   else the frontmatter `indication`);
 * - `conclusion_not_described` — the conclusion names a side the
 *   description, which names sides, never does;
 * - `no_conclusion` — the exam has no conclusion section at all.
 *
 * Warnings, never a block (D7's spirit). Romanian and English, diacritics
 * aside: stâng/stânga/stângă/stg, drept/dreaptă/dreapta, left/right;
 * bilateral/ambele/both name both sides. "dr." is left alone (a doctor).
 * Pure: codes and their arguments out, the screen words them.
 */
final class Laterality
{
    public const LEFT = 'left';
    public const RIGHT = 'right';

    /**
     * @param array<string, mixed> $frontmatter
     *
     * @return list<array{code: string, exam: string, sides: list<string>, other: list<string>}>
     */
    public static function check(string $body, array $frontmatter): array
    {
        $split = Exams::split(ReportName::withoutNameHeading($body, $frontmatter));
        $parts = $split['parts'] !== [] ? $split['parts'] : [['title' => MetaText::text($frontmatter['exam_title'] ?? null), 'text' => $split['head']]];
        $headSections = self::sections($split['parts'] !== [] ? $split['head'] : '');
        $indicationAll = trim(($headSections['indication'] ?? '') . "\n" . MetaText::text($frontmatter['indication'] ?? null));

        $warnings = [];
        foreach ($parts as $part) {
            $sections = self::sections($part['text']);
            $exam = trim($part['title']) !== '' ? trim($part['title']) : MetaText::text($frontmatter['exam_title'] ?? null);
            if (!isset($sections['conclusion'])) {
                $warnings[] = ['code' => 'no_conclusion', 'exam' => $exam, 'sides' => [], 'other' => []];
                continue;
            }
            $conclusion = self::sides($sections['conclusion']);
            if ($conclusion === []) {
                continue;
            }
            $title = self::sides($exam);
            if (self::opposite($title, $conclusion)) {
                $warnings[] = ['code' => 'title_vs_conclusion', 'exam' => $exam, 'sides' => $title, 'other' => $conclusion];
            }
            $indication = self::sides(trim(($sections['indication'] ?? '') . "\n" . $indicationAll));
            if (self::opposite($indication, $conclusion)) {
                $warnings[] = ['code' => 'indication_vs_conclusion', 'exam' => $exam, 'sides' => $indication, 'other' => $conclusion];
            }
            $description = self::sides($sections['description'] ?? '');
            $missing = array_values(array_diff($conclusion, $description));
            if ($description !== [] && $missing !== []) {
                $warnings[] = ['code' => 'conclusion_not_described', 'exam' => $exam, 'sides' => $missing, 'other' => $description];
            }
        }

        return $warnings;
    }

    /**
     * The sides a text names: left, right, both (bilateral names both)
     *
     * @return list<string>
     */
    public static function sides(string $text): array
    {
        $folded = self::fold($text);
        $sides = [];
        if (preg_match('/\b(bilateral\w*|ambele|ambii|both|bilaterally)\b/u', $folded) === 1) {
            return [self::LEFT, self::RIGHT];
        }
        if (preg_match('/\b(stang\w*|sting\w*|stg|left)\b/u', $folded) === 1) {
            $sides[] = self::LEFT;
        }
        // drept…/dreapt… — but not dreptunghi(c) (rectangular)
        if (preg_match('/\b(drept(?!unghi)\w*|dreapt\w*|right)\b/u', $folded) === 1) {
            $sides[] = self::RIGHT;
        }

        return $sides;
    }

    /** One side on each side, and they differ: a single left against a single right */
    private static function opposite(array $a, array $b): bool
    {
        return \count($a) === 1 && \count($b) === 1 && $a !== $b;
    }

    /**
     * A part's text by kind of section: `indication`, `conclusion`, and
     * everything else as `description`; a heading's kind lasts to the next
     * heading of the same or a higher level
     *
     * @return array<string, string>
     */
    private static function sections(string $text): array
    {
        $out = [];
        $kind = 'description';
        $level = 99;
        $fence = false;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s{0,3}(```|~~~)/', $line) === 1) {
                $fence = !$fence;
            }
            if (!$fence && preg_match('/^ {0,3}(#{1,6})[ \t]+(.+?)[ \t#]*$/u', $line, $m) === 1) {
                $l = \strlen($m[1]);
                $heading = self::fold(trim($m[2], " \t:.*_"));
                if (str_starts_with($heading, 'conclu')) {
                    [$kind, $level] = ['conclusion', $l];
                } elseif (str_starts_with($heading, 'indica') || str_starts_with($heading, 'motiv')) {
                    [$kind, $level] = ['indication', $l];
                } elseif ($l <= $level) {
                    [$kind, $level] = ['description', $l <= 2 ? 99 : $l];
                }
                if ($l <= 2) {
                    continue; // an exam's own heading is its title, not its text
                }
                $out[$kind] = $out[$kind] ?? '';
                continue;
            }
            $out[$kind] = ($out[$kind] ?? '') . $line . "\n";
        }

        return $out;
    }

    private static function fold(string $text): string
    {
        return strtr(mb_strtolower($text), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    }
}
