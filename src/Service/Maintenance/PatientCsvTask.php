<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use DateTimeImmutable;
use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Storage\FlatFile;
use Reporion\Support\Cnp;
use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\ReportPath;
use Reporion\Support\Slug;
use Throwable;

/**
 * pages:apply-patient-csv — a site's booking table (one row per exam:
 * `DATA, NUME, PRENUME, CNP, SEGMENT ANALIZAT, DATE CLINICE`, …) read
 * against the reports of one namespace, filling each matched report's
 * CNP, birth year, sex and indication where it has none, and counting the
 * reports whose exam title the table words differently (it never replaces one).
 *
 * The risk here is attaching one person's CNP to another's report — the CNP
 * is the strong patient key (D11), so a wrong one merges two timelines. A row
 * therefore applies only when it names exactly one report: the same name
 * (surname + given names, either order, any token order) *and* the exact
 * study day of the report's path, and no other row claims that report. A
 * name match a few days off, a name that fits several reports, two rows for
 * one report, a CNP that disagrees with the one already there — all of
 * those go to review and write nothing. A CNP failing its checksum is never
 * written, and the birth year and sex are derived from a valid CNP alone.
 * Fields are only ever filled, never overwritten (the booking table's
 * SEGMENT is shorthand like "IRM GEN DR NATIV"; the exam title is the
 * body's `## exam` heading, and saving moves it into `exams:` anyway); a signed report is listed, never
 * rewritten (D3). Output names reports by pid and table rows by line
 * number — never a name, a CNP or a path (invariant 8).
 */
final class PatientCsvTask implements MaintenanceTask
{
    /** How many days off the path's day a name-only match still counts as worth a look */
    private const NEAR_DAYS = 7;

    public function __construct(
        private readonly FlatFile $storage,
        private readonly AuditLog $audit,
    ) {
    }

    public function name(): string
    {
        return 'pages:apply-patient-csv';
    }

    public function modes(): array
    {
        return [self::CHECK, self::APPLY];
    }

    public function options(array $raw): array
    {
        $from = trim((string) ($raw['from'] ?? ''));
        if ($from === '' || !is_file($from) || !is_readable($from)) {
            throw new InvalidArgumentException('--from=<file.csv> must name a readable file');
        }
        $namespace = trim((string) ($raw['namespace'] ?? ''), ': ');
        $limit = $raw['limit'] ?? 0;

        return [
            'from' => $from,
            'namespace' => $namespace !== '' ? $namespace : 'reports:mri:polimed',
            'limit' => is_numeric($limit) && (int) $limit > 0 ? (int) $limit : 0,
            'loose_names' => filter_var($raw['loose_names'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $apply = $mode === self::APPLY;
        $limit = (int) $options['limit'];
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        $changedKey = $apply ? 'applied' : 'would_apply';
        foreach (['rows', $changedKey, 'unchanged', 'review', 'unmatched', 'unreadable_rows', 'invalid_cnp', 'signed', 'exam_kept', ...($apply ? ['remaining'] : [])] as $key) {
            $report->count($key, 0);
        }

        [$rows, $unreadable] = $this->readRows((string) $options['from']);
        $report->count('rows', \count($rows) + \count($unreadable));
        foreach ($unreadable as $line => $why) {
            $report->count('unreadable_rows');
            $report->item(null, null, 'unreadable-row', 'line ' . $line . ': ' . $why);
        }

        $pages = $this->pagesOf((string) $options['namespace']);

        // Which rows name which page; a page claimed by several rows is a conflict
        /** @var array<string, list<int>> $claims  path → row lines */
        $claims = [];
        /** @var array<int, string> $matched row line → path */
        $matched = [];
        foreach ($rows as $line => $row) {
            [$exact, $near, $partial] = $this->candidates($row, $pages);
            if ($exact === [] && $options['loose_names']) {
                $exact = $partial;
            }
            if (\count($exact) === 1) {
                $matched[$line] = $exact[0];
                $claims[$exact[0]][] = $line;
            } elseif (\count($exact) > 1) {
                $report->count('review');
                $report->item(null, null, 'review', 'line ' . $line . ': the name and day fit ' . \count($exact) . ' reports');
            } elseif (\count($near) === 1) {
                $page = $this->storage->read($near[0]);
                $report->count('review');
                $report->item($page->pid, $page->rev, 'review', 'line ' . $line . ': the name fits this report, but not its day exactly');
            } elseif (\count($partial) === 1) {
                $page = $this->storage->read($partial[0]);
                $report->count('review');
                $report->item($page->pid, $page->rev, 'review', 'line ' . $line . ': the day fits and the name partly (one has a given name the other lacks); --loose-names applies these');
            } else {
                $report->count('unmatched');
                $report->item(null, null, 'unmatched', 'line ' . $line . ': no report with this name and day'
                    . ($near !== [] ? ' (' . \count($near) . ' with the name on nearby days)' : ''));
            }
        }

        $written = 0;
        foreach ($matched as $line => $path) {
            try {
                $page = $this->storage->read($path);
            } catch (Throwable) {
                continue;
            }
            if (\count($claims[$path]) > 1) {
                if ($line === $claims[$path][0]) {
                    $report->count('review');
                    $report->item($page->pid, $page->rev, 'review', 'lines ' . implode(', ', $claims[$path]) . ' all name this report');
                }
                continue;
            }

            $row = $rows[$line];
            [$updates, $reasons, $notes] = $this->reconcile($row, Exams::flat($page->frontmatter));
            if ($notes['invalid_cnp']) {
                $report->count('invalid_cnp');
                $report->item($page->pid, $page->rev, 'invalid-cnp', 'line ' . $line . ': the CNP fails its checksum — not written');
            }
            if ($notes['exam_kept']) {
                $report->count('exam_kept');
            }
            if ($reasons !== []) {
                $report->count('review');
                $report->item($page->pid, $page->rev, 'review', 'line ' . $line . ': ' . implode('; ', $reasons));
                continue;
            }
            if ($updates === []) {
                $report->count('unchanged');
                continue;
            }
            $fields = array_keys($updates);
            if ($page->status === 'signed') {
                $report->count('signed');
                $report->item($page->pid, $page->rev, 'signed', 'line ' . $line . ': ' . implode(', ', $fields), ['fields' => $fields]);
                continue;
            }
            if (!$apply) {
                $report->count('would_apply');
                continue;
            }
            if ($limit > 0 && $written >= $limit) {
                $report->count('remaining');
                continue;
            }

            $frontmatter = $this->applyUpdates(Exams::flat($page->frontmatter), $updates);
            $saved = $this->storage->save($path, $frontmatter, $page->body, $page->rev, $actor, 'patient data from the booking table (' . implode(', ', $fields) . ')', auto: true);
            $this->audit->record('page.save', $actor, null, $saved->pid, $saved->path, $saved->rev, extra: ['reason' => 'patient-csv-apply', 'fields' => $fields]);
            ++$written;
            $report->count('applied');
        }

        return $report;
    }

    /**
     * The table's rows keyed by line number: the study day (Y-m-d), the
     * surname and given names, the CNP, the segment and the clinical data.
     * A row that cannot be read (a date that is not DD.MM.YYYY, no name) is
     * returned apart, with why.
     *
     * @return array{0: array<int, array{day: string, surname: string, given: string, cnp: string, segment: string, indication: string}>, 1: array<int, string>}
     */
    private function readRows(string $file): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('The table cannot be opened');
        }
        $rows = [];
        $unreadable = [];
        $header = null;
        $line = 0;
        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            ++$line;
            if ($header === null) {
                $header = array_map(static fn (?string $h): string => strtoupper(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h) ?? '')), $cells);
                foreach (['DATA', 'NUME', 'PRENUME', 'CNP'] as $needed) {
                    if (!\in_array($needed, $header, true)) {
                        fclose($handle);
                        throw new InvalidArgumentException('The table has no ' . $needed . ' column');
                    }
                }
                continue;
            }
            if ($cells === [null]) {
                continue;
            }
            $cell = static fn (string $name): string => trim((string) ($cells[(int) array_search($name, $header, true)] ?? ''));

            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $cell('DATA'), $m) !== 1 || !checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                $unreadable[$line] = 'the date is not DD.MM.YYYY';
                continue;
            }
            if ($cell('NUME') === '' && $cell('PRENUME') === '') {
                $unreadable[$line] = 'no name';
                continue;
            }
            $rows[$line] = [
                'day' => \sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]),
                'surname' => $cell('NUME'),
                'given' => $cell('PRENUME'),
                'cnp' => $cell('CNP'),
                'segment' => $cell('SEGMENT ANALIZAT'),
                'indication' => $cell('DATE CLINICE'),
            ];
        }
        fclose($handle);

        return [$rows, $unreadable];
    }

    /**
     * The namespace's reports as path → [study day Y-m-d, sorted name tokens, name slug].
     * Taken from the path's leaf (`yymmdd-name`), which is how the team finds a report.
     *
     * @return array<string, array{day: string, tokens: string, slug: string}>
     */
    private function pagesOf(string $namespace): array
    {
        $pages = [];
        foreach ($this->storage->allPaths() as $path) {
            if (!str_starts_with($path, $namespace . ':') || !ReportPath::isReport($path)) {
                continue;
            }
            $leaf = substr($path, (int) strrpos($path, ':') + 1);
            if (preg_match('/^(\d{2})(\d{2})(\d{2})-(.+)$/', $leaf, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], 2000 + (int) $m[1])) {
                continue;
            }
            $pages[$path] = [
                'day' => \sprintf('20%s-%s-%s', $m[1], $m[2], $m[3]),
                'tokens' => self::tokens($m[4]),
                'slug' => $m[4],
            ];
        }

        return $pages;
    }

    /**
     * The reports a row may name: those with the same name — surname then
     * given names, or either order, in any token order — on the exact day
     * ($exact), those with the name on a day within NEAR_DAYS ($near), and
     * those on the exact day whose name shares two tokens or more with the
     * row's and holds all of one side's ($partial: "Ana Popescu" and "Ana
     * Maria Popescu").
     *
     * @param array{day: string, surname: string, given: string, cnp: string, segment: string, indication: string} $row
     * @param array<string, array{day: string, tokens: string, slug: string}> $pages
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function candidates(array $row, array $pages): array
    {
        $key = self::tokens(self::slugOf($row['surname'] . ' ' . $row['given']));
        $day = new DateTimeImmutable($row['day']);
        $exact = [];
        $near = [];
        $partial = [];
        $mine = explode('-', $key);
        foreach ($pages as $path => $page) {
            if ($page['tokens'] !== $key) {
                $theirs = explode('-', $page['tokens']);
                $shared = array_intersect($mine, $theirs);
                if ($page['day'] === $row['day'] && \count($shared) >= 2 && (\count($shared) === \count($mine) || \count($shared) === \count($theirs))) {
                    $partial[] = $path;
                }
                continue;
            }
            if ($page['day'] === $row['day']) {
                $exact[] = $path;
            } elseif (abs((int) $day->diff(new DateTimeImmutable($page['day']))->days) <= self::NEAR_DAYS) {
                $near[] = $path;
            }
        }

        return [$exact, $near, $partial];
    }

    private static function slugOf(string $name): string
    {
        try {
            return Slug::normalize($name);
        } catch (InvalidArgumentException) {
            return '';
        }
    }

    /** A slug's tokens, sorted and joined: "matei-gabriel-tudor" however the name was ordered */
    private static function tokens(string $slug): string
    {
        $parts = array_values(array_filter(explode('-', $slug), static fn (string $t): bool => $t !== ''));
        sort($parts);

        return implode('-', $parts);
    }

    /**
     * What a row fills into a report: `$updates` (dot-paths → values, only
     * into fields that are empty), `$reasons` (everything that disagrees with
     * what is there — the whole report is then left alone) and `$notes`.
     *
     * @param array{day: string, surname: string, given: string, cnp: string, segment: string, indication: string} $row
     * @param array<string, mixed> $frontmatter
     *
     * @return array{0: array<string, mixed>, 1: list<string>, 2: array{invalid_cnp: bool, exam_kept: bool}}
     */
    private function reconcile(array $row, array $frontmatter): array
    {
        $updates = [];
        $reasons = [];
        $notes = ['invalid_cnp' => false, 'exam_kept' => false];

        $cnp = preg_replace('/\s+/', '', $row['cnp']) ?? '';
        $currentCnp = MetaText::text($frontmatter['patient']['cnp'] ?? null);
        $currentBorn = $frontmatter['patient']['born'] ?? null;
        $currentSex = strtoupper(MetaText::text($frontmatter['patient']['sex'] ?? null));

        if ($cnp !== '' && !Cnp::isValid($cnp)) {
            $notes['invalid_cnp'] = true;
        } elseif ($cnp !== '') {
            $born = (int) Cnp::birthDate($cnp)?->format('Y');
            $sex = Cnp::sex($cnp);

            if ($currentCnp === '') {
                $updates['patient.cnp'] = $cnp;
            } elseif ($currentCnp !== $cnp) {
                $reasons[] = 'the CNP disagrees with the one on the report';
            }
            if ($currentBorn === null || $currentBorn === '') {
                $updates['patient.born'] = $born;
            } elseif ((int) $currentBorn !== $born) {
                $reasons[] = 'the CNP implies born ' . $born . ', which disagrees with patient.born (' . $currentBorn . ')';
            }
            if ($sex !== null) {
                if ($currentSex === '') {
                    $updates['patient.sex'] = $sex;
                } elseif ($currentSex !== $sex) {
                    $reasons[] = 'the CNP implies sex ' . $sex . ', which disagrees with patient.sex (' . $currentSex . ')';
                }
            }
        }

        $indication = trim(preg_replace('/\s+/u', ' ', $row['indication']) ?? '', " \t;");
        if ($indication !== '' && MetaText::text($frontmatter['indication'] ?? null) === '') {
            $updates['indication'] = $indication;
        }

        // The title is left as it is (the body's `## exam` heading agrees with it) and only counted, so the
        // owner can see how many the table would word differently before deciding to replace any
        $segment = trim(preg_replace('/\s+/u', ' ', $row['segment']) ?? '');
        $currentTitle = MetaText::text($frontmatter['exam_title'] ?? null);
        if ($segment !== '' && $currentTitle !== '' && mb_strtolower($segment) !== mb_strtolower($currentTitle)) {
            $notes['exam_kept'] = true;
        }

        return [$updates, $reasons, $notes];
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @param array<string, mixed> $updates     dot-paths, e.g. "patient.cnp"
     *
     * @return array<string, mixed>
     */
    private function applyUpdates(array $frontmatter, array $updates): array
    {
        foreach ($updates as $path => $value) {
            $parts = explode('.', $path);
            $last = array_pop($parts);
            $ref = &$frontmatter;
            foreach ($parts as $part) {
                if (!isset($ref[$part]) || !\is_array($ref[$part])) {
                    $ref[$part] = [];
                }
                $ref = &$ref[$part];
            }
            $ref[$last] = $value;
            unset($ref);
        }

        return $frontmatter;
    }
}
