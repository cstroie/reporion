<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use InvalidArgumentException;
use Reporion\Exception\MaintenanceBusyException;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * bin/reporion pages:apply-patient-csv --from=<table.csv> [--namespace=<ns>] [--loose-names] --actor=<username> [--dry-run] [--limit=<n>] [--json]
 *
 * Reads a site's booking table against the reports of one namespace (default
 * reports:mri:polimed) and lists what it would fill — CNP, birth year, sex,
 * indication, exam title, into fields that are empty — with --dry-run only;
 * otherwise it writes one new revision per unsigned report, attributed to --actor,
 * at most --limit per run — Service\Maintenance\PatientCsvTask. Reports are
 * named by pid and table rows by line number: the output never carries a name
 * or a CNP. --loose-names also applies a row whose name and a report's differ
 * by a given name (same day, two name tokens or more in common, still only when
 * exactly one report fits). Not in Admin → Maintenance: it needs a file on the server.
 */
final class PagesApplyPatientCsvCommand implements CommandInterface
{
    public function __construct(private readonly MaintenanceRunner $runner)
    {
    }

    public function run(array $args, Output $output): int
    {
        if (\in_array('--apply', $args, true)) {
            $output->error('--apply is gone: writes are the default now; add --dry-run to only look');

            return 1;
        }
        $apply = !\in_array('--dry-run', $args, true);
        $actor = null;
        $raw = [];
        foreach ($args as $arg) {
            foreach (['from', 'namespace', 'limit'] as $option) {
                if (str_starts_with($arg, '--' . $option . '=')) {
                    $raw[$option] = substr($arg, \strlen($option) + 3);
                }
            }
            if ($arg === '--loose-names') {
                $raw['loose_names'] = true;
            }
            if (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            }
        }
        if ($apply && $actor === null) {
            $output->error('--actor=<username> is needed unless --dry-run: the new revisions are attributed to them');

            return 1;
        }

        try {
            $report = $this->runner->run('pages:apply-patient-csv', $apply ? MaintenanceTask::APPLY : MaintenanceTask::CHECK, $actor ?? 'cli', $raw)['report'];
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        } catch (InvalidArgumentException $e) {
            $output->error($e->getMessage());

            return 1;
        }
        if (\in_array('--json', $args, true)) {
            $output->line($report->toJson());

            return $report->exit();
        }

        foreach ($report->items() as $item) {
            $output->line(($item['pid'] !== null ? \sprintf('pid %s rev %d: ', (string) $item['pid'], (int) $item['rev']) : '') . $item['outcome'] . ' — ' . $item['detail']);
        }

        $s = $report->summary();
        $output->line(\sprintf(
            '%d table rows; %s; %d already complete; %d to review by hand; %d without a report; %d unreadable; %d with an invalid CNP (not written); %d signed (correct and re-sign by hand); %d keep an exam title the table would word differently%s',
            $s['rows'],
            $apply ? $s['applied'] . ' applied' : $s['would_apply'] . ' to apply (dry run: nothing written)',
            $s['unchanged'],
            $s['review'],
            $s['unmatched'],
            $s['unreadable_rows'],
            $s['invalid_cnp'],
            $s['signed'],
            $s['exam_kept'],
            $apply && $s['remaining'] > 0 ? '; ' . $s['remaining'] . ' left for the next run (--limit)' : '',
        ));

        return 0;
    }
}
