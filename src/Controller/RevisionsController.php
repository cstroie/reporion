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
use Reporion\Support\CompareSections;
use Reporion\Support\Diff;
use Reporion\Support\DiffView;
use Reporion\Support\DocumentFormat;
use Reporion\Support\ExamTemplates;
use Reporion\Support\PageTitle;
use Reporion\Support\ReportName;
use Reporion\Support\TemplateHeadings;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * GET /{path}/revisions, POST /{path}/revisions/revert (docs/architecture-api.md
 * Table 1). Named "Revisions", not "History" (2026-09-30): a page's own
 * revision list read as "history of the patient" often enough to be worth
 * the rename: this is the history of the *page*, unrelated to the Timeline
 * tab.
 *
 * Absorbs the old Compare tab (2026-09-30): both screens did the same
 * thing: take two revisions, show the result: as two separate pickers
 * (this page's radio-dot rows, Compare's own from/to selects) and separate
 * renders. One screen now: the row picker (unchanged) chooses from/to,
 * defaulting to previous→current same as Compare did; a style switch picks
 * the render:
 * - **split** (default, 2026-10-21): two aligned columns (Support\DiffView).
 * - **word**. Compare's track-changes read (Support\Diff::words()).
 *   Needs both sides' body text to parse and Diff::wordsFits() to hold
 *   (2026-09-30 incident: a body too large exhausted a PHP-FPM worker, see
 *   `Diff`'s docblock): either failing falls back to **line**.
 * - **line**: this screen's original unified diff (Support\Diff::lines()).
 *   Works on raw bytes unconditionally, no parse, cheap regardless of size
 *   (a report's *line* count stays small even when its *word* count
 *   doesn't): the universal fallback as well as its own style.
 * - **side**: Compare's other original render, two full pages side by
 *   side through the canonical renderer (invariant 4), unparseable
 *   frontmatter falling back to that side's raw source. No diffing, so no
 *   size concern; a reading view, not a change view.
 *
 * **Revision zero (2026-10-01)**: when the page's frontmatter names a
 * `template` the caller can read, that template is listed below rev 1 as
 * revision 0 and can be compared like any other: the report against what
 * it started from. Virtual, never stored (history stays append-only,
 * invariant 3); no Restore. It is the template as it is now. A comparison
 * with it is of bodies only, the report's name heading left out
 * (Support\ReportName): frontmatter and the patient's name are not what a
 * template is about.
 *
 * Same read entitlement as viewing the page itself: whoever can reach
 * `/{path}` can reach its revisions: a namespace grant or public/unlisted
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
        // Every revision read once, here: the list's counts and the diff below both use them
        $raw = [];
        foreach ($revlog as $entry) {
            $raw[(int) $entry['n']] = $this->storage->readRevision($path, (int) $entry['n']);
        }
        $template = $this->template($path, $raw === [] ? null : $raw[array_key_last($raw)], $principal);

        $rows = [];
        $previousDocument = null;
        foreach ($revlog as $entry) {
            $document = $raw[(int) $entry['n']];
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
        // Defaults: previous → current (Compare's old default): nothing to
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
        $requestedStyle = \in_array($requestedStyleRaw, ['word', 'line', 'side'], true) ? $requestedStyleRaw : 'split';
        $ignoreSpace = (string) ($request->query['ws'] ?? '') === '1';
        $diff = $this->buildDiff($path, $from, $to, $currentRev, $revlog, $raw, $requestedStyle, $request->basePath, $template, $ignoreSpace);

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/revisions.php',
            [
                'path' => $path,
                'rows' => $rows,
                'currentRev' => $currentRev,
                'from' => $from,
                'to' => $to,
                'requestedStyle' => $requestedStyle,
                'ignoreSpace' => $ignoreSpace,
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
     * @param array<int, string> $raw revisions already read, by number
     *
     * @return ?array{
     *     style: 'word'|'line'|'split'|'side',
     *     ops: ?list<array{op: string, line: string}>,
     *     rows: ?list<array<string, mixed>>,
     *     stats?: array{add: int, remove: int, unit: string},
     *     panes: ?list<array{rev: int, ts: string, title: string, html: ?string, raw: string}>,
     *     fromTs: string, toTs: string,
     * }
     */
    private function buildDiff(string $path, ?int $from, ?int $to, int $currentRev, array $revlog, array $raw, string $requestedStyle, string $basePath, ?array $template = null, bool $ignoreSpace = false): ?array
    {
        $lowest = $template !== null ? 0 : 1;
        if ($from === null || $to === null || $from < $lowest || $to < $lowest || $from > $currentRev || $to > $currentRev) {
            return null;
        }
        if ($from === 0 || $to === 0) {
            return $this->templateDiff($path, $from, $to, $revlog, $raw, $requestedStyle, $basePath, $template, $ignoreSpace);
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
                $this->pane($path, $from, $revlog, $raw, $basePath),
                $this->pane($path, $to, $revlog, $raw, $basePath),
            ];

            return ['style' => 'side', 'ops' => null, 'rows' => null, 'stats' => null, 'panes' => $panes, 'aligned' => self::align($panes), 'fromTs' => $fromTs, 'toTs' => $toTs];
        }

        $fromRaw = $this->revision($path, $from, $raw);
        $toRaw = $this->revision($path, $to, $raw);

        if ($requestedStyle === 'word') {
            try {
                [, $fromBody] = DocumentFormat::parse($fromRaw);
                [, $toBody] = DocumentFormat::parse($toRaw);
                if (Diff::wordsFits($fromBody, $toBody)) {
                    return $this->wordDiff($fromBody, $toBody, $fromTs, $toTs, $ignoreSpace);
                }
            } catch (RuntimeException | ParseException) {
                // Unparseable frontmatter: fall through to the line diff,
                // which works on the raw bytes and needs no parse at all.
            }
            $requestedStyle = 'line';
        }

        return $this->lineDiff($fromRaw, $toRaw, $requestedStyle, $fromTs, $toTs, $ignoreSpace);
    }

    /**
     * The two rendered panes lined up section by section (Support\CompareSections, as the
     * report-vs-prior Compare does): the pane picked first on the left. Null when a side is shown
     * as source.
     *
     * @param list<array{rev: int, ts: string, title: string, html: ?string, raw: string}> $panes
     *
     * @return ?array{rows: list<array{0: string, 1: string}>, reordered: bool}
     */
    private static function align(array $panes): ?array
    {
        if ($panes[0]['html'] === null || $panes[1]['html'] === null) {
            return null;
        }
        // align() keeps its first argument's order and puts it on the left: the right pane leads, then the columns swap back
        $aligned = CompareSections::align($panes[1]['html'], $panes[0]['html']);
        $aligned['rows'] = array_map(static fn (array $r): array => [$r[1], $r[0]], $aligned['rows']);

        return $aligned;
    }

    /**
     * The prose view: the word diff of two bodies, with the words added and removed counted.
     *
     * @return array<string, mixed>
     */
    private function wordDiff(string $from, string $to, string $fromTs, string $toTs, bool $ignoreSpace): array
    {
        $ops = Diff::words($from, $to, $ignoreSpace);
        $add = 0;
        $remove = 0;
        foreach ($ops as $op) {
            $n = preg_match_all('/\S+/u', $op['line']);
            if ($op['op'] === 'add') {
                $add += $n;
            } elseif ($op['op'] === 'remove') {
                $remove += $n;
            }
        }

        return ['style' => 'word', 'ops' => $ops, 'rows' => null, 'stats' => ['add' => $add, 'remove' => $remove, 'unit' => 'words'], 'panes' => null, 'fromTs' => $fromTs, 'toTs' => $toTs];
    }

    /**
     * The unified ('line') or two-column ('split') view of two texts.
     *
     * @return array<string, mixed>
     */
    private function lineDiff(string $from, string $to, string $style, string $fromTs, string $toTs, bool $ignoreSpace): array
    {
        $view = DiffView::chunks($from, $to, $ignoreSpace);
        $rows = $style === 'split' ? DiffView::split($view['chunks']) : DiffView::unified($view['chunks']);

        return ['style' => $style, 'ops' => null, 'rows' => $rows, 'stats' => ['add' => $view['add'], 'remove' => $view['remove'], 'unit' => 'lines'], 'panes' => null, 'fromTs' => $fromTs, 'toTs' => $toTs];
    }

    /**
     * @param list<array<string, mixed>> $revlog
     * @param array<int, string> $raw
     *
     * @return array{rev: int, ts: string, title: string, html: ?string, raw: string}
     */
    private function pane(string $path, int $rev, array $revlog, array $raw, string $basePath): array
    {
        $ts = '';
        foreach ($revlog as $entry) {
            if ((int) $entry['n'] === $rev) {
                $ts = (string) $entry['ts'];
            }
        }

        $document = $this->revision($path, $rev, $raw);
        try {
            [$frontmatter, $body] = DocumentFormat::parse($document);
        } catch (RuntimeException | ParseException) {
            // Unparseable is shown as source, never hidden
            return ['rev' => $rev, 'ts' => $ts, 'title' => '', 'html' => null, 'raw' => $document];
        }

        return [
            'rev' => $rev,
            'ts' => $ts,
            'title' => PageTitle::of($frontmatter, $body),
            'html' => $this->render->body($body, $frontmatter, $basePath)->html,
            'raw' => $document,
        ];
    }

    /**
     * The templates the page names, as revision 0: null when it names none the
     * caller can read (the listing predicate, invariant 6). A report of several
     * exams is the concatenation of its exams' templates, in exam order, each
     * without its `#` name heading, as the report's own body is built; one
     * template used by two exams counts twice, as the report holds both exams.
     * $current is the newest revision, already read: the page as it is now (D2).
     *
     * @return ?array{path: string, paths: list<string>, title: string, body: string, ts: string, by: string}
     */
    private function template(string $path, ?string $current, ?User $principal): ?array
    {
        try {
            $names = $current !== null ? array_column(ExamTemplates::of(DocumentFormat::parse($current)[0]), 1) : [];
        } catch (RuntimeException | ParseException) {
            $names = [];
        }

        $bodies = [];
        $paths = [];
        $titles = [];
        $ts = '';
        $by = '';
        foreach ($names as $name) {
            $name = trim((string) $name, ': ');
            if ($name === '' || $name === $path) {
                continue;
            }
            $row = $this->index->findByPath($name, $principal);
            if ($row === null) {
                continue;
            }
            $bodies[] = trim(TemplateHeadings::withoutName($this->storage->read($name)->body));
            if (!\in_array($name, $paths, true)) {
                $paths[] = $name;
                $titles[] = (string) ($row['title'] ?? '');
            }
            // The newest of the templates dates the whole
            if ((string) ($row['updated'] ?? '') >= $ts) {
                $ts = (string) ($row['updated'] ?? '');
                $by = (string) ($row['updated_by'] ?? '');
            }
        }
        if ($bodies === []) {
            return null;
        }

        return [
            'path' => $paths[0],
            'paths' => $paths,
            'title' => implode(' + ', array_filter($titles, static fn (string $t): bool => $t !== '')),
            'body' => implode("\n\n", $bodies) . "\n",
            'ts' => $ts,
            'by' => $by,
        ];
    }

    /** A revision's body without its frontmatter or the report's name heading: what a template is compared with */
    /** @return array<string, mixed> a revision's frontmatter; [] when it cannot be read */
    private static function frontmatterOf(string $raw): array
    {
        try {
            return DocumentFormat::parse($raw)[0];
        } catch (RuntimeException | ParseException) {
            return [];
        }
    }

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
     * @param array<int, string> $raw
     * @param array{path: string, title: string, body: string, ts: string, by: string} $template
     *
     * @return array<string, mixed>
     */
    private function templateDiff(string $path, int $from, int $to, array $revlog, array $raw, string $requestedStyle, string $basePath, array $template, bool $ignoreSpace = false): array
    {
        $side = function (int $rev) use ($path, $revlog, $raw, $template): array {
            if ($rev === 0) {
                return ['rev' => 0, 'ts' => $template['ts'], 'title' => $template['title'], 'body' => TemplateHeadings::withoutName($template['body']), 'fm' => []];
            }
            $ts = '';
            foreach ($revlog as $entry) {
                if ((int) $entry['n'] === $rev) {
                    $ts = (string) $entry['ts'];
                }
            }

            $document = $this->revision($path, $rev, $raw);

            return ['rev' => $rev, 'ts' => $ts, 'title' => '', 'body' => self::bodyOf($document), 'fm' => self::frontmatterOf($document)];
        };
        $a = $side($from);
        $b = $side($to);

        if ($requestedStyle === 'side') {
            $pane = fn (array $s): array => ['rev' => $s['rev'], 'ts' => $s['ts'], 'title' => $s['title'], 'html' => $this->render->body($s['body'], $s['fm'], $basePath)->html, 'raw' => $s['body']];

            return ['style' => 'side', 'ops' => null, 'rows' => null, 'stats' => null, 'panes' => [$pane($a), $pane($b)], 'aligned' => self::align([$pane($a), $pane($b)]), 'fromTs' => $a['ts'], 'toTs' => $b['ts']];
        }
        if ($requestedStyle === 'word' && Diff::wordsFits($a['body'], $b['body'])) {
            return $this->wordDiff($a['body'], $b['body'], $a['ts'], $b['ts'], $ignoreSpace);
        }

        return $this->lineDiff($a['body'], $b['body'], $requestedStyle === 'split' ? 'split' : 'line', $a['ts'], $b['ts'], $ignoreSpace);
    }

    /**
     * POST /{path}/revisions/revert { to }. A classic form action, not the
     * JSON /api/v1/pages/{path}/revert route: same shape as
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

    /** @param array<int, string> $raw revisions already read; one not among them is read now */
    private function revision(string $path, int $rev, array $raw): string
    {
        return $raw[$rev] ?? $this->storage->readRevision($path, $rev);
    }

    private static function queryInt(Request $request, string $key): ?int
    {
        $value = $request->query[$key] ?? null;

        return \is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
