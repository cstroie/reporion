<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Dicom;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Exception\RevisionConflictException;
use Reporion\Service\Maintenance\MaintenanceReport;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ReportPath;
use Throwable;

/**
 * pacs:link — walks the reports of one site that carry no `study_uid` and
 * links each to its PACS study when that is unambiguous: exactly one study
 * on the report's own day (not the ±window the PACS tab shows) that matches
 * by CNP, or by the same name when the CNP cannot decide; and no other
 * report already holds it. Everything else is listed (ambiguous, no
 * match) for the PACS tab. A name-only match never imports the study's
 * CNP. Signed reports are linked too, which — as any edit of a signed
 * report (D3) — returns them to draft: the check reports how many. Pages
 * are named by pid only (invariant 8). Check mode asks the PACS as well,
 * so it costs the same queries; --limit bounds both.
 */
final class BulkLinkTask implements MaintenanceTask
{
    public function __construct(
        private readonly Pacs $pacs,
        private readonly StorageInterface $storage,
        private readonly AuditLog $audit,
    ) {
    }

    public function name(): string
    {
        return 'pacs:link';
    }

    public function modes(): array
    {
        return [self::CHECK, self::APPLY];
    }

    public function options(array $raw): array
    {
        $site = \is_string($raw['site'] ?? null) ? $raw['site'] : '';
        if (!isset($this->pacs->servers()[$site])) {
            throw new InvalidArgumentException('--site must be a site with a PACS configured');
        }
        $limit = $raw['limit'] ?? 0;

        return ['site' => $site, 'limit' => is_numeric($limit) && (int) $limit > 0 ? (int) $limit : 0];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $apply = $mode === self::APPLY;
        $site = (string) $options['site'];
        $limit = (int) $options['limit'];
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        foreach ([$apply ? 'linked' : 'would_link', $apply ? 'signed_to_draft' : 'would_unsign', 'ambiguous', 'no_match', 'no_day', 'error', 'remaining'] as $key) {
            $report->count($key, 0);
        }

        // One disk pass: this site's unlinked reports, and every study UID any report already holds
        $held = [];
        $todo = [];
        foreach ($this->storage->allPaths() as $path) {
            if (!ReportPath::isReport($path)) {
                continue;
            }
            try {
                $page = $this->storage->read($path);
            } catch (Throwable) {
                continue;
            }
            $uid = \is_scalar($page->frontmatter['study_uid'] ?? null) ? (string) $page->frontmatter['study_uid'] : '';
            if ($uid !== '') {
                $held[$uid] = true;
            } elseif (($page->frontmatter['site'] ?? null) === $site) {
                $todo[$page->path] = $page;
            }
        }
        ksort($todo);

        $asked = 0;
        foreach ($todo as $page) {
            if ($limit > 0 && $asked >= $limit) {
                $report->count('remaining');
                continue;
            }
            $day = Pacs::studyDay($page);
            if ($day === '') {
                $report->count('no_day');
                continue;
            }
            ++$asked;
            try {
                $rows = $this->pacs->lookup($page, $site, $day)['rows'];
            } catch (DicomException $e) {
                $report->count('error');
                $report->item($page->pid, $page->rev, 'error', $e->getMessage());
                if (\in_array($e->getMessage(), ['unreachable', 'timeout', 'rejected', 'no-tool'], true)) {
                    $report->note('Stopped: the PACS did not answer (' . $e->getMessage() . ').');
                    $report->fail();
                    break;
                }
                continue;
            }

            $matches = array_values(array_filter(
                $rows,
                static fn (array $r): bool => \in_array($r['match'], ['cnp', 'name'], true)
                    && substr((string) $r['when'], 0, 10) === $day
                    && !isset($held[(string) $r['uid']]),
            ));
            if (\count($matches) > 1) {
                $report->count('ambiguous');
                $report->item($page->pid, $page->rev, 'ambiguous', \count($matches) . ' studies on the day');
                continue;
            }
            if ($matches === []) {
                $report->count('no_match');
                continue;
            }

            $match = $matches[0];
            $signed = $page->status === 'signed';
            if (!$apply) {
                $report->count('would_link');
                if ($signed) {
                    $report->count('would_unsign');
                    $report->item($page->pid, $page->rev, 'would_unsign', 'signed; linking returns it to draft', ['match' => $match['match']]);
                }
                $held[(string) $match['uid']] = true;
                continue;
            }
            try {
                $saved = $this->pacs->link($page, $actor, $site, (string) $match['uid'], auto: true, fillCnp: $match['match'] === 'cnp');
            } catch (InvalidArgumentException $e) {
                $report->count('error');
                $report->item($page->pid, $page->rev, 'error', $e->getMessage());
                continue;
            } catch (RevisionConflictException) {
                $report->count('error');
                $report->item($page->pid, $page->rev, 'error', 'edited meanwhile');
                continue;
            } catch (DicomException $e) {
                $report->count('error');
                $report->item($page->pid, $page->rev, 'error', $e->getMessage());
                continue;
            }
            $held[(string) $match['uid']] = true;
            if ($saved === null) {
                continue;
            }
            $report->count('linked');
            if ($signed) {
                $report->count('signed_to_draft');
                $report->item($page->pid, $saved->rev, 'signed_to_draft', 'linked; sign it again', ['match' => $match['match']]);
            }
            $this->audit->record('page.save', $actor, null, $saved->pid, $saved->path, $saved->rev, extra: ['via' => 'dicom-bulk', 'match' => $match['match']]);
        }

        return $report;
    }
}
