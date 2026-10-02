<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A report's exams (docs/FORMATS.md §12). Since phase 27 every report
 * keeps its exams in `exams:` — a single-exam report a list of one — and
 * the top-level `modality`, `region`, `exam_title`, `study_date`,
 * `device`, `accession` and `template` are derived from them on every save
 * (normalize()). Older reports keep the shape they were saved in: of()
 * reads both, for ever, and is what every reader uses.
 *
 * A report is multi-exam when it has two or more: its body's `##`
 * headings are then the exam boundaries, in order, and whatever is above
 * the first one is the shared head.
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

    /** Top-level key → the exam key it is derived from (or, in an old single-exam report, stood for) */
    public const DERIVED = ['exam_title' => 'title', 'modality' => 'modality', 'region' => 'region', 'study_date' => 'study_date', 'device' => 'device', 'accession' => 'accession', 'template' => 'template'];

    /**
     * Exam-level keys that are not derived back: they live on the exam
     * only. The modality-specific fields of conf/schema/{ct,mg,mr,us,xr}.json
     * but `indication`, which is the report's (ExamsTest checks this list
     * against the schema).
     */
    public const OWN = ['protocol', 'study_uid', 'pacs_accession', 'order_ref', 'contrast', 'phases', 'dlp', 'kv', 'views', 'density', 'birads', 'tomosynthesis', 'field_strength', 'sequences', 'doppler', 'projections', 'weight_bearing'];

    /** The order an exam's keys are written in */
    private const ORDER = ['title', 'modality', 'region', 'study_date', 'device', 'protocol', 'template', 'accession', 'study_uid', 'pacs_accession', 'order_ref'];

    /** An exam's value that, on a multi-exam report, the whole report may give every exam at once */
    private const SHARED = ['modality', 'study_date', 'device', 'protocol', 'order_ref', 'contrast', 'phases', 'dlp', 'kv', 'views', 'density', 'birads', 'tomosynthesis', 'field_strength', 'sequences', 'doppler', 'projections', 'weight_bearing'];

    /**
     * Every exam of a report, in either shape: its `exams:` entries (a value
     * the entry lacks taken from the top level where the whole report gave
     * it — an old multi-exam report's `modality`, `study_date`, `device`;
     * the first exam's `template`), or, for a report without `exams:`, one
     * exam made of its top-level fields. Values as written; empty ones left
     * out. Never empty for a report: one exam at least, possibly with
     * nothing in it.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return list<array<string, mixed>>
     */
    public static function of(array $frontmatter): array
    {
        $raw = $frontmatter['exams'] ?? null;
        $entries = \is_array($raw) && array_is_list($raw) && $raw !== [] ? array_map(static fn (mixed $e): array => \is_array($e) ? $e : [], $raw) : null;
        if ($entries === null) {
            $exam = [];
            foreach ([...self::DERIVED, ...array_combine(self::OWN, self::OWN)] as $top => $key) {
                if (!self::isEmpty($frontmatter[$top] ?? null)) {
                    $exam[$key] = $frontmatter[$top];
                }
            }

            return [$exam];
        }
        foreach ($entries as $n => $exam) {
            foreach (self::SHARED as $key) {
                if (self::isEmpty($exam[$key] ?? null) && !self::isEmpty($frontmatter[$key] ?? null)) {
                    $entries[$n][$key] = $frontmatter[$key];
                }
            }
            if ($n === 0 && self::isEmpty($exam['template'] ?? null) && !self::isEmpty($frontmatter['template'] ?? null)) {
                $entries[0]['template'] = $frontmatter['template'];
            }
            $entries[$n] = array_filter($entries[$n], static fn (mixed $v): bool => !self::isEmpty($v));
        }
        // Regions given for the whole report only (no exam has its own): every exam takes them
        if (!self::isEmpty($frontmatter['region'] ?? null) && array_filter($entries, static fn (array $e): bool => isset($e['region'])) === []) {
            foreach (array_keys($entries) as $n) {
                $entries[$n]['region'] = $frontmatter['region'];
            }
        }

        return $entries;
    }

    /**
     * The frontmatter in the phase 27 shape, as every save writes it: the
     * exams in `exams:`, and the derived top-level keys recomputed from
     * them. $before is the frontmatter being replaced (null for a new
     * page): a derived top-level key that changed since — raw YAML, the
     * form's report fields, a plugin filling a blank — is an edit, and goes
     * into the exam (the one exam; on a multi-exam report, a shared value
     * goes to every exam), so no edit is silently undone. An unchanged one
     * is recomputed. A report already in this shape comes back unchanged.
     *
     * @param array<string, mixed>  $frontmatter
     * @param ?array<string, mixed> $before
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $frontmatter, ?array $before = null): array
    {
        $before ??= [];
        $hadExams = \is_array($frontmatter['exams'] ?? null) && array_is_list($frontmatter['exams']) && $frontmatter['exams'] !== [];
        $exams = self::of($frontmatter);
        $changed = static fn (string $key): bool => self::canon($frontmatter[$key] ?? null) !== self::canon($before[$key] ?? null);

        if (\count($exams) === 1) {
            if ($hadExams) {
                // A one-exam list already: the top level wins only where it was edited
                $raw = array_map(static fn (mixed $e): array => \is_array($e) ? $e : [], $frontmatter['exams'])[0];
                $exam = array_filter($raw, static fn (mixed $v): bool => !self::isEmpty($v));
                foreach ([...self::DERIVED, ...array_combine(self::OWN, self::OWN)] as $top => $key) {
                    if ($changed($top)) {
                        $exam[$key] = $frontmatter[$top] ?? null;
                    } elseif (!self::isEmpty($frontmatter[$top] ?? null) && !isset($exam[$key])) {
                        $exam[$key] = $frontmatter[$top];
                    }
                }
                $exams = [$exam];
            }
        } else {
            foreach (self::SHARED as $key) {
                if (!$changed($key) || self::isEmpty($frontmatter[$key] ?? null)) {
                    continue;
                }
                $value = $frontmatter[$key];
                if ($key === 'modality') {
                    $list = self::listOf($value);
                    if (\count($list) !== 1) {
                        continue;
                    }
                    $value = $list[0];
                } elseif (\is_array($value)) {
                    continue;
                }
                foreach (array_keys($exams) as $n) {
                    $exams[$n][$key] = $value;
                }
            }
            if ($changed('template') && !self::isEmpty($frontmatter['template'] ?? null)) {
                $exams[0]['template'] = $frontmatter['template'];
            }
        }

        $clean = [];
        foreach ($exams as $exam) {
            $exam = array_filter($exam, static fn (mixed $v): bool => !self::isEmpty($v));
            if (isset($exam['modality'])) {
                $list = self::listOf($exam['modality']);
                $exam['modality'] = \count($list) === 1 ? $list[0] : $list;
            }
            if (isset($exam['region'])) {
                $exam['region'] = self::listOf($exam['region']);
            }
            // The usual keys first, in one order; anything else after, as it was
            $clean[] = array_merge(array_intersect_key(array_flip(self::ORDER), $exam), $exam);
        }

        $out = $frontmatter;
        // An exam's own key leaves the top level once an exam holds it — every
        // exam of a multi-exam report takes a shared one (of()); a study of a
        // multi-exam report written at the top level stays where it is
        foreach (self::OWN as $key) {
            if (\count($clean) === 1 || \in_array($key, self::SHARED, true)) {
                unset($out[$key]);
            }
        }
        $derived = self::derive($clean);
        foreach (array_keys(self::DERIVED) as $top) {
            if ($derived[$top] === null) {
                unset($out[$top]);
            } else {
                $out[$top] = $derived[$top];
            }
        }
        $out['exams'] = $clean;

        return $out;
    }

    /**
     * The top-level values the exams give: modality and region the union,
     * the exam title "A + B", the earliest exam date, the first exam's
     * device, accession and template. Null where none has one.
     *
     * @param list<array<string, mixed>> $exams
     *
     * @return array<string, mixed>
     */
    public static function derive(array $exams): array
    {
        $first = static function (string $key) use ($exams): mixed {
            foreach ($exams as $exam) {
                if (!self::isEmpty($exam[$key] ?? null)) {
                    return $exam[$key];
                }
            }

            return null;
        };
        $union = static function (string $key) use ($exams): ?array {
            $all = array_values(array_unique(array_merge([], ...array_map(static fn (array $e): array => self::listOf($e[$key] ?? null), $exams))));

            return $all !== [] ? $all : null;
        };
        $titles = array_values(array_filter(array_map(static fn (array $e): string => MetaText::text($e['title'] ?? null), $exams), static fn (string $t): bool => $t !== ''));
        $dates = array_values(array_filter(array_map(static fn (array $e): mixed => $e['study_date'] ?? null, $exams), static fn (mixed $d): bool => !self::isEmpty($d)));
        usort($dates, static fn (mixed $a, mixed $b): int => strcmp(self::dateKey($a), self::dateKey($b)));

        return [
            'exam_title' => $titles !== [] ? implode(' + ', $titles) : null,
            'modality' => $union('modality'),
            'region' => $union('region'),
            'study_date' => $dates[0] ?? null,
            'device' => $first('device'),
            'accession' => self::isEmpty($exams[0]['accession'] ?? null) ? null : $exams[0]['accession'],
            'template' => self::isEmpty($exams[0]['template'] ?? null) ? null : $exams[0]['template'],
        ];
    }

    /**
     * A single-exam report as one flat frontmatter: its exam's values at the
     * top level (the title as `exam_title`), where the top level has none —
     * the view a writer that fills blanks at the top level (a plugin's
     * prefill or link) works on; normalize() puts them back on save. A
     * multi-exam report comes back as it is.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return array<string, mixed>
     */
    public static function flat(array $frontmatter): array
    {
        $exams = self::of($frontmatter);
        if (\count($exams) !== 1) {
            return $frontmatter;
        }
        foreach ([...self::DERIVED, ...array_combine(self::OWN, self::OWN)] as $top => $key) {
            if (self::isEmpty($frontmatter[$top] ?? null) && !self::isEmpty($exams[0][$key] ?? null)) {
                $frontmatter[$top] = $exams[0][$key];
            }
        }

        return $frontmatter;
    }

    /** @return list<string> a scalar or a list as a list of non-empty strings */
    public static function listOf(mixed $value): array
    {
        $items = \is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(static fn (mixed $v): string => \is_scalar($v) ? trim((string) $v) : '', $items), static fn (string $s): bool => $s !== ''));
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** A value compared for "changed": a scalar list and its one item alike, empty as nothing */
    private static function canon(mixed $value): string
    {
        if (self::isEmpty($value)) {
            return '';
        }
        if (\is_array($value) && array_is_list($value) && \count($value) === 1 && \is_scalar($value[0])) {
            $value = $value[0];
        }

        return \is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    /** A sortable form of a stored date: a YAML date read as a timestamp, or the text */
    private static function dateKey(mixed $value): string
    {
        return \is_int($value) ? gmdate('Y-m-d\\TH:i:s', $value) : MetaText::text($value);
    }

    /**
     * A multi-exam report's exams, each with a title, regions and an
     * accession ('' or [] when unset). Empty for a single-exam report — one
     * declared exam or none.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return list<array{title: string, region: list<string>, accession: string}>
     */
    public static function declared(array $frontmatter): array
    {
        $raw = $frontmatter['exams'] ?? null;
        // One exam is a single-exam report, whichever shape it is in (phase 27)
        if (!\is_array($raw) || !array_is_list($raw) || \count($raw) < 2) {
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
