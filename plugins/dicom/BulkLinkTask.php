<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Dicom;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Exception\RevisionConflictException;
use Reporion\Service\Maintenance\MaintenanceReport;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Maintenance\ProgressAware;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Exams;
use Reporion\Support\ReportPath;
use Throwable;

/**
 * pacs:link — walks the reports of one site that carry no `study_uid` and
 * links each to its PACS study when that is unambiguous: exactly one study
 * on the report's own day (not the ±window the PACS tab shows) that matches
 * by CNP, or by the same name when the CNP cannot decide; and no other
 * report already holds it. Several studies on the day that all carry one
 * patient (a multi-part scan) link no study but fill the report's blank
 * patient fields. Everything else is listed (ambiguous, no
 * match) for the PACS tab. A name-only match never imports the study's
 * CNP. Signed reports are linked too, which — as any edit of a signed
 * report (D3) — returns them to draft: the check reports how many. Pages
 * are named by pid only (invariant 8). Check mode asks the PACS as well,
 * so it costs the same queries; --limit bounds both.
 */
final class BulkLinkTask implements MaintenanceTask, ProgressAware
{
    /** @var ?callable(string, array<string, mixed>): void */
    private $progress = null;

    public function __construct(
        private readonly Pacs $pacs,
        private readonly StorageInterface $storage,
        private readonly AuditLog $audit,
    ) {
    }

    public function setProgress(?callable $progress): void
    {
        $this->progress = $progress;
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
        foreach ([$apply ? 'refreshed' : 'would_refresh', $apply ? 'linked' : 'would_link', $apply ? 'signed_to_draft' : 'would_unsign', 'ambiguous', $apply ? 'patient_only' : 'would_patient', 'no_match', 'no_day', 'error', 'remaining'] as $key) {
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
            // A multi-exam report got its studies from the worklist, per exam: held, never linked here
            foreach (Exams::isMulti($page->frontmatter) ? Pacs::studyUids($page->frontmatter) : [] as $examUid) {
                $held[$examUid] = true;
            }
            if (Exams::isMulti($page->frontmatter) && !\is_scalar($page->frontmatter['study_uid'] ?? null)) {
                continue;
            }
            $uid = \is_scalar($page->frontmatter['study_uid'] ?? null) ? (string) $page->frontmatter['study_uid'] : '';
            if ($uid !== '') {
                $held[$uid] = true;
                if (($page->frontmatter['site'] ?? null) === $site && self::wantsRefresh($page->frontmatter)) {
                    $todo[$page->path] = $page;
                }
            } elseif (($page->frontmatter['site'] ?? null) === $site) {
                $todo[$page->path] = $page;
            }
        }
        ksort($todo);

        $asked = 0;
        $total = $limit > 0 ? min($limit, \count($todo)) : \count($todo);
        $say = function (string $event, array $info = []): void {
            if ($this->progress !== null) {
                ($this->progress)($event, $info);
            }
        };
        foreach ($todo as $page) {
            if ($limit > 0 && $asked >= $limit) {
                $report->count('remaining');
                continue;
            }
            ++$asked;
            // The operator's terminal only: the run report and the audit name pages by pid (invariant 8)
            $patient = \is_array($page->frontmatter['patient'] ?? null) ? trim((string) ($page->frontmatter['patient']['name'] ?? '')) : '';
            $say('start', ['n' => $asked, 'total' => $total, 'label' => $patient !== '' ? $patient : (string) ($page->frontmatter['title'] ?? $page->pid)]);
            $own = \is_scalar($page->frontmatter['study_uid'] ?? null) ? (string) $page->frontmatter['study_uid'] : '';
            if ($own !== '') {
                $this->refresh($page, $own, $site, $actor, $apply, $report, $say);
                continue;
            }
            $day = Pacs::studyDay($page);
            if ($day === '') {
                $report->count('no_day');
                $say('done', ['status' => 'no exam day']);
                continue;
            }
            try {
                $rows = $this->pacs->lookup($page, $site, $day)['rows'];
            } catch (DicomException $e) {
                $report->count('error');
                $report->item($page->pid, $page->rev, 'error', $e->getMessage());
                $say('done', ['status' => 'error: ' . $e->getMessage()]);
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
            // A name match may import the CNP when every study of the day under that name agrees on one
            $dayCnps = array_unique(array_map(
                static fn (array $r): string => (string) $r['cnp'],
                array_filter($rows, static fn (array $r): bool => \in_array($r['match'], ['cnp', 'name'], true) && substr((string) $r['when'], 0, 10) === $day),
            ));
            $cnpSafe = \count($dayCnps) === 1 && reset($dayCnps) !== '';
            if (\count($matches) > 1) {
                // Several studies of one patient (a multi-part scan): the patient data is safe, which study is not
                if (!Pacs::samePatient($matches)) {
                    $report->count('ambiguous');
                    $report->item($page->pid, $page->rev, 'ambiguous', \count($matches) . ' studies on the day, not all one patient');
                    $say('done', ['status' => 'ambiguous: ' . \count($matches) . ' studies on the day, different patients']);
                    continue;
                }
                $byCnp = $cnpSafe;
                if (!$apply) {
                    $report->count('would_patient');
                    $say('done', ['status' => 'would fill the patient only (' . \count($matches) . ' studies on the day)']);
                    continue;
                }
                try {
                    $saved = $this->pacs->linkPatient($page, $actor, $matches[0], auto: true, fillCnp: $byCnp);
                } catch (InvalidArgumentException) {
                    $report->count('error');
                    $report->item($page->pid, $page->rev, 'error', 'mismatch');
                    $say('done', ['status' => 'error: mismatch']);
                    continue;
                } catch (RevisionConflictException) {
                    $report->count('error');
                    $report->item($page->pid, $page->rev, 'error', 'edited meanwhile');
                    $say('done', ['status' => 'error: edited meanwhile']);
                    continue;
                }
                if ($saved === null) {
                    $say('done', ['status' => 'nothing to add (' . \count($matches) . ' studies on the day)']);
                    continue;
                }
                $report->count('patient_only');
                $report->item($page->pid, $saved->rev, 'patient_only', \count($matches) . ' studies on the day; patient filled, no study linked');
                $this->audit->record('page.save', $actor, null, $saved->pid, $saved->path, $saved->rev, extra: ['via' => 'dicom-bulk', 'match' => $byCnp ? 'cnp' : 'name', 'studies' => \count($matches)]);
                $say('done', ['status' => 'patient filled, no study linked (' . \count($matches) . ' studies on the day)']);
                continue;
            }
            if ($matches === []) {
                $report->count('no_match');
                $say('done', ['status' => 'no match']);
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
                $say('done', ['status' => 'would link (' . $match['match'] . ')' . ($signed ? ', signed: would return to draft' : '')]);
                continue;
            }
            try {
                $saved = $this->pacs->link($page, $actor, $site, (string) $match['uid'], auto: true, fillCnp: $match['match'] === 'cnp' || $cnpSafe);
            } catch (InvalidArgumentException | DicomException $e) {
                $report->count('error');
                $report->item($page->pid, $page->rev, 'error', $e->getMessage());
                $say('done', ['status' => 'error: ' . $e->getMessage()]);
                continue;
            } catch (RevisionConflictException) {
                $report->count('error');
                $report->item($page->pid, $page->rev, 'error', 'edited meanwhile');
                $say('done', ['status' => 'error: edited meanwhile']);
                continue;
            }
            $held[(string) $match['uid']] = true;
            if ($saved === null) {
                $say('done', ['status' => 'nothing to add']);
                continue;
            }
            $report->count('linked');
            if ($signed) {
                $report->count('signed_to_draft');
                $report->item($page->pid, $saved->rev, 'signed_to_draft', 'linked; sign it again', ['match' => $match['match']]);
            }
            $this->audit->record('page.save', $actor, null, $saved->pid, $saved->path, $saved->rev, extra: ['via' => 'dicom-bulk', 'match' => $match['match']]);
            $say('done', ['status' => 'linked (' . $match['match'] . ')' . ($signed ? ', signed: now a draft' : '')]);
        }

        return $report;
    }

    /** @param array<string, mixed> $fm a report with a study but without what the PACS can tell: CNP, accession, institution */
    private static function wantsRefresh(array $fm): bool
    {
        $patient = \is_array($fm['patient'] ?? null) ? $fm['patient'] : [];
        foreach ([$patient['cnp'] ?? null, $fm['pacs_accession'] ?? null, $fm['pacs_institution'] ?? null] as $v) {
            if ($v === null || $v === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * A report that already holds its study: asked by that UID — exact, so the
     * CNP is certain — and only blanks are filled. Check mode counts the
     * candidates without asking the PACS.
     *
     * @param callable(string, array<string, mixed>): void $say
     */
    private function refresh(PageRecord $page, string $uid, string $site, string $actor, bool $apply, MaintenanceReport $report, callable $say): void
    {
        $signed = $page->status === 'signed';
        if (!$apply) {
            $report->count('would_refresh');
            if ($signed) {
                $report->count('would_unsign');
                $report->item($page->pid, $page->rev, 'would_unsign', 'signed; refreshing returns it to draft');
            }
            $say('done', ['status' => 'would refresh from its study' . ($signed ? ', signed: would return to draft' : '')]);

            return;
        }
        try {
            $saved = $this->pacs->link($page, $actor, $site, $uid, auto: true);
        } catch (InvalidArgumentException | DicomException $e) {
            $report->count('error');
            $report->item($page->pid, $page->rev, 'error', $e->getMessage());
            $say('done', ['status' => 'error: ' . $e->getMessage()]);

            return;
        } catch (RevisionConflictException) {
            $report->count('error');
            $report->item($page->pid, $page->rev, 'error', 'edited meanwhile');
            $say('done', ['status' => 'error: edited meanwhile']);

            return;
        }
        if ($saved === null) {
            $say('done', ['status' => 'nothing to add']);

            return;
        }
        $report->count('refreshed');
        if ($signed) {
            $report->count('signed_to_draft');
            $report->item($page->pid, $saved->rev, 'signed_to_draft', 'refreshed; sign it again');
        }
        $this->audit->record('page.save', $actor, null, $saved->pid, $saved->path, $saved->rev, extra: ['via' => 'dicom-bulk', 'match' => 'uid']);
        $say('done', ['status' => 'refreshed' . ($signed ? ', signed: now a draft' : '')]);
    }
}
