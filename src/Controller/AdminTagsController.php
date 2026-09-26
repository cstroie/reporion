<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Tags;

/**
 * GET /admin/tags, POST /admin/tags/rename { from, to } and POST
 * /admin/tags/merge { from[], into } — Admin → Tags, owner-only (decided
 * 2026-09-26). Every tag with its page count; renaming or merging goes
 * through Service\Tags (new revisions, signed reports left alone).
 */
final class AdminTagsController
{
    public function __construct(
        private readonly IndexInterface $index,
        private readonly Tags $tags,
    ) {
    }

    public function show(Request $request, ?User $principal, ?string $error = null): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-tags.php', [
            'tags' => $this->index->tagCounts(),
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

    /** @param list<string> $from */
    private function apply(Request $request, User $principal, array $from, string $into): Response
    {
        try {
            $result = $this->tags->merge($from, $into, $principal->username, $request);
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage());
        }

        return Response::redirect($request->basePath . '/admin/tags?changed=' . $result['changed'] . '&signed=' . $result['skippedSigned']);
    }
}
