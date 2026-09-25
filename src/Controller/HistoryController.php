<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Diff;

/**
 * GET /{path}/history, POST /{path}/history/revert (docs/architecture-api.md
 * Table 1: "revision list + unified diff, both computed server-side").
 *
 * Same read entitlement as viewing the page itself: whoever can reach
 * `/{path}` can reach its history — a namespace grant or public/unlisted
 * direct-path access, resolved once through Index\Sqlite exactly like
 * PageController::view() does, never re-derived here.
 *
 * Plain SSR, not the mockup's radio-multiselect "compare selected" — a
 * per-row "diff vs previous" link and a from/to query string cover the
 * useful case without JavaScript; picking an arbitrary pair of revisions
 * to compare is deliberately out of scope for this slice (see
 * docs/BUILD_LOG.md). "unified" is the only diff view built — the
 * mockup's side-by-side and rendered toggles are not.
 */
final class HistoryController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
    ) {
    }

    public function history(Request $request, string $path, ?User $principal): Response
    {
        $indexed = $this->index->findByPath($path, $principal);
        if ($indexed === null) {
            throw new PageNotFoundException();
        }

        $revlog = $this->storage->revisions($path);

        $rows = [];
        $previousDocument = null;
        foreach ($revlog as $entry) {
            $document = $this->storage->readRevision($path, (int) $entry['n']);
            $rows[] = [
                'entry' => $entry,
                'counts' => $previousDocument !== null ? Diff::counts($previousDocument, $document) : null,
            ];
            $previousDocument = $document;
        }

        $from = self::queryInt($request, 'from');
        $to = self::queryInt($request, 'to');
        $diffLines = null;
        if ($from !== null && $to !== null) {
            try {
                $diffLines = Diff::lines($this->storage->readRevision($path, $from), $this->storage->readRevision($path, $to));
            } catch (PageNotFoundException) {
                // An invalid from/to just means no diff panel renders below
                // the list — not a 404 for the whole history page.
                $diffLines = null;
            }
        }

        $currentRev = $revlog === [] ? 0 : (int) $revlog[array_key_last($revlog)]['n'];

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/history.php',
            [
                'path' => $path,
                'rows' => $rows,
                'currentRev' => $currentRev,
                'from' => $from,
                'to' => $to,
                'diffLines' => $diffLines,
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($path))
              + ChromeVars::pageHeaderFromRow($indexed, $principal, 'history'),
            t('tabs.history') . ' · ' . (string) $indexed['title'],
        ));
    }

    /**
     * POST /{path}/history/revert { to }. A classic form action, not the
     * JSON /api/v1/pages/{path}/revert route — same shape as
     * AdminUsersController's actions, so "restore rev N" on this page
     * works with no JavaScript.
     */
    public function revert(Request $request, string $path, ?User $principal): Response
    {
        if ($principal === null || !$principal->canWrite($path)) {
            return Response::notFound();
        }

        parse_str($request->body, $fields);
        $to = isset($fields['to']) && ctype_digit((string) $fields['to']) ? (int) $fields['to'] : null;
        if ($to === null) {
            return Response::notFound();
        }

        $reverted = $this->storage->revert($path, $to, $principal->username);
        $this->audit->record('page.revert', $principal->username, $request, $reverted->pid, $reverted->path, $reverted->rev, extra: ['to' => $to]);

        return Response::redirect($request->basePath . '/' . $path . '/history');
    }

    private static function queryInt(Request $request, string $key): ?int
    {
        $value = $request->query[$key] ?? null;

        return \is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
