<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use DateTimeImmutable;
use Reporion\Auth\User;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Stats;

/**
 * GET /stats and GET /stats.csv (roadmap phase 24b): workload and
 * turnaround over the reports the caller can list (Service\Stats). Filters
 * are checked against what exists — the period against Stats::PERIODS, the
 * site against the configured sites, the modality against the schemas —
 * so nothing from the query string reaches SQL unchecked. The CSV carries
 * the count tables only, never the stale drafts' titles (patient names).
 */
final class StatsController
{
    /** Tables the CSV offers */
    public const TABLES = ['months', 'modality', 'site', 'signer'];

    /**
     * @param array<string, string> $sites    site key => display name
     * @param list<string>          $modalities
     */
    public function __construct(
        private readonly Stats $stats,
        private readonly IndexInterface $index,
        private readonly array $sites,
        private readonly array $modalities,
    ) {
    }

    public function show(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            return Response::redirect($request->basePath . '/login');
        }
        [$months, $filters] = $this->filters($request);

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/stats.php', [
            'stats' => $this->stats->compute($principal, new DateTimeImmutable(), $months, $filters),
            'filter' => ['months' => $months, 'site' => $filters['site'] ?? '', 'modality' => $filters['modality'] ?? ''],
            'periods' => Stats::PERIODS,
            'sites' => $this->sites,
            'modalities' => $this->modalities,
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('stats.title')));
    }

    public function csv(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            return Response::redirect($request->basePath . '/login');
        }
        $table = \is_string($request->query['table'] ?? null) && \in_array($request->query['table'], self::TABLES, true) ? $request->query['table'] : 'months';
        [$months, $filters] = $this->filters($request);
        $stats = $this->stats->compute($principal, new DateTimeImmutable(), $months, $filters);

        $days = static fn (?float $d): string => $d === null ? '' : number_format($d, 2, '.', '');
        if ($table === 'months') {
            $lines = [['month', 'exams', 'signed', 'median_days']];
            foreach ($stats['months'] as $row) {
                $lines[] = [$row['month'], (string) $row['exams'], (string) $row['signed'], $days($row['median'])];
            }
        } else {
            $lines = [[$table, 'exams', 'signed', 'median_days', 'p90_days']];
            foreach ($stats[$table] as $row) {
                $key = match ($table) {
                    'signer' => display_name($row['key']),
                    'site' => $this->sites[$row['key']] ?? $row['key'],
                    default => $row['key'],
                };
                $lines[] = [$key, (string) $row['exams'], (string) $row['signed'], $days($row['median']), $days($row['p90'])];
            }
        }

        $out = fopen('php://temp', 'r+');
        foreach ($lines as $line) {
            // A cell starting with = + - @ is a formula to a spreadsheet; quote it out
            fputcsv($out, array_map(static fn (string $cell): string => preg_match('/^[=+\-@]/', $cell) === 1 ? "'" . $cell : $cell, $line), ',', '"', '');
        }
        rewind($out);
        $body = (string) stream_get_contents($out);
        fclose($out);

        return new Response(200, $body, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="reporion-stats-' . $table . '-' . (new DateTimeImmutable())->format('Ymd') . '.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array{0: int, 1: array{site?: string, modality?: string}}
     */
    private function filters(Request $request): array
    {
        $months = (int) ($request->query['months'] ?? 12);
        $months = \in_array($months, Stats::PERIODS, true) ? $months : 12;
        $filters = [];
        $site = $request->query['site'] ?? '';
        if (\is_string($site) && isset($this->sites[$site])) {
            $filters['site'] = $site;
        }
        $modality = $request->query['modality'] ?? '';
        if (\is_string($modality) && \in_array($modality, $this->modalities, true)) {
            $filters['modality'] = $modality;
        }

        return [$months, $filters];
    }
}
