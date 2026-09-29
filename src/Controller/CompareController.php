<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

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
 * GET /{path}/compare?from=N&to=M (docs/architecture-api.md
 * Table 1: "compare two revisions server-side").
 *
 * Same read entitlement as viewing the page itself. Renders a word-level
 * diff of the two revisions' body text (TODO 13, 2026-09-28: red
 * strikethrough for a removed word, green for an added one, inline —
 * Support\Diff::words()), a track-changes read rather than the line
 * diff the revisions screen has. A revision whose frontmatter does not
 * parse falls back to the two raw documents side by side, since there is
 * no body text to tokenise. When no from/to is given, defaults to
 * previous→current so the page always renders a meaningful diff (a fresh
 * page with one revision compares that revision against itself — empty
 * diff, not an error).
 */
final class CompareController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly Render $render,
    ) {
    }

    public function compare(Request $request, string $path, ?User $principal): Response
    {
        $indexed = $this->index->findByPath($path, $principal);
        if ($indexed === null) {
            throw new PageNotFoundException();
        }

        $revlog = $this->storage->revisions($path);
        $currentRev = $revlog === [] ? 0 : (int) $revlog[array_key_last($revlog)]['n'];

        $from = self::queryInt($request, 'from');
        $to = self::queryInt($request, 'to');

        /* Defaults: previous → current */
        if ($from === null && $to === null) {
            if ($currentRev === 0) {
                $from = 0;
                $to = 0;
            } else {
                $to = $currentRev;
                $from = count($revlog) >= 2 ? (int) $revlog[count($revlog) - 2]['n'] : $currentRev;
            }
        }

        $revOptions = [];
        foreach ($revlog as $entry) {
            $revOptions[] = ['n' => (int) $entry['n'], 'ts' => (string) $entry['ts']];
        }

        // The two revisions side by side, each rendered from its own bytes
        // through the canonical renderer (invariant 4). A rev outside the
        // revlog is simply not shown.
        $panes = [];
        foreach ([$from, $to] as $rev) {
            if ($rev === null || $rev < 1 || $rev > $currentRev) {
                continue;
            }
            $panes[] = $this->pane($path, $rev, $revlog, $request->basePath);
        }

        // A word diff needs both sides' body text; a pane that fell back to
        // raw source (unparseable frontmatter) has none, so the template
        // falls back to the old side-by-side render for that case — the
        // same fallback a body too large for Diff::words() to diff safely
        // gets (Diff::wordsFits(), 2026-09-30 incident: see its docblock).
        $wordDiff = null;
        if (\count($panes) === 2 && $panes[0]['body'] !== null && $panes[1]['body'] !== null
            && Diff::wordsFits($panes[0]['body'], $panes[1]['body'])) {
            $wordDiff = Diff::words($panes[0]['body'], $panes[1]['body']);
        }

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/compare.php',
            [
                'path' => $path,
                'from' => $from,
                'to' => $to,
                'panes' => $panes,
                'wordDiff' => $wordDiff,
                'revOptions' => $revOptions,
                'currentRev' => $currentRev,
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($path))
              + ChromeVars::pageHeaderFromRow($indexed, $principal, 'compare'),
            t('tabs.compare') . ' · ' . (string) $indexed['title'],
        ));
    }

    /**
     * @param list<array<string, mixed>> $revlog
     *
     * @return array{rev: int, ts: string, title: string, html: ?string, raw: string, body: ?string}
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
            // Unparseable history is shown as source, never hidden
            return ['rev' => $rev, 'ts' => $ts, 'title' => '', 'html' => null, 'raw' => $raw, 'body' => null];
        }

        return [
            'rev' => $rev,
            'ts' => $ts,
            'title' => \is_string($frontmatter['title'] ?? null) ? $frontmatter['title'] : '',
            'html' => $this->render->toHtml($body, $basePath)->html,
            'raw' => $raw,
            'body' => $body,
        ];
    }

    private static function queryInt(Request $request, string $key): ?int
    {
        $value = $request->query[$key] ?? null;

        return \is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
