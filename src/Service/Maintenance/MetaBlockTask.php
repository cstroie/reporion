<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Audit\AuditLog;
use Reporion\Storage\FlatFile;
use Reporion\Support\MetaBlock;
use Reporion\Support\MetaText;
use Reporion\Support\ReportPath;
use Throwable;

/**
 * pages:apply-meta-block — the imported DokuWiki `~~META: … ~~` block still
 * sitting, verbatim, in report bodies (TODO.md idea 10; `Support\MetaBlock`
 * parses the syntax, this task is what the ten keys *mean*). A key fills a
 * frontmatter field the importer left empty; a key that disagrees with a
 * field already set sends the whole page to review rather than guessing
 * which one is right (the same rule `Import\MetadataExtractor` and
 * `Support\MetaBlock` already state — a wrong value is worse than a blank
 * one). Only once a page has no disagreement does apply write it: the
 * frontmatter fills plus the block stripped from the body, in one new
 * revision. `&section` and `&fo` have no frontmatter field to fill and are
 * dropped with the rest of the block — neither is indexed or exported
 * anywhere today. A signed report is listed, never rewritten here (D3).
 */
final class MetaBlockTask implements MaintenanceTask
{
    public function __construct(
        private readonly FlatFile $storage,
        private readonly AuditLog $audit,
    ) {
    }

    public function name(): string
    {
        return 'pages:apply-meta-block';
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
        $changedKey = $apply ? 'applied' : 'would_apply';
        foreach ([$changedKey, 'review', 'unparseable', 'signed', ...($apply ? ['remaining'] : [])] as $key) {
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

            $block = MetaBlock::parse($page->body);
            if ($block === null) {
                continue;
            }
            if (!$block['ok']) {
                $report->count('unparseable');
                $report->item($page->pid, $page->rev, 'unparseable', implode('; ', $block['reasons']));
                continue;
            }

            [$updates, $reasons] = $this->reconcile($block['fields'], $page->frontmatter);
            if ($reasons !== []) {
                $report->count('review');
                $report->item($page->pid, $page->rev, 'review', implode('; ', $reasons), ['fields' => array_keys($updates)]);
                continue;
            }

            $data = ['fields' => array_keys($updates)];
            if ($page->status === 'signed') {
                $report->count('signed');
                $report->item($page->pid, $page->rev, 'signed', implode(', ', array_keys($updates)) ?: 'block only', $data);
                continue;
            }
            if (!$apply) {
                $report->count('would_apply');
                continue;
            }
            if ($limit > 0 && $written >= $limit) {
                // Past --limit: left for the next run
                $report->count('remaining');
                continue;
            }

            $frontmatter = $this->applyUpdates($page->frontmatter, $updates);
            $message = 'apply imported META block' . ($updates === [] ? '' : ' (' . implode(', ', array_keys($updates)) . ')');
            $saved = $this->storage->save($path, $frontmatter, $block['bodyWithoutBlock'], $page->rev, $actor, $message, auto: true);
            $this->audit->record('page.save', $actor, null, $saved->pid, $saved->path, $saved->rev, extra: ['reason' => 'meta-block-apply', 'fields' => array_keys($updates)]);
            ++$written;
            $report->count('applied');
        }

        return $report;
    }

    /**
     * Maps the block's ten keys onto frontmatter: `$updates` (dot-paths a
     * value fills, only ever into a field that is currently empty) and
     * `$reasons` (a key that disagrees with a value already there — the
     * whole page is left untouched when this is non-empty).
     *
     * @param array<string, string> $fields      MetaBlock::parse()'s ten keys
     * @param array<string, mixed>  $frontmatter
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function reconcile(array $fields, array $frontmatter): array
    {
        $updates = [];
        $reasons = [];

        $studyDate = $fields['date'] !== '' ? MetaBlock::parseDate($fields['date']) : null;
        if ($fields['date'] !== '' && $studyDate === null) {
            $reasons[] = '"&date" ("' . $fields['date'] . '") is not a recognised date';
        }
        $currentStudyDate = MetaText::text($frontmatter['study_date'] ?? null);
        $currentStudyDay = $currentStudyDate !== '' ? substr($currentStudyDate, 0, 10) : '';
        if ($studyDate !== null) {
            if ($currentStudyDay === '') {
                $updates['study_date'] = $studyDate;
            } elseif ($currentStudyDay !== $studyDate) {
                $reasons[] = '"&date" (' . $studyDate . ') disagrees with study_date (' . $currentStudyDay . ')';
            }
        }

        $name = trim($fields['name']);
        $currentName = MetaText::text($frontmatter['patient']['name'] ?? null);
        if ($name !== '') {
            if ($currentName === '') {
                $updates['patient.name'] = mb_strtoupper($name);
            } elseif (mb_strtolower($currentName) !== mb_strtolower($name)) {
                $reasons[] = '"&name" disagrees with patient.name';
            }
        }

        $sex = strtoupper(trim($fields['sex']));
        $currentSex = MetaText::text($frontmatter['patient']['sex'] ?? null);
        if ($sex !== '') {
            if (!\in_array($sex, ['M', 'F'], true)) {
                $reasons[] = '"&sex" ("' . $fields['sex'] . '") is neither M nor F';
            } elseif ($currentSex === '') {
                $updates['patient.sex'] = $sex;
            } elseif ($currentSex !== $sex) {
                $reasons[] = '"&sex" (' . $sex . ') disagrees with patient.sex (' . $currentSex . ')';
            }
        }

        $age = trim($fields['age']);
        if ($age !== '') {
            $studyYear = $studyDate !== null ? (int) substr($studyDate, 0, 4)
                : ($currentStudyDay !== '' ? (int) substr($currentStudyDay, 0, 4) : null);
            $born = $studyYear !== null ? MetaBlock::parseAge($age, $studyYear) : null;
            $currentBorn = $frontmatter['patient']['born'] ?? null;
            if ($born === null) {
                $reasons[] = '"&age" ("' . $age . '") could not be resolved to a birth year'
                    . ($studyYear === null ? ' (no study year known)' : '');
            } elseif ($currentBorn === null || $currentBorn === '') {
                $updates['patient.born'] = $born;
            } elseif ((int) $currentBorn !== $born) {
                $reasons[] = '"&age" implies born ' . $born . ', which disagrees with patient.born (' . $currentBorn . ')';
            }
        }

        $medic = trim($fields['medic']);
        $currentReferrer = MetaText::text($frontmatter['referrer'] ?? null);
        if ($medic !== '') {
            if ($currentReferrer === '') {
                $updates['referrer'] = $medic;
            } elseif (mb_strtolower($currentReferrer) !== mb_strtolower($medic)) {
                $reasons[] = '"&medic" disagrees with referrer';
            }
        }

        // &diag is the imported diagnosis, not the same fact as a hand-written
        // indication — filled when indication is blank, never flagged when it isn't
        $diag = trim($fields['diag']);
        if ($diag !== '' && MetaText::text($frontmatter['indication'] ?? null) === '') {
            $updates['indication'] = $diag;
        }

        $exam = trim($fields['exam']);
        $currentExamTitle = MetaText::text($frontmatter['exam_title'] ?? null);
        if ($exam !== '') {
            if ($currentExamTitle === '') {
                $updates['exam_title'] = $exam;
            } elseif (mb_strtolower($currentExamTitle) !== mb_strtolower($exam)) {
                $reasons[] = '"&exam" disagrees with exam_title';
            }
        }

        $secv = trim($fields['secv']);
        $currentSequences = $frontmatter['sequences'] ?? null;
        if ($secv !== '' && ($currentSequences === null || $currentSequences === [])) {
            $updates['sequences'] = MetaBlock::parseSequences($secv);
        }

        return [$updates, $reasons];
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @param array<string, mixed> $updates     dot-paths, e.g. "patient.name"
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
