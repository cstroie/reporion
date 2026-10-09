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
use Reporion\Support\ReportName;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * GET /{path}/revisions, POST /{path}/revisions/revert (docs/architecture-api.md
 * Table 1). Named "Revisions", not "History" (2026-09-30): a page's own
 * revision list read as "history of the patient" often enough to be worth
 * the rename — this is the history of the *page*, unrelated to the Timeline
 * tab.
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
 * **Revision zero (2026-10-01)**: when the page's frontmatter names a
 * `template` the caller can read, that template is listed below rev 1 as
 * revision 0 and can be compared like any other — the report against what
 * it started from. Virtual, never stored (history stays append-only,
 * invariant 3); no Restore. It is the template as it is now. A comparison
 * with it is of bodies only, the report's name heading left out
 * (Support\ReportName): frontmatter and the patient's name are not what a
 * template is about.
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
        $template = $this->template($path, $principal);

        $rows = [];
        $previousDocument = null;
        foreach ($revlog as $entry) {
            $document = $this->storage->readRevision($path, (int) $entry['n']);
            $counts = $previousDocument !== null ? Diff::counts($previousDocument, $document) : null;
            // Rev 1's change is counted from the template, when there is one
            if ($previousDocument === null && $template !== null) {
                $counts = Diff::counts($template['body'], self::bodyOf($document));
            }
            $rows[] = ['entry' => $entry, 'counts' => $counts];
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
        } elseif ($from === null && $to === null && $template !== null && $currentRev >= 1) {
            // One revision but a template: the report against what it started from
            $to = $currentRev;
            $from = 0;
        }

        $requestedStyleRaw = (string) ($request->query['style'] ?? '');
        $requestedStyle = \in_array($requestedStyleRaw, ['line', 'side'], true) ? $requestedStyleRaw : 'word';
        $diff = $this->buildDiff($path, $from, $to, $currentRev, $revlog, $requestedStyle, $request->basePath, $template);

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
                'template' => $template,
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
    private function buildDiff(string $path, ?int $from, ?int $to, int $currentRev, array $revlog, string $requestedStyle, string $basePath, ?array $template = null): ?array
    {
        $lowest = $template !== null ? 0 : 1;
        if ($from === null || $to === null || $from < $lowest || $to < $lowest || $from > $currentRev || $to > $currentRev) {
            return null;
        }
        if ($from === 0 || $to === 0) {
            return $this->templateDiff($path, $from, $to, $revlog, $requestedStyle, $basePath, $template);
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
     * The template the page names, as revision 0 — null when it names none,
     * or one the caller cannot read (the listing predicate, invariant 6).
     *
     * @return ?array{path: string, title: string, body: string, ts: string, by: string}
     */
    private function template(string $path, ?User $principal): ?array
    {
        $name = $this->storage->read($path)->frontmatter['template'] ?? null;
        if (!\is_string($name) || trim($name, ': ') === '') {
            return null;
        }
        $name = trim($name, ': ');
        $row = $this->index->findByPath($name, $principal);
        if ($row === null || $name === $path) {
            return null;
        }
        $record = $this->storage->read($name);

        return [
            'path' => $name,
            'title' => (string) ($row['title'] ?? ''),
            'body' => $record->body,
            'ts' => (string) ($row['updated'] ?? ''),
            'by' => (string) ($row['updated_by'] ?? ''),
        ];
    }

    /** A revision's body without its frontmatter or the report's name heading: what a template is compared with */
    private static function bodyOf(string $raw): string
    {
        try {
            [$frontmatter, $body] = DocumentFormat::parse($raw);
        } catch (RuntimeException | ParseException) {
            return $raw;
        }

        return ReportName::withoutNameHeading($body, $frontmatter);
    }

    /**
     * A comparison with revision 0: bodies only (see the class docblock).
     *
     * @param list<array<string, mixed>> $revlog
     * @param array{path: string, title: string, body: string, ts: string, by: string} $template
     *
     * @return array<string, mixed>
     */
    private function templateDiff(string $path, int $from, int $to, array $revlog, string $requestedStyle, string $basePath, array $template): array
    {
        $side = function (int $rev) use ($path, $revlog, $basePath, $template): array {
            if ($rev === 0) {
                return ['rev' => 0, 'ts' => $template['ts'], 'title' => $template['title'], 'body' => $template['body']];
            }
            $ts = '';
            foreach ($revlog as $entry) {
                if ((int) $entry['n'] === $rev) {
                    $ts = (string) $entry['ts'];
                }
            }

            return ['rev' => $rev, 'ts' => $ts, 'title' => '', 'body' => self::bodyOf($this->storage->readRevision($path, $rev))];
        };
        $a = $side($from);
        $b = $side($to);

        if ($requestedStyle === 'side') {
            $pane = fn (array $s): array => ['rev' => $s['rev'], 'ts' => $s['ts'], 'title' => $s['title'], 'html' => $this->render->toHtml($s['body'], $basePath)->html, 'raw' => $s['body']];

            return ['style' => 'side', 'ops' => null, 'panes' => [$pane($a), $pane($b)], 'fromTs' => $a['ts'], 'toTs' => $b['ts']];
        }
        $style = $requestedStyle === 'word' && Diff::wordsFits($a['body'], $b['body']) ? 'word' : 'line';
        $ops = $style === 'word' ? Diff::words($a['body'], $b['body']) : Diff::lines($a['body'], $b['body']);

        return ['style' => $style, 'ops' => $ops, 'panes' => null, 'fromTs' => $a['ts'], 'toTs' => $b['ts']];
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
