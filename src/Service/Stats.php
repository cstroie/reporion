<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use DateTimeImmutable;
use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Support\ReportPath;
use Throwable;

/**
 * Workload and turnaround (roadmap phase 24), from the index only: the
 * caller's own listing (invariant 6), so an editor with one site's grant
 * gets that site's numbers.
 *
 * - **Exams** count by the exam date (`study_date`), whatever the status.
 * - **Signed** counts by the first signature (`signed_at`, phase 24a); an
 *   archived import was never signed here and is not counted as signed.
 * - **Turnaround** is exam date → first signature, in days (an exam date
 *   without a time is that day's midnight); a signature before the exam
 *   date (a typo in the date) is left out.
 * - **Stale drafts**: unsigned reports last changed more than STALE_DAYS
 *   ago, oldest first: the start page's threshold.
 */
final class Stats
{
    /** Periods offered, in months */
    public const PERIODS = [3, 6, 12, 24];

    /** Stale drafts listed */
    public const STALE_SHOWN = 50;

    public function __construct(
        private readonly IndexInterface $index,
        private readonly int $staleDays,
    ) {
    }

    /**
     * @param array{site?: string, modality?: string} $filters
     *
     * @return array{
     *   months: list<array{month: string, exams: int, signed: int, median: ?float}>,
     *   status: array{draft: int, signed: int, archived: int},
     *   modality: list<array{key: string, exams: int, signed: int, median: ?float, p90: ?float}>,
     *   site: list<array{key: string, exams: int, signed: int, median: ?float, p90: ?float}>,
     *   signer: list<array{key: string, exams: int, signed: int, median: ?float, p90: ?float}>,
     *   turnaround: array{n: int, median: ?float, p90: ?float},
     *   stale: list<array{path: string, title: string, updated: string, study_date: ?string}>,
     *   staleTotal: int
     * }
     */
    public function compute(?User $principal, DateTimeImmutable $now, int $months, array $filters = []): array
    {
        $months = \in_array($months, self::PERIODS, true) ? $months : 12;
        $first = $now->modify('first day of this month')->setTime(0, 0)->modify('-' . ($months - 1) . ' months');
        $since = $first->format('Y-m-d');
        $rows = $this->reports($principal, ['since' => $since] + $filters);

        $keys = [];
        for ($m = $first; \count($keys) < $months; $m = $m->modify('+1 month')) {
            $keys[] = $m->format('Y-m');
        }
        $perMonth = array_fill_keys($keys, ['exams' => 0, 'signed' => 0, 'days' => []]);
        $status = ['draft' => 0, 'signed' => 0, 'archived' => 0];
        $groups = ['modality' => [], 'site' => [], 'signer' => []];
        $all = [];
        $staleBefore = $now->modify('-' . $this->staleDays . ' days')->format('Y-m-d\TH:i:sP');
        $stale = [];

        foreach ($rows as $row) {
            $examMonth = $row['study_date'] !== null && \strlen($row['study_date']) >= 7 ? substr($row['study_date'], 0, 7) : null;
            $signMonth = $row['signed_at'] !== null ? substr($row['signed_at'], 0, 7) : null;
            $inExams = $examMonth !== null && isset($perMonth[$examMonth]);
            $inSigned = $signMonth !== null && isset($perMonth[$signMonth]);
            $days = $inSigned ? self::turnaround($row['study_date'], (string) $row['signed_at']) : null;
            $site = $row['site'] ?? '';

            if ($row['status'] === 'draft' && $row['updated'] < $staleBefore) {
                $stale[] = ['path' => $row['path'], 'title' => $row['title'], 'updated' => $row['updated'], 'study_date' => $row['study_date']];
            }
            if ($inExams) {
                ++$perMonth[$examMonth]['exams'];
                if (isset($status[$row['status']])) {
                    ++$status[$row['status']];
                }
            }
            if ($inSigned) {
                ++$perMonth[$signMonth]['signed'];
                if ($days !== null) {
                    $perMonth[$signMonth]['days'][] = $days;
                    $all[] = $days;
                }
            }

            $by = [
                'modality' => $row['modalities'] !== [] ? $row['modalities'] : ['n/a'],
                'site' => [$site !== '' ? $site : 'n/a'],
                'signer' => $inSigned && ($row['signed_by'] ?? '') !== '' ? [(string) $row['signed_by']] : [],
            ];
            foreach ($by as $group => $values) {
                foreach ($values as $value) {
                    $groups[$group][$value] ??= ['exams' => 0, 'signed' => 0, 'days' => []];
                    if ($inExams && $group !== 'signer') {
                        ++$groups[$group][$value]['exams'];
                    }
                    if ($inSigned) {
                        ++$groups[$group][$value]['signed'];
                        if ($days !== null) {
                            $groups[$group][$value]['days'][] = $days;
                        }
                    }
                }
            }
        }

        usort($stale, static fn (array $a, array $b): int => strcmp($a['updated'], $b['updated']));

        return [
            'months' => array_map(
                static fn (string $month, array $m): array => ['month' => $month, 'exams' => $m['exams'], 'signed' => $m['signed'], 'median' => self::percentile($m['days'], 50)],
                array_keys($perMonth),
                array_values($perMonth),
            ),
            'status' => $status,
            'modality' => self::table($groups['modality']),
            'site' => self::table($groups['site']),
            'signer' => self::table($groups['signer']),
            'turnaround' => ['n' => \count($all), 'median' => self::percentile($all, 50), 'p90' => self::percentile($all, 90)],
            'stale' => \array_slice($stale, 0, self::STALE_SHOWN),
            'staleTotal' => \count($stale),
        ];
    }

    /**
     * The start page's "This month" card: reports signed this month and
     * last, with their median turnaround.
     *
     * @return array{signed: int, median: ?float, lastSigned: int, lastMedian: ?float}
     */
    public function thisMonth(?User $principal, DateTimeImmutable $now): array
    {
        $this0 = $now->modify('first day of this month')->format('Y-m');
        $last0 = $now->modify('first day of last month')->format('Y-m');
        $days = [$this0 => [], $last0 => []];
        $count = [$this0 => 0, $last0 => 0];
        foreach ($this->reports($principal, ['since' => $last0 . '-01']) as $row) {
            $month = $row['signed_at'] !== null ? substr($row['signed_at'], 0, 7) : '';
            if (!isset($count[$month])) {
                continue;
            }
            ++$count[$month];
            $d = self::turnaround($row['study_date'], (string) $row['signed_at']);
            if ($d !== null) {
                $days[$month][] = $d;
            }
        }

        return [
            'signed' => $count[$this0],
            'median' => self::percentile($days[$this0], 50),
            'lastSigned' => $count[$last0],
            'lastMedian' => self::percentile($days[$last0], 50),
        ];
    }

    /**
     * The start page's Insights panel: the caller's listing over the last
     * twelve months (one statsRows() call, invariant 6).
     *
     * @return array<string, mixed> see insightsFrom()
     */
    public function insights(User $principal, DateTimeImmutable $now): array
    {
        $since = $now->modify('first day of this month')->modify('-12 months')->format('Y-m-d');

        return self::insightsFrom($this->reports($principal, ['since' => $since]), $now, $principal->username);
    }

    /**
     * Pure: rows as statsRows() returns them (reports only).
     *
     * - weeks: the last 12 ISO weeks, oldest first, signatures by the caller and by others;
     * - trend: median turnaround this month against the three months before, p90 this month;
     * - sameDay: share of this month's signatures under one day (null for none);
     * - backlog: open drafts by days since their last change, and the oldest one's age;
     * - modality / site: this month's exams, top five with their share;
     * - weekday / hour: when signatures happen over the 12 weeks (null for none);
     * - share: the caller's part of this month's signatures; pace: this month's count
     *   extrapolated over its working days (null before the first working day ends).
     *
     * @param list<array{path: string, title: string, status: string, site: ?string, study_date: ?string, signed_at: ?string, signed_by: ?string, updated: string, modalities: list<string>}> $rows
     *
     * @return array{
     *   weeks: list<array{week: string, mine: int, team: int}>,
     *   trend: array{median: ?float, before: ?float, p90: ?float, change: ?float},
     *   sameDay: ?float,
     *   backlog: array{buckets: array{day: int, week: int, month: int, older: int}, total: int, oldest: ?float},
     *   modality: list<array{key: string, n: int, share: float}>,
     *   site: list<array{key: string, n: int, share: float}>,
     *   weekday: ?int,
     *   hour: ?int,
     *   signed: int,
     *   mine: int,
     *   share: ?float,
     *   pace: ?int
     * }
     */
    public static function insightsFrom(array $rows, DateTimeImmutable $now, string $me): array
    {
        $thisMonth = $now->format('Y-m');
        $before = [];
        for ($i = 1; $i <= 3; ++$i) {
            $before[$now->modify('first day of this month')->modify("-{$i} months")->format('Y-m')] = true;
        }
        $monday = $now->setTime(0, 0)->modify('monday this week');
        $weeks = [];
        for ($i = 11; $i >= 0; --$i) {
            $weeks[$monday->modify("-{$i} weeks")->format('o-\WW')] = ['mine' => 0, 'team' => 0];
        }
        $daysNow = [];
        $daysBefore = [];
        $buckets = ['day' => 0, 'week' => 0, 'month' => 0, 'older' => 0];
        $oldest = null;
        $mix = ['modality' => [], 'site' => []];
        $exams = 0;
        $weekday = array_fill(1, 7, 0);
        $hour = array_fill(0, 24, 0);
        $signed = 0;
        $mine = 0;

        foreach ($rows as $row) {
            if ($row['status'] === 'draft') {
                try {
                    $age = ($now->getTimestamp() - (new DateTimeImmutable($row['updated']))->getTimestamp()) / 86400;
                } catch (Throwable) {
                    $age = null;
                }
                if ($age !== null) {
                    $age = max(0.0, $age);
                    ++$buckets[match (true) {
                        $age < 1 => 'day',
                        $age < 7 => 'week',
                        $age < 30 => 'month',
                        default => 'older',
                    }];
                    $oldest = max($oldest ?? 0.0, $age);
                }
            }
            if ($row['study_date'] !== null && str_starts_with($row['study_date'], $thisMonth)) {
                ++$exams;
                foreach ($row['modalities'] !== [] ? $row['modalities'] : ['n/a'] as $m) {
                    $mix['modality'][$m] = ($mix['modality'][$m] ?? 0) + 1;
                }
                $site = ($row['site'] ?? '') !== '' ? (string) $row['site'] : 'n/a';
                $mix['site'][$site] = ($mix['site'][$site] ?? 0) + 1;
            }
            if ($row['signed_at'] === null || $row['signed_at'] === '') {
                continue;
            }
            try {
                $at = new DateTimeImmutable($row['signed_at']);
            } catch (Throwable) {
                continue;
            }
            $at = $at->setTimezone($now->getTimezone());
            $isMine = $row['signed_by'] === $me;
            $week = $at->format('o-\WW');
            if (isset($weeks[$week])) {
                ++$weeks[$week][$isMine ? 'mine' : 'team'];
                ++$weekday[(int) $at->format('N')];
                ++$hour[(int) $at->format('G')];
            }
            $month = $at->format('Y-m');
            $days = self::turnaround($row['study_date'], $row['signed_at']);
            if ($month === $thisMonth) {
                ++$signed;
                if ($isMine) {
                    ++$mine;
                }
                if ($days !== null) {
                    $daysNow[] = $days;
                }
            } elseif (isset($before[$month]) && $days !== null) {
                $daysBefore[] = $days;
            }
        }

        $median = self::percentile($daysNow, 50);
        $medianBefore = self::percentile($daysBefore, 50);
        $top = static function (array $counts) use ($exams): array {
            arsort($counts);
            $out = [];
            foreach (\array_slice($counts, 0, 5, true) as $key => $n) {
                $out[] = ['key' => (string) $key, 'n' => $n, 'share' => $exams > 0 ? $n / $exams : 0.0];
            }

            return $out;
        };
        $any = array_sum($weekday) > 0;

        return [
            'weeks' => array_map(static fn (string $w, array $c): array => ['week' => $w] + $c, array_keys($weeks), array_values($weeks)),
            'trend' => [
                'median' => $median,
                'before' => $medianBefore,
                'p90' => self::percentile($daysNow, 90),
                'change' => $median !== null && $medianBefore !== null && $medianBefore > 0 ? ($median - $medianBefore) / $medianBefore : null,
            ],
            'sameDay' => $daysNow !== [] ? \count(array_filter($daysNow, static fn (float $d): bool => $d < 1)) / \count($daysNow) : null,
            'backlog' => ['buckets' => $buckets, 'total' => array_sum($buckets), 'oldest' => $oldest],
            'modality' => $top($mix['modality']),
            'site' => $top($mix['site']),
            'weekday' => $any ? (int) array_search(max($weekday), $weekday, true) : null,
            'hour' => $any ? (int) array_search(max($hour), $hour, true) : null,
            'signed' => $signed,
            'mine' => $mine,
            'share' => $signed > 0 ? $mine / $signed : null,
            'pace' => self::pace($signed, $now),
        ];
    }

    /** This month's count extrapolated over its working days (Mon–Fri); null before one has passed */
    public static function pace(int $signed, DateTimeImmutable $now): ?int
    {
        $total = 0;
        $done = 0;
        $today = (int) $now->format('j');
        $day = $now->modify('first day of this month')->setTime(12, 0);
        $last = (int) $now->format('t');
        for ($d = 1; $d <= $last; ++$d, $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') >= 6) {
                continue;
            }
            ++$total;
            if ($d < $today) {
                ++$done;
            }
        }

        return $done > 0 ? (int) round($signed / $done * $total) : null;
    }

    /**
     * Exam date → first signature, in days; null when either is missing or
     * the signature comes first.
     */
    public static function turnaround(?string $studyDate, string $signedAt): ?float
    {
        if ($studyDate === null || $studyDate === '' || $signedAt === '') {
            return null;
        }
        try {
            $from = new DateTimeImmutable(\strlen($studyDate) === 10 ? $studyDate . ' 00:00:00' : $studyDate);
            $to = new DateTimeImmutable($signedAt);
        } catch (Throwable) {
            return null;
        }
        $seconds = $to->getTimestamp() - $from->getTimestamp();

        return $seconds >= 0 ? $seconds / 86400 : null;
    }

    /** A turnaround for display: "n/a" for none, hours under a day, else days to one decimal */
    public static function formatDays(?float $days): string
    {
        return match (true) {
            $days === null => 'n/a',
            $days < 1 => t('stats.hours', [(int) round($days * 24)]),
            default => t('stats.days', [number_format($days, 1)]),
        };
    }

    /**
     * The $p-th percentile, linear between neighbours (the median of an
     * even count is the mean of the middle two); null for no values.
     *
     * @param list<float> $values
     */
    public static function percentile(array $values, int $p): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $rank = ($p / 100) * (\count($values) - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);

        return $values[$low] + ($values[$high] - $values[$low]) * ($rank - $low);
    }

    /**
     * @param array<string, array{exams: int, signed: int, days: list<float>}> $group
     *
     * @return list<array{key: string, exams: int, signed: int, median: ?float, p90: ?float}>
     */
    private static function table(array $group): array
    {
        $out = [];
        foreach ($group as $key => $g) {
            $out[] = ['key' => (string) $key, 'exams' => $g['exams'], 'signed' => $g['signed'], 'median' => self::percentile($g['days'], 50), 'p90' => self::percentile($g['days'], 90)];
        }
        usort($out, static fn (array $a, array $b): int => [$b['exams'] + $b['signed'], $a['key']] <=> [$a['exams'] + $a['signed'], $b['key']]);

        return $out;
    }

    /**
     * @param array{since: string, site?: string, modality?: string} $filters
     *
     * @return list<array{path: string, title: string, status: string, site: ?string, study_date: ?string, signed_at: ?string, signed_by: ?string, updated: string, modalities: list<string>}>
     */
    private function reports(?User $principal, array $filters): array
    {
        return array_values(array_filter(
            $this->index->statsRows($principal, $filters),
            static fn (array $row): bool => ReportPath::isReport($row['path']),
        ));
    }
}
