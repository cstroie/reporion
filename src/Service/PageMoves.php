<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Storage\FlatFile;
use Reporion\Storage\PageRecord;
use RuntimeException;
use Throwable;

/**
 * Moving a page, and the link fixups that come with it (decided
 * 2026-09-26): the redirect stub keeps every old link working, and links in
 * *unsigned* pages are also rewritten to the new path — each as an ordinary
 * new revision attributed to whoever moved the page. A signed report is
 * never edited for this: that would turn it back into a draft (D3), and its
 * links keep resolving through the stub.
 *
 * Links are found by scanning page bodies, not the index (which records no
 * body links): markdown targets written as `ns:page`, `/ns:page`, or the
 * importer's `ns/page`, optionally with a `#fragment`, keep their form.
 */
final class PageMoves
{
    public function __construct(
        private readonly FlatFile $storage,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Moves, fixes links, and audits all of it (page.move, plus a page.save
     * per fixed page); $request is null from the CLI.
     *
     * @return array{moved: PageRecord, fixed: list<PageRecord>, skippedSigned: int}
     */
    public function move(string $from, string $to, string $actor, ?Request $request = null): array
    {
        $moved = $this->storage->move($from, $to, $actor);
        $fixed = [];
        $skippedSigned = 0;

        foreach ($this->storage->allPaths() as $path) {
            if ($path === $moved->path) {
                continue;
            }
            try {
                $page = $this->storage->read($path);
            } catch (Throwable) {
                continue;
            }
            $body = self::rewrite($page->body, $from, $moved->path);
            $frontmatter = self::rewriteReferences($page->frontmatter, [$from => $moved->path]);
            if ($body === $page->body && $frontmatter === $page->frontmatter) {
                continue;
            }
            if ($page->status === 'signed') {
                ++$skippedSigned;
                continue;
            }
            try {
                $fixed[] = $this->storage->save($path, $frontmatter, $body, $page->rev, $actor, 'link to moved page', auto: true);
            } catch (RuntimeException) {
                // Someone saved it in between: its link still resolves through the stub
            }
        }

        $this->audit->record('page.move', $actor, $request, $moved->pid, $moved->path, $moved->rev, extra: [
            'from_hash' => AuditLog::pathHash($from),
            'links_fixed' => \count($fixed),
            'links_left_signed' => $skippedSigned,
        ]);
        foreach ($fixed as $page) {
            $this->audit->record('page.save', $actor, $request, $page->pid, $page->path, $page->rev, extra: ['reason' => 'link-fixup']);
        }

        return ['moved' => $moved, 'fixed' => $fixed, 'skippedSigned' => $skippedSigned];
    }

    /**
     * Several moves at once — the namespace index's bulk Move. Each page is
     * moved on its own (a collision or a page with children under it fails
     * that page only, reported in `failed`), then the link fixups run as
     * **one** pass over every page with all the moves applied together:
     * move() rescans the whole tree per call, which at archive size
     * (thousands of pages) × a bulk selection is far too slow for a request.
     * Audited like move(): a page.move per moved page, a page.save per fixed one.
     *
     * @param array<string, string> $moves from path => to path
     *
     * @return array{moved: list<PageRecord>, failed: array<string, string>, fixed: list<PageRecord>, skippedSigned: int}
     */
    public function moveMany(array $moves, string $actor, ?Request $request = null): array
    {
        $done = [];
        $moved = [];
        $failed = [];
        foreach ($moves as $from => $to) {
            try {
                $record = $this->storage->move((string) $from, $to, $actor);
            } catch (InvalidArgumentException|PageNotFoundException $e) {
                $failed[(string) $from] = $e->getMessage();
                continue;
            }
            $done[(string) $from] = $record->path;
            $moved[(string) $from] = $record;
        }

        $fixed = [];
        $skippedSigned = 0;
        if ($done !== []) {
            foreach ($this->storage->allPaths() as $path) {
                try {
                    $page = $this->storage->read($path);
                } catch (Throwable) {
                    continue;
                }
                $body = self::rewriteMany($page->body, $done);
                $frontmatter = self::rewriteReferences($page->frontmatter, $done);
                if ($body === $page->body && $frontmatter === $page->frontmatter) {
                    continue;
                }
                if ($page->status === 'signed') {
                    ++$skippedSigned;
                    continue;
                }
                try {
                    $fixed[] = $this->storage->save($path, $frontmatter, $body, $page->rev, $actor, 'links to moved pages', auto: true);
                } catch (RuntimeException) {
                    // Someone saved it in between: its links still resolve through the stubs
                }
            }
        }

        foreach ($moved as $from => $record) {
            $this->audit->record('page.move', $actor, $request, $record->pid, $record->path, $record->rev, extra: [
                'from_hash' => AuditLog::pathHash((string) $from),
                'bulk' => \count($moved),
            ]);
        }
        foreach ($fixed as $page) {
            $this->audit->record('page.save', $actor, $request, $page->pid, $page->path, $page->rev, extra: ['reason' => 'link-fixup']);
        }

        return ['moved' => array_values($moved), 'failed' => $failed, 'fixed' => $fixed, 'skippedSigned' => $skippedSigned];
    }

    /** $body with every markdown link to $from pointed at $to, in the form it was written */
    public static function rewrite(string $body, string $from, string $to): string
    {
        return self::rewriteMany($body, [$from => $to]);
    }

    /**
     * rewrite() for several moves in one pass — each link is matched once
     * against every from path, so one move's target can never be rewritten
     * again by another's.
     *
     * @param array<string, string> $moves from path => to path
     */
    public static function rewriteMany(string $body, array $moves): string
    {
        $forms = [];
        foreach ($moves as $from => $to) {
            $from = (string) $from;
            $forms[$from] = $to;
            $forms['/' . $from] = '/' . $to;
            $forms[str_replace(':', '/', $from)] = str_replace(':', '/', $to);
        }

        return (string) preg_replace_callback(
            '/\]\(\s*([^)\s#]+)(#[^)\s]*)?(\s+"[^"]*")?\s*\)/',
            static function (array $m) use ($forms): string {
                if (!isset($forms[$m[1]])) {
                    return $m[0];
                }

                return '](' . $forms[$m[1]] . ($m[2] ?? '') . ($m[3] ?? '') . ')';
            },
            $body
        );
    }

    /**
     * A template's `references:` (phase 25) follows its pages when they
     * move, like a link in the body; nothing else in frontmatter is touched.
     *
     * @param array<string, mixed>  $frontmatter
     * @param array<string, string> $moves from path => to path
     *
     * @return array<string, mixed>
     */
    public static function rewriteReferences(array $frontmatter, array $moves): array
    {
        if (!\is_array($frontmatter['references'] ?? null)) {
            return $frontmatter;
        }
        $frontmatter['references'] = array_map(
            static fn (mixed $ref): mixed => \is_string($ref) && isset($moves[trim($ref, " \t:/")]) ? $moves[trim($ref, " \t:/")] : $ref,
            $frontmatter['references'],
        );

        return $frontmatter;
    }
}
