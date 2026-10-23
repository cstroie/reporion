<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\MaintenanceBusyException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Maintenance\MaintenanceReport;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\ReportView;
use Reporion\Service\TagDictionary;
use Reporion\Service\TagCuration;
use Reporion\Support\TagProposals;

/**
 * GET /admin/tags, POST /admin/tags/rename { from, to }, POST
 * /admin/tags/merge { from[], into } and POST /admin/tags/dictionary
 * { tag, group, icd10, synonyms }. Admin → Tags, owner-only (decided
 * 2026-09-26). Every tag with its page count, group, ICD-10 code and
 * synonyms (Service\TagDictionary, phase 20) and the suggested merges;
 * renaming or merging goes through Service\Tags (new revisions, signed
 * reports left alone). ?edit={tag} opens a tag's dictionary entry;
 * ?into=&from=a,b prefills the merge form (a suggestion, never applied).
 *
 * POST /admin/tags/review/{id} { pick[], to[i], reject[] } (2026-10-15):
 * the proposals of a tags:review run the owner ticked, applied one by one
 * as system:admin on the owner's behalf (D41): a merge or rename keeps the
 * old names as synonyms, a generalisation does not (the detail stays in
 * the text), a drop takes the tag off; signed reports are left alone. The
 * ones marked "never suggest" go to data/tags.yaml's `_rejected`.
 */
final class AdminTagsController
{
    public function __construct(
        private readonly IndexInterface $index,
        private readonly TagCuration $curation,
        private readonly TagDictionary $dictionary,
        private readonly AuditLog $audit,
        private readonly ?MaintenanceRunner $maintenance = null,
    ) {
    }

    /** @param array{group: string, icd10: string, synonyms: list<string>}|null $posted an entry refused, shown back as typed */
    public function show(Request $request, ?User $principal, ?string $error = null, ?string $edit = null, ?array $posted = null): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        $counts = [];
        foreach ($this->index->tagCounts() as $row) {
            $counts[$row['tag']] = $row['n'];
        }
        $entries = $this->dictionary->all();
        // In use first (most pages first), then the dictionary's unused entries
        $rows = [];
        foreach ($counts as $tag => $n) {
            $rows[] = ['tag' => (string) $tag, 'n' => $n] + $this->dictionary->get((string) $tag);
        }
        foreach (array_diff_key($entries, $counts) as $tag => $entry) {
            $rows[] = ['tag' => (string) $tag, 'n' => 0] + $entry;
        }
        $runView = $this->maintenance !== null ? (new ReportView($this->maintenance, $this->index))->forRun((string) ($request->query['run'] ?? ''), $principal) : ['runId' => null, 'report' => null, 'running' => null, 'pages' => [], 'shownItems' => 0];
        $edit ??= \is_string($request->query['edit'] ?? null) && $request->query['edit'] !== '' ? $request->query['edit'] : null;

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-tags.php', [
            'tags' => $rows,
            'suggestions' => $this->dictionary->suggestedMerges($counts),
            'edit' => $edit,
            'editEntry' => $posted ?? ($edit !== null ? $this->dictionary->get($edit) : null),
            'mergeInto' => \is_string($request->query['into'] ?? null) ? $request->query['into'] : '',
            // Comma-joined (a tag has no comma): the query string holds strings only
            'mergeFrom' => array_values(array_filter(explode(',', (string) ($request->query['from'] ?? '')), static fn (string $t): bool => $t !== '')),
            'saved' => isset($request->query['saved']),
            'changed' => isset($request->query['changed']) ? (int) $request->query['changed'] : null,
            'skippedSigned' => (int) ($request->query['signed'] ?? 0),
            'conflicts' => (int) ($request->query['conflicts'] ?? 0),
            'error' => $error,
            'adminTab' => 'tags',
            'basePath' => $request->basePath,
            // The tag tasks (Admin → Maintenance's, moved here): their cards, and the report of the run just made
            'tasks' => $this->maintenance !== null ? array_intersect_key($this->maintenance->tasks(), array_flip(AdminMaintenanceController::TAG_TASKS)) : [],
            'busy' => isset($request->query['busy']),
            'maintError' => match ($request->query['maint'] ?? null) {
                'confirm' => t('admin.maint.err_confirm'),
                'invalid' => t('admin.maint.err_invalid'),
                default => null,
            },
            'review' => $this->reviewRows($runView['report'], $counts),
        ] + $runView
          + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.tags.title')), $error === null ? 200 : 422);
    }

    public function rename(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);

        return $this->apply($request, $principal, [(string) ($fields['from'] ?? '')], (string) ($fields['to'] ?? ''));
    }

    public function merge(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $from = array_values(array_filter((array) ($fields['from'] ?? []), static fn (mixed $tag): bool => \is_string($tag) && $tag !== ''));
        if ($from === []) {
            return $this->show($request, $principal, t('admin.tags.err_none'));
        }

        return $this->apply($request, $principal, $from, (string) ($fields['into'] ?? ''));
    }

    public function review(Request $request, string $id, ?User $principal): Response
    {
        $report = $principal?->isOwner === true ? $this->maintenance?->load($id) : null;
        if ($report === null || $report->task !== 'tags:review' || $report->finishedAt() === '') {
            throw new PageNotFoundException();
        }
        if (!$this->dictionary->writable()) {
            return $this->show($request, $principal, t('admin.tags.err_unreadable'));
        }
        parse_str($request->body, $fields);
        $indexes = static fn (string $key): array => array_values(array_unique(array_map('intval', array_filter((array) ($fields[$key] ?? []), 'is_numeric'))));

        $proposals = TagCuration::proposals($report);
        $targets = (array) ($fields['to'] ?? []);
        $picks = [];
        foreach ($indexes('pick') as $i) {
            if (isset($proposals[$i])) {
                $picks[] = ['proposal' => $proposals[$i], 'to' => \is_string($targets[$i] ?? null) ? $targets[$i] : null];
            }
        }
        $rejects = array_map(static fn (int $i): array => $proposals[$i], array_values(array_filter($indexes('reject'), static fn (int $i): bool => isset($proposals[$i]))));

        try {
            $done = $this->curation->apply($picks, $rejects, $principal->username, $request);
        } catch (MaintenanceBusyException) {
            return Response::redirect($request->basePath . '/admin/tags?busy=1');
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage());
        }

        return Response::redirect($request->basePath . '/admin/tags?run=' . rawurlencode($id) . '&changed=' . $done['changed'] . '&signed=' . $done['skippedSigned'] . '&conflicts=' . $done['conflicts'] . '#review');
    }

    public function saveEntry(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $tag = (string) ($fields['tag'] ?? '');
        $group = (string) ($fields['group'] ?? '');
        $icd10 = (string) ($fields['icd10'] ?? '');
        $synonyms = (string) ($fields['synonyms'] ?? '');
        try {
            $this->dictionary->set($tag, $group, $icd10, $synonyms);
        } catch (InvalidArgumentException $e) {
            $posted = ['group' => $group, 'icd10' => $icd10, 'synonyms' => array_map('trim', explode(',', $synonyms))];

            return $this->show($request, $principal, t('admin.tags.err_' . $e->getMessage()), $tag, $posted);
        }
        $this->audit->record('tags.dictionary', $principal->username, $request, extra: ['tag' => $tag]);

        return Response::redirect($request->basePath . '/admin/tags?saved=1');
    }

    /** @param list<string> $from */
    private function apply(Request $request, User $principal, array $from, string $into): Response
    {
        // Checked before any page is renamed: the dictionary merge comes after
        if (!$this->dictionary->writable()) {
            return $this->show($request, $principal, t('admin.tags.err_unreadable'));
        }
        try {
            $result = $this->curation->merge($from, trim($into), $principal->username, $request);
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage());
        }

        return Response::redirect($request->basePath . '/admin/tags?changed=' . $result['changed'] . '&signed=' . $result['skippedSigned'] . '&conflicts=' . $result['conflicts']);
    }

    /**
     * A finished tags:review run's proposals for the review table, each with
     * its index in the run, whether it is already done (none of its tags is
     * in use any more) and whether it was turned down for good
     *
     * @param array<string, int> $counts
     *
     * @return ?list<array{i: int, op: string, from: array<string, int>, to: string, reason: string, source: string, done: bool, rejected: bool}>
     */
    private function reviewRows(?MaintenanceReport $report, array $counts): ?array
    {
        if ($report === null || $report->task !== 'tags:review' || $report->finishedAt() === '') {
            return null;
        }
        $rejected = $this->dictionary->rejected();
        $rows = [];
        foreach (TagCuration::proposals($report) as $i => $p) {
            $from = [];
            foreach ($p['from'] as $tag) {
                $from[$tag] = $counts[$tag] ?? 0;
            }
            $op = $p['op'];
            $to = $p['to'];
            $rows[] = [
                'i' => $i,
                'op' => $op,
                'from' => $from,
                'to' => $to,
                'reason' => $p['reason'],
                'source' => $p['source'],
                'done' => array_sum($from) === 0,
                'rejected' => \in_array(TagProposals::key(['op' => $op, 'from' => array_keys($from), 'to' => $to]), $rejected, true),
            ];
        }

        return $rows;
    }
}
