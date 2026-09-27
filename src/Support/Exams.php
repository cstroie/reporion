<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A multi-exam report (roadmap phase 12, docs/FORMATS.md §12): one file,
 * one frontmatter, several exams — both knees, three spine regions. A
 * report is multi-exam only when its frontmatter declares `exams:`; its
 * body's `##` headings are then the exam boundaries, in order, and
 * whatever is above the first one is the shared head.
 *
 * The boundary rule is a line rule, not a parse, so that the editor island
 * (assets/js/editor-exams.js) splits a document exactly as this does: a
 * line of up to three spaces, `##`, then a space, a tab or the line's end,
 * outside a fenced code block. `###` and deeper never split.
 */
final class Exams
{
    private const BOUNDARY = '/^ {0,3}##(?:[ \t]|$)/';
    private const SUBHEADING = '/^ {0,3}###[ \t]+(.+?)[ \t#]*$/u';
    private const FENCE = '/^ {0,3}(`{3,}|~{3,})/';
    private const CONCLUSION = '/^concluzi[ei]\b/u';

    /**
     * The declared exams, each with a title, regions and an accession ('' or
     * [] when unset). Empty for a report that declares none.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return list<array{title: string, region: list<string>, accession: string}>
     */
    public static function declared(array $frontmatter): array
    {
        $raw = $frontmatter['exams'] ?? null;
        if (!\is_array($raw) || !array_is_list($raw)) {
            return [];
        }
        $exams = [];
        foreach ($raw as $entry) {
            $entry = \is_array($entry) ? $entry : [];
            $region = $entry['region'] ?? [];
            $exams[] = [
                'title' => MetaText::text($entry['title'] ?? null),
                'region' => array_values(array_filter(\is_array($region) ? $region : [$region], static fn (mixed $r): bool => \is_string($r) && $r !== '')),
                'accession' => MetaText::text($entry['accession'] ?? null),
            ];
        }

        return $exams;
    }

    /** @param array<string, mixed> $frontmatter */
    public static function isMulti(array $frontmatter): bool
    {
        return self::declared($frontmatter) !== [];
    }

    /**
     * The body cut at its exam boundaries: the shared head, then each exam
     * from its `##` line up to the next. head . implode(parts) is the body,
     * byte for byte.
     *
     * @return array{head: string, parts: list<array{title: string, text: string}>}
     */
    public static function split(string $body): array
    {
        $lines = preg_split('/(?<=\n)/', $body) ?: [];
        $head = '';
        $parts = [];
        $fence = null;
        foreach ($lines as $line) {
            $bare = rtrim($line, "\r\n");
            if ($fence !== null) {
                if (preg_match(self::FENCE, $bare, $m) === 1 && $m[1][0] === $fence[0] && \strlen($m[1]) >= \strlen($fence) && trim(substr(ltrim($bare), \strlen($m[1]))) === '') {
                    $fence = null;
                }
            } elseif (preg_match(self::FENCE, $bare, $m) === 1) {
                $fence = $m[1];
            } elseif (preg_match(self::BOUNDARY, $bare) === 1) {
                $parts[] = ['title' => self::headingText($bare), 'text' => ''];
            }
            if ($parts === []) {
                $head .= $line;
            } else {
                $parts[\count($parts) - 1]['text'] .= $line;
            }
        }

        return ['head' => $head, 'parts' => $parts];
    }

    /**
     * What stops a multi-exam report from being signed (D7: never from
     * being saved), as dotted codes the sign screen labels:
     * `exams.count` when the body's exams and the list differ in number,
     * `exams.{n}.title` for an exam with no title, `exams.{n}.conclusion`
     * for an exam with no `### Concluzii` directly under it. Nothing for a
     * report that declares no exams.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return list<string>
     */
    public static function problems(array $frontmatter, string $body): array
    {
        $declared = self::declared($frontmatter);
        if ($declared === []) {
            return [];
        }
        $parts = self::split($body)['parts'];
        $problems = \count($parts) !== \count($declared) ? ['exams.count'] : [];
        foreach ($declared as $i => $exam) {
            $n = $i + 1;
            if ($exam['title'] === '' && ($parts[$i]['title'] ?? '') === '') {
                $problems[] = 'exams.' . $n . '.title';
            }
            if (isset($parts[$i]) && !self::hasConclusion($parts[$i]['text'])) {
                $problems[] = 'exams.' . $n . '.conclusion';
            }
        }

        return $problems;
    }

    /**
     * Every exam's accession, in order, skipping unset ones — the numbers a
     * multi-exam report holds (D20: one per exam).
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return list<string>
     */
    public static function accessions(array $frontmatter): array
    {
        return array_values(array_filter(array_column(self::declared($frontmatter), 'accession'), static fn (string $a): bool => $a !== ''));
    }

    /** Whether an exam's text has a `### Concluzii` (or Concluzie, any case) of its own */
    private static function hasConclusion(string $text): bool
    {
        $fence = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if ($fence !== null) {
                if (preg_match(self::FENCE, $line, $m) === 1 && $m[1][0] === $fence[0]) {
                    $fence = null;
                }
                continue;
            }
            if (preg_match(self::FENCE, $line, $m) === 1) {
                $fence = $m[1];
                continue;
            }
            if (preg_match(self::SUBHEADING, $line, $m) === 1 && !str_starts_with(ltrim($line), '####')
                && preg_match(self::CONCLUSION, self::fold($m[1])) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function headingText(string $line): string
    {
        return trim((string) preg_replace('/(?:^|[ \t])#+[ \t]*$/', '', (string) preg_replace('/^ {0,3}##(?:[ \t]+|$)/', '', $line)));
    }

    /** Lower case, Romanian diacritics folded (Concluzie, CONCLUZII) */
    private static function fold(string $text): string
    {
        return strtr(mb_strtolower(trim($text)), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    }
}
