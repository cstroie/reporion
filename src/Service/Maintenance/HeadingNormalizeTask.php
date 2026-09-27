<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Audit\AuditLog;
use Reporion\Support\HeadingNormalizer;
use Reporion\Storage\FlatFile;
use Reporion\Support\MetaText;
use Reporion\Support\ReportPath;
use Throwable;

/**
 * pages:normalize-headings — brings every report's headings to the one
 * shape (Support\HeadingNormalizer, docs/FORMATS.md §11): `#` the patient's
 * name, `##` each exam, `###` its sections. A report with no `exam_title`
 * gets one from its exam heading(s), so its PDF is titled by the exam
 * (D30). Apply writes one new revision per report it changes; signed
 * reports are listed, never rewritten here (D3). What the rules cannot
 * place is listed for review and left as it is. Pages are named by pid.
 */
final class HeadingNormalizeTask implements MaintenanceTask
{
    public function __construct(
        private readonly FlatFile $storage,
        private readonly AuditLog $audit,
    ) {
    }

    public function name(): string
    {
        return 'pages:normalize-headings';
    }

    public function modes(): array
    {
        return [self::CHECK, self::APPLY];
    }

    public function options(array $raw): array
    {
        $limit = $raw['limit'] ?? 0;

        return ['limit' => is_numeric($limit) && (int) $limit > 0 ? (int) $limit : 0];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $apply = $mode === self::APPLY;
        $limit = (int) $options['limit'];
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        $changedKey = $apply ? 'normalized' : 'would_normalize';
        foreach ([$changedKey, 'unchanged', 'review', 'signed', ...($apply ? ['remaining'] : [])] as $key) {
            $report->count($key, 0);
        }

        $written = 0;
        foreach ($this->storage->allPaths() as $path) {
            if (!ReportPath::isReport($path)) {
                continue;
            }
            try {
                $page = $this->storage->read($path);
            } catch (Throwable) {
                continue;
            }

            $result = HeadingNormalizer::normalize($page->body, $page->frontmatter);
            if ($result['outcome'] === HeadingNormalizer::REVIEW) {
                $report->count('review');
                $report->item($page->pid, $page->rev, 'review', $result['shape'] . ' — ' . implode(', ', $result['reasons']), ['shape' => $result['shape'], 'reasons' => $result['reasons']]);
                continue;
            }

            $frontmatter = $page->frontmatter;
            $fillTitle = $result['exams'] !== [] && MetaText::text($frontmatter['exam_title'] ?? null) === '';
            if ($fillTitle) {
                $frontmatter['exam_title'] = implode(' + ', $result['exams']);
            }
            if ($result['outcome'] === HeadingNormalizer::UNCHANGED && !$fillTitle) {
                $report->count('unchanged');
                continue;
            }

            $detail = $result['shape'] . ($fillTitle ? ' + exam_title' : '');
            $data = ['shape' => $result['shape'], 'exams' => \count($result['exams']), 'exam_title' => $fillTitle];
            if ($page->status === 'signed') {
                $report->count('signed');
                $report->item($page->pid, $page->rev, 'signed', $detail, $data);
                continue;
            }
            // Only what needs a reader becomes an item (review, signed): a few
            // thousand normalized pages would bury it, and each write already
            // has its own page.save audit line
            if (!$apply) {
                $report->count('would_normalize');
                continue;
            }
            if ($limit > 0 && $written >= $limit) {
                // Past --limit: left for the next run
                $report->count('remaining');
                continue;
            }

            $saved = $this->storage->save($path, $frontmatter, $result['body'], $page->rev, $actor, 'normalize report headings');
            $this->audit->record('page.save', $actor, null, $saved->pid, $saved->path, $saved->rev, extra: ['reason' => 'heading-normalize']);
            ++$written;
            $report->count('normalized');
        }

        return $report;
    }
}
