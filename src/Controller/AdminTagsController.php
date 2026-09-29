<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\TagDictionary;
use Reporion\Service\Tags;

/**
 * GET /admin/tags, POST /admin/tags/rename { from, to }, POST
 * /admin/tags/merge { from[], into } and POST /admin/tags/dictionary
 * { tag, group, icd10, synonyms } — Admin → Tags, owner-only (decided
 * 2026-09-26). Every tag with its page count, group, ICD-10 code and
 * synonyms (Service\TagDictionary, phase 20) and the suggested merges;
 * renaming or merging goes through Service\Tags (new revisions, signed
 * reports left alone). ?edit={tag} opens a tag's dictionary entry;
 * ?into=&from=a,b prefills the merge form (a suggestion, never applied).
 */
final class AdminTagsController
{
    public function __construct(
        private readonly IndexInterface $index,
        private readonly Tags $tags,
        private readonly TagDictionary $dictionary,
        private readonly AuditLog $audit,
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
        foreach ([...$counts, ...array_fill_keys(array_keys(array_diff_key($entries, $counts)), 0)] as $tag => $n) {
            $rows[] = ['tag' => (string) $tag, 'n' => $n] + $this->dictionary->get((string) $tag);
        }
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
            'error' => $error,
            'adminTab' => 'tags',
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.tags.title')), $error === null ? 200 : 422);
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
        try {
            $result = $this->tags->merge($from, $into, $principal->username, $request);
            // The merged names stay findable: they become $into's synonyms
            $this->dictionary->merge($from, trim($into));
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage());
        }

        return Response::redirect($request->basePath . '/admin/tags?changed=' . $result['changed'] . '&signed=' . $result['skippedSigned']);
    }
}
