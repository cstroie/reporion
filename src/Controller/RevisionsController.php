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
use Reporion\Service\Render;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Diff;
use Reporion\Support\DocumentFormat;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * GET /{path}/revisions, POST /{path}/revisions/revert (docs/architecture-api.md
 * Table 1). Named "Revisions", not "History" (2026-09-30): a page's own
 * revision list read as "history of the patient" often enough to be worth
 * the rename — this is the history of the *page*, unrelated to the Patient
 * tab's timeline.
 *
 * Absorbs the old Compare tab (2026-09-30): both screens did the same
 * thing — take two revisions, show the result — as two separate pickers
 * (this page's radio-dot rows, Compare's own from/to selects) and separate
 * renders. One screen now: the row picker (unchanged) chooses from/to,
 * defaulting to previous→current same as Compare did; a style switch picks
 * the render:
 * - **word** (default) — Compare's track-changes read (Support\Diff::words()).
 *   Needs both sides' body text to parse and Diff::wordsFits() to hold
 *   (2026-09-30 incident: a body too large exhausted a PHP-FPM worker, see
 *   `Diff`'s docblock) — either failing falls back to **line**.
 * - **line** — this screen's original unified diff (Support\Diff::lines()).
 *   Works on raw bytes unconditionally, no parse, cheap regardless of size
 *   (a report's *line* count stays small even when its *word* count
 *   doesn't) — the universal fallback as well as its own style.
 * - **side** — Compare's other original render, two full pages side by
 *   side through the canonical renderer (invariant 4), unparseable
 *   frontmatter falling back to that side's raw source. No diffing, so no
 *   size concern; a reading view, not a change view.
 *
 * Same read entitlement as viewing the page itself: whoever can reach
 * `/{path}` can reach its revisions — a namespace grant or public/unlisted
 * direct-path access, resolved once through Index\Sqlite exactly like
 * PageController::view() does, never re-derived here.
 */
final class RevisionsController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
        private readonly Render $render,
    ) {
    }

    public function revisions(Request $request, string $path, ?User $principal): Response
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

        $currentRev = $revlog === [] ? 0 : (int) $revlog[array_key_last($revlog)]['n'];

        $from = self::queryInt($request, 'from');
        $to = self::queryInt($request, 'to');
        // Defaults: previous → current (Compare's old default) — nothing to
        // default to with fewer than two revisions, same as before.
        if ($from === null && $to === null && \count($revlog) >= 2) {
            $to = $currentRev;
            $from = (int) $revlog[\count($revlog) - 2]['n'];
        }

        $requestedStyleRaw = (string) ($request->query['style'] ?? '');
        $requestedStyle = \in_array($requestedStyleRaw, ['line', 'side'], true) ? $requestedStyleRaw : 'word';
        $diff = $this->buildDiff($path, $from, $to, $currentRev, $revlog, $requestedStyle, $request->basePath);

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/revisions.php',
            [
                'path' => $path,
                'rows' => $rows,
                'currentRev' => $currentRev,
                'from' => $from,
                'to' => $to,
                'requestedStyle' => $requestedStyle,
                'diff' => $diff,
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($path))
              + ChromeVars::pageHeaderFromRow($indexed, $principal, 'revisions'),
            t('tabs.revisions') . ' · ' . (string) $indexed['title'],
        ));
    }

    /**
     * @param list<array<string, mixed>> $revlog
     *
     * @return ?array{
     *     style: 'word'|'line'|'side',
     *     ops: ?list<array{op: string, line: string}>,
     *     panes: ?list<array{rev: int, ts: string, title: string, html: ?string, raw: string}>,
     *     fromTs: string, toTs: string,
     * }
     */
    private function buildDiff(string $path, ?int $from, ?int $to, int $currentRev, array $revlog, string $requestedStyle, string $basePath): ?array
    {
        if ($from === null || $to === null || $from < 1 || $to < 1 || $from > $currentRev || $to > $currentRev) {
            return null;
        }

        $fromTs = $toTs = '';
        foreach ($revlog as $entry) {
            if ((int) $entry['n'] === $from) {
                $fromTs = (string) $entry['ts'];
            }
            if ((int) $entry['n'] === $to) {
                $toTs = (string) $entry['ts'];
            }
        }

        if ($requestedStyle === 'side') {
            $panes = [
                $this->pane($path, $from, $revlog, $basePath),
                $this->pane($path, $to, $revlog, $basePath),
            ];

            return ['style' => 'side', 'ops' => null, 'panes' => $panes, 'fromTs' => $fromTs, 'toTs' => $toTs];
        }

        $fromRaw = $this->storage->readRevision($path, $from);
        $toRaw = $this->storage->readRevision($path, $to);

        $ops = null;
        $style = $requestedStyle;
        if ($requestedStyle === 'word') {
            try {
                [, $fromBody] = DocumentFormat::parse($fromRaw);
                [, $toBody] = DocumentFormat::parse($toRaw);
                if (Diff::wordsFits($fromBody, $toBody)) {
                    $ops = Diff::words($fromBody, $toBody);
                }
            } catch (RuntimeException | ParseException) {
                // Unparseable frontmatter: fall through to the line diff,
                // which works on the raw bytes and needs no parse at all.
            }
        }
        if ($ops === null) {
            $ops = Diff::lines($fromRaw, $toRaw);
            $style = 'line';
        }

        return ['style' => $style, 'ops' => $ops, 'panes' => null, 'fromTs' => $fromTs, 'toTs' => $toTs];
    }

    /**
     * @param list<array<string, mixed>> $revlog
     *
     * @return array{rev: int, ts: string, title: string, html: ?string, raw: string}
     */
    private function pane(string $path, int $rev, array $revlog, string $basePath): array
    {
        $ts = '';
        foreach ($revlog as $entry) {
            if ((int) $entry['n'] === $rev) {
                $ts = (string) $entry['ts'];
            }
        }

        $raw = $this->storage->readRevision($path, $rev);
        try {
            [$frontmatter, $body] = DocumentFormat::parse($raw);
        } catch (RuntimeException | ParseException) {
            // Unparseable is shown as source, never hidden
            return ['rev' => $rev, 'ts' => $ts, 'title' => '', 'html' => null, 'raw' => $raw];
        }

        return [
            'rev' => $rev,
            'ts' => $ts,
            'title' => \is_string($frontmatter['title'] ?? null) ? $frontmatter['title'] : '',
            'html' => $this->render->toHtml($body, $basePath)->html,
            'raw' => $raw,
        ];
    }

    /**
     * POST /{path}/revisions/revert { to }. A classic form action, not the
     * JSON /api/v1/pages/{path}/revert route — same shape as
     * AdminUsersController's actions, so "restore rev N" on this page
     * works with no JavaScript.
     */
    public function revert(Request $request, string $path, ?User $principal): Response
    {
        if ($principal === null || !$principal->canWrite($path)) {
            throw new PageNotFoundException();
        }

        parse_str($request->body, $fields);
        $to = isset($fields['to']) && ctype_digit((string) $fields['to']) ? (int) $fields['to'] : null;
        if ($to === null) {
            throw new PageNotFoundException();
        }

        $reverted = $this->storage->revert($path, $to, $principal->username);
        $this->audit->record('page.revert', $principal->username, $request, $reverted->pid, $reverted->path, $reverted->rev, extra: ['to' => $to]);

        return Response::redirect($request->basePath . '/' . $path . '/revisions');
    }

    private static function queryInt(Request $request, string $key): ?int
    {
        $value = $request->query[$key] ?? null;

        return \is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
