<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A report's exams (docs/FORMATS.md §12). Every report keeps its exams in
 * `exams:`: a single-exam report a list of one. What the exams share is
 * the report's own: `modality`, `device` and `study_date` (the day);
 * what each has is its own: title, time, region, template, accession,
 * study, protocol. Nothing is derived into the top level (2026-10-15).
 * Older reports keep the shape they were saved in: of() and report() read
 * every shape, for ever, and are what every reader uses.
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

    /**
     * Report-level keys: one value for the whole report, whatever its
     * number of exams (2026-10-15). `study_date` is the day only; each
     * exam has its own `time`.
     */
    public const SHARED = ['modality', 'device', 'study_date'];

    /**
     * Top-level key an older report carried for its one exam (or, derived,
     * for all of them) → the exam key. Never written any more; read and
     * folded into the exam (of(), normalize()).
     */
    public const LEGACY = ['exam_title' => 'title', 'region' => 'region', 'accession' => 'accession', 'template' => 'template'];

    /**
     * Exam-level keys that are not derived back: they live on the exam
     * only. The modality-specific fields of resources/schema/{ct,mg,mr,us,xr}.json
     * but `indication`, which is the report's (ExamsTest checks this list
     * against the schema).
     */
    public const OWN = ['protocol', 'study_uid', 'pacs_accession', 'order_ref', 'contrast', 'phases', 'dlp', 'kv', 'views', 'density', 'birads', 'tomosynthesis', 'field_strength', 'sequences', 'doppler', 'projections', 'weight_bearing'];

    /** The order an exam's keys are written in */
    private const ORDER = ['title', 'time', 'region', 'template', 'protocol', 'accession', 'study_uid', 'pacs_accession', 'order_ref'];

    /**
     * An exam's own key that an older multi-exam report gave once, at the
     * top level, for every exam (a study's UID and PACS number never: they
     * are one study's)
     */
    private const LIFTED = ['protocol', 'order_ref', 'contrast', 'phases', 'dlp', 'kv', 'views', 'density', 'birads', 'tomosynthesis', 'field_strength', 'sequences', 'doppler', 'projections', 'weight_bearing'];

    /**
     * The report-level values, whichever shape the frontmatter is in:
     * `modality` (a list), `device`, the `date` (Y-m-d), the earliest exam
     * `time` (H:i) and the `moment` the two make (the index's study_date:
     * the day, or the day and the earliest time). Older reports gave each
     * exam its own modality, device and date-time, and a flat one its
     * date-time at the top: they are read here, the top level first.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return array{modality: list<string>, device: string, date: string, time: string, moment: string}
     */
    public static function report(array $frontmatter): array
    {
        $raw = $frontmatter['exams'] ?? null;
        $entries = \is_array($raw) && array_is_list($raw) ? array_values(array_filter($raw, 'is_array')) : [];

        $modality = self::listOf($frontmatter['modality'] ?? null);
        if ($modality === []) {
            $modality = array_values(array_unique(array_merge([], ...array_map(static fn (array $e): array => self::listOf($e['modality'] ?? null), $entries))));
        }
        $device = MetaText::text($frontmatter['device'] ?? null);
        if ($device === '') {
            foreach ($entries as $e) {
                if (($device = MetaText::text($e['device'] ?? null)) !== '') {
                    break;
                }
            }
        }

        [$date, $time] = self::moment($frontmatter['study_date'] ?? null);
        $times = $time !== '' ? [$time] : [];
        $dates = $date !== '' ? [$date] : [];
        foreach ($entries as $e) {
            [$d, $t] = self::moment($e['study_date'] ?? null);
            $t = MetaText::text($e['time'] ?? null) ?: $t;
            if ($d !== '') {
                $dates[] = $d;
            }
            if ($t !== '') {
                $times[] = $t;
            }
        }
        // The top level's day wins; else the earliest an exam gave
        if ($date === '' && $dates !== []) {
            sort($dates);
            $date = $dates[0];
        }
        sort($times);
        $time = $times[0] ?? '';

        return [
            'modality' => $modality,
            'device' => $device,
            'date' => $date,
            'time' => $time,
            'moment' => $date === '' ? '' : $date . ($time !== '' ? 'T' . $time . ':00' : ''),
        ];
    }

    /**
     * The day and the time of a stored date or date-time (a string, or an
     * unquoted YAML date read as a timestamp): ['Y-m-d', 'H:i'], '' for a
     * part it has not. A value that is not a date comes back as the day, as written.
     *
     * @return array{0: string, 1: string}
     */
    public static function moment(mixed $value): array
    {
        if (self::isEmpty($value)) {
            return ['', ''];
        }
        [$day, $time] = array_pad(explode('|', MetaText::dateTime($value, MetaText::DATE, '|H:i'), 2), 2, '');

        return [$day, $time];
    }

    /**
     * Every exam of a report in the current shape (title, time, region,
     * template, accession, study_uid, pacs_accession, order_ref, protocol and
     * the modality's fields; empty values left out), whichever shape it is
     * stored in. The shared values (modality, device, day) are not in the
     * exams: report() has them. Older shapes are read here, for ever: an
     * exam's own date-time becomes its `time`; a report without `exams:` is
     * one exam made of its top-level fields; a report of one exam takes the
     * top-level title, region, accession and template an older save kept
     * there; a report of several never takes a template from the top level
     * (it was the first exam's, and is no exam's own). Never empty for a
     * report: one exam at least, possibly with nothing in it.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return list<array<string, mixed>>
     */
    public static function of(array $frontmatter): array
    {
        $report = self::report($frontmatter);
        $raw = $frontmatter['exams'] ?? null;
        $entries = \is_array($raw) && array_is_list($raw) && $raw !== [] ? array_map(static fn (mixed $e): array => \is_array($e) ? $e : [], $raw) : null;
        if ($entries === null) {
            $exam = [];
            foreach ([...self::LEGACY, ...array_combine(self::OWN, self::OWN)] as $top => $key) {
                if (!self::isEmpty($frontmatter[$top] ?? null)) {
                    $exam[$key] = $frontmatter[$top];
                }
            }
            if ($report['time'] !== '') {
                $exam['time'] = $report['time'];
            }

            return [$exam];
        }
        $single = \count($entries) === 1;
        foreach ($entries as $n => $exam) {
            if (self::isEmpty($exam['time'] ?? null)) {
                $time = self::moment($exam['study_date'] ?? null)[1];
                $exam['time'] = $time !== '' ? $time : ($single ? $report['time'] : '');
            }
            unset($exam['study_date'], $exam['modality']);
            if (MetaText::text($exam['device'] ?? null) === $report['device']) {
                unset($exam['device']);
            }
            foreach (self::LIFTED as $key) {
                if (self::isEmpty($exam[$key] ?? null) && !self::isEmpty($frontmatter[$key] ?? null)) {
                    $exam[$key] = $frontmatter[$key];
                }
            }
            if ($single) {
                foreach (self::LEGACY as $top => $key) {
                    if (self::isEmpty($exam[$key] ?? null) && !self::isEmpty($frontmatter[$top] ?? null)) {
                        $exam[$key] = $frontmatter[$top];
                    }
                }
            }
            $entries[$n] = array_filter($exam, static fn (mixed $v): bool => !self::isEmpty($v));
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
     * The frontmatter in the current shape, as every save writes it: the
     * exams in `exams:` (title, time, region, template, accession, study and
     * the modality's fields on each), the report's `modality`, `device` and
     * `study_date` (the day) at the top level, and nothing derived: no
     * top-level `exam_title`, `region`, `accession` or `template`.
     * $before is the frontmatter being replaced (null for a new page): a
     * top-level value of an older shape that changed since: raw YAML, the
     * form's report fields, a plugin filling a blank: is an edit, and goes
     * into the exam (the first one, on a report of several), so no edit is
     * silently undone. An unchanged one is dropped. A report already in this
     * shape comes back unchanged.
     *
     * @param array<string, mixed>  $frontmatter
     * @param ?array<string, mixed> $before
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $frontmatter, ?array $before = null): array
    {
        $hadExams = \is_array($frontmatter['exams'] ?? null) && array_is_list($frontmatter['exams']) && $frontmatter['exams'] !== [];
        $exams = self::of($frontmatter);
        $creating = $before === null;
        $before ??= [];
        $changed = static fn (string $key): bool => !$creating && self::canon($frontmatter[$key] ?? null) !== self::canon($before[$key] ?? null);
        $single = \count($exams) === 1;

        if ($hadExams) {
            // A one-exam list already: the exam wins, but for a top-level value edited since the
            // revision before: a legacy one changed, an exam's own one set
            if ($single) {
                $exam = $exams[0];
                // A key present and changed is an edit; one absent is not: a writer of the current shape has none
                // at the top level, and saving it over an older revision must not clear what the exam holds
                foreach (self::LEGACY as $top => $key) {
                    if (!self::isEmpty($frontmatter[$top] ?? null) && $changed($top)) {
                        $exam[$key] = $frontmatter[$top];
                    }
                }
                foreach (self::OWN as $key) {
                    if (!self::isEmpty($frontmatter[$key] ?? null) && $changed($key)) {
                        $exam[$key] = $frontmatter[$key];
                    }
                }
                $exams = [$exam];
            } elseif ($changed('template') && !self::isEmpty($frontmatter['template'] ?? null)) {
                $exams[0]['template'] = $frontmatter['template'];
            }
        }
        // A date-time written at the top level (a plugin filling the day's time): its time is the first exam's
        if ($changed('study_date')) {
            $time = self::moment($frontmatter['study_date'] ?? null)[1];
            if ($time !== '') {
                $exams[0]['time'] = $time;
            }
        }

        $clean = [];
        foreach ($exams as $exam) {
            $exam = array_filter($exam, static fn (mixed $v): bool => !self::isEmpty($v));
            if (isset($exam['region'])) {
                $exam['region'] = self::listOf($exam['region']);
            }
            // The usual keys first, in one order; anything else after, as it was
            $clean[] = array_merge(array_intersect_key(array_flip(self::ORDER), $exam), $exam);
        }

        $out = $frontmatter;
        // An exam's own key leaves the top level once an exam holds it
        foreach (self::OWN as $key) {
            if (\count($clean) === 1 || \in_array($key, self::LIFTED, true)) {
                unset($out[$key]);
            }
        }
        foreach (array_keys(self::LEGACY) as $top) {
            unset($out[$top]);
        }
        $report = self::report($frontmatter);
        // An older revision kept the report's modality, device or day on its exams only, and a save that
        // rewrites the exams (the editor's cards) has dropped them from there: they are the report's now
        if (!$creating) {
            $was = self::report($before);
            foreach (['modality' => 'modality', 'device' => 'device', 'study_date' => 'date'] as $key => $part) {
                if (self::isEmpty($report[$part]) && self::isEmpty($frontmatter[$key] ?? null) && self::isEmpty($before[$key] ?? null)) {
                    $report[$part] = $was[$part];
                }
            }
        }
        foreach (['modality' => $report['modality'], 'device' => $report['device'], 'study_date' => $report['date']] as $key => $value) {
            if ($value === [] || $value === '') {
                unset($out[$key]);
            } else {
                $out[$key] = $value;
            }
        }
        $out['exams'] = $clean;

        return $out;
    }


    /** @param array<string, mixed> $frontmatter */
    public static function title(array $frontmatter): string
    {
        return implode(' + ', array_values(array_filter(array_map(static fn (array $e): string => MetaText::text($e['title'] ?? null), self::of($frontmatter)), static fn (string $t): bool => $t !== '')));
    }

    /**
     * Every region of every exam, once each
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return list<string>
     */
    public static function regions(array $frontmatter): array
    {
        return array_values(array_unique(array_merge([], ...array_map(static fn (array $e): array => self::listOf($e['region'] ?? null), self::of($frontmatter)))));
    }

    /**
     * A single-exam report as one flat frontmatter: its exam's values at the
     * top level (the title as `exam_title`), where the top level has none:
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
        foreach ([...self::LEGACY, ...array_combine(self::OWN, self::OWN)] as $top => $key) {
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

    /**
     * A multi-exam report's exams, each with a title, regions and an
     * accession ('' or [] when unset). Empty for a single-exam report: one
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
     * byte for byte. A last `##` that is a conclusion (Concluzii, Concluzie…),
     * after at least one exam, is not an exam: it is the report's shared
     * conclusion, returned as `shared` (null when there is none) and left out
     * of `parts`; head . implode(parts) . shared text is the body.
     *
     * @return array{head: string, parts: list<array{title: string, text: string}>, shared: array{title: string, text: string}|null}
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

        $shared = null;
        $last = \count($parts) - 1;
        if ($last >= 1 && self::isConclusionTitle($parts[$last]['title'])) {
            $shared = array_pop($parts);
        }

        return ['head' => $head, 'parts' => $parts, 'shared' => $shared];
    }

    /**
     * The parts the writing checks read: each exam's text, or, for a report with a
     * shared conclusion, one part holding every exam's text then that conclusion as
     * a `### Concluzii` (the conclusion answers all the exams, not one). A report
     * with no `##` is one part with the body's head.
     *
     * @param array{head: string, parts: list<array{title: string, text: string}>, shared: array{title: string, text: string}|null} $split
     * @param array<string, mixed> $frontmatter
     *
     * @return list<array{title: string, text: string}>
     */
    public static function checkParts(array $split, array $frontmatter): array
    {
        if ($split['shared'] !== null) {
            $text = implode('', array_column($split['parts'], 'text'));
            $text = ($text !== '' && !str_ends_with($text, "\n") ? $text . "\n" : $text) . "### Concluzii\n" . self::withoutHeadingLine($split['shared']['text']);

            return [['title' => '', 'text' => $text]];
        }

        return $split['parts'] !== [] ? $split['parts'] : [['title' => self::title($frontmatter), 'text' => $split['head']]];
    }

    /** A part's text without its first (`##`) line */
    private static function withoutHeadingLine(string $text): string
    {
        $at = strpos($text, "\n");

        return $at === false ? '' : substr($text, $at + 1);
    }

    /** Whether a `##` heading's text names a conclusion (Concluzii, Concluzie, Conclusions) */
    private static function isConclusionTitle(string $title): bool
    {
        return preg_match('/^conclu(zi[aei]?|sions?)\b/u', self::fold(trim($title, " \t:.*_"))) === 1;
    }

    /**
     * What stops a multi-exam report from being signed (D7: never from
     * being saved), as dotted codes the sign screen labels:
     * `exams.count` when the body's exams and the list differ in number,
     * `exams.{n}.title` for an exam with no title, `exams.{n}.conclusion`
     * for an exam with no `### Concluzii` directly under it, unless the
     * report has a shared `## Concluzii` after its exams (then none is asked
     * of them): `exams.shared_empty` if that has no text. Nothing for a
     * report that declares no exams, nor for a text one (D40): it has no
     * headings to check.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return list<string>
     */
    public static function problems(array $frontmatter, string $body): array
    {
        $declared = self::declared($frontmatter);
        if ($declared === [] || BodyFormat::isText($frontmatter)) {
            return [];
        }
        $split = self::split($body);
        $parts = $split['parts'];
        $shared = $split['shared'];
        $problems = \count($parts) !== \count($declared) ? ['exams.count'] : [];
        if ($shared !== null) {
            if (trim(self::withoutHeadingLine($shared['text'])) === '') {
                $problems[] = 'exams.shared_empty';
            }
        }
        foreach ($declared as $i => $exam) {
            $n = $i + 1;
            if ($exam['title'] === '' && ($parts[$i]['title'] ?? '') === '') {
                $problems[] = 'exams.' . $n . '.title';
            }
            if ($shared === null && isset($parts[$i]) && !self::hasConclusion($parts[$i]['text'])) {
                $problems[] = 'exams.' . $n . '.conclusion';
            }
        }

        return $problems;
    }

    /**
     * Every exam's accession, in order, skipping unset ones: the numbers a
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

    /**
     * The accession numbers the PACS gave the report's studies (`pacs_accession`),
     * one per exam in order, unset ones skipped: what a printed report shows: the
     * number the imaging center knows, never Reporion's own (D20). A single-exam
     * report's is on its one exam, or at the top level in the old shape.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return list<string>
     */
    public static function pacsAccessions(array $frontmatter): array
    {
        $raw = $frontmatter['exams'] ?? null;
        $exams = \is_array($raw) && array_is_list($raw) && $raw !== [] ? $raw : [$frontmatter];
        $out = [];
        foreach ($exams as $exam) {
            $number = \is_array($exam) ? MetaText::text($exam['pacs_accession'] ?? null) : '';
            if ($number !== '' && !\in_array($number, $out, true)) {
                $out[] = $number;
            }
        }

        return $out;
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
