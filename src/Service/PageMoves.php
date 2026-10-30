<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Http\Request;
use Reporion\Storage\FileTree;
use Reporion\Support\CitationKey;
use Reporion\Storage\PageRecord;
use RuntimeException;

/**
 * Moving a page, and the link fixups that come with it (decided
 * 2026-09-26): the redirect stub keeps every old link working, and links in
 * *unsigned* pages are also rewritten to the new path: each as an ordinary
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
        private readonly FileTree $storage,
        private readonly AuditLog $audit,
        // Where `[@key]` citations find their sources: a library page renamed is re-cited (docs/library.md)
        private readonly string $libraryNamespace = 'library',
    ) {
    }

    /**
     * Moves, fixes links, and audits all of it (page.move, plus a page.save
     * per fixed page); $request is null from the CLI.
     *
     * @return array{moved: PageRecord, fixed: list<PageRecord>, skippedSigned: int, skipped: int}
     */
    public function move(string $from, string $to, string $actor, ?Request $request = null): array
    {
        $moved = $this->storage->move($from, $to, $actor);
        ['fixed' => $fixed, 'skippedSigned' => $skippedSigned, 'skipped' => $skipped] = $this->relink([$from => $moved->path], $actor, 'link to moved page');

        $this->audit->record('page.move', $actor, $request, $moved->pid, $moved->path, $moved->rev, extra: [
            'from_hash' => AuditLog::pathHash($from),
            'links_fixed' => \count($fixed),
            'links_left_signed' => $skippedSigned,
            'links_skipped' => $skipped,
        ]);
        foreach ($fixed as $page) {
            $this->audit->record('page.save', $actor, $request, $page->pid, $page->path, $page->rev, extra: ['reason' => 'link-fixup']);
        }

        return ['moved' => $moved, 'fixed' => $fixed, 'skippedSigned' => $skippedSigned, 'skipped' => $skipped];
    }

    /**
     * Several moves at once: the namespace index's bulk Move. Each page is
     * moved on its own (a collision or a page with children under it fails
     * that page only, reported in `failed`), then the link fixups run as
     * **one** pass over every page with all the moves applied together:
     * move() rescans the whole tree per call, which at archive size
     * (thousands of pages) × a bulk selection is far too slow for a request.
     * Audited like move(): a page.move per moved page, a page.save per fixed one.
     *
     * @param array<string, string> $moves from path => to path
     *
     * @return array{moved: list<PageRecord>, failed: array<string, string>, fixed: list<PageRecord>, skippedSigned: int, skipped: int}
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

        ['fixed' => $fixed, 'skippedSigned' => $skippedSigned, 'skipped' => $skipped] = $done !== [] ? $this->relink($done, $actor, 'links to moved pages') : ['fixed' => [], 'skippedSigned' => 0, 'skipped' => 0];

        foreach ($moved as $from => $record) {
            $this->audit->record('page.move', $actor, $request, $record->pid, $record->path, $record->rev, extra: [
                'from_hash' => AuditLog::pathHash((string) $from),
                'bulk' => \count($moved),
                'links_skipped' => $skipped,
            ]);
        }
        foreach ($fixed as $page) {
            $this->audit->record('page.save', $actor, $request, $page->pid, $page->path, $page->rev, extra: ['reason' => 'link-fixup']);
        }

        return ['moved' => array_values($moved), 'failed' => $failed, 'fixed' => $fixed, 'skippedSigned' => $skippedSigned, 'skipped' => $skipped];
    }

    /**
     * Links to the $map's old paths pointed at the new ones, in one pass over
     * every page: body links, a template's `reference`, a report's `priors`.
     * Unsigned pages only: a signed one is counted, never edited (D3). Not
     * audited here: the caller audits a page.save per fixed page. `skipped`
     * counts the pages left as they were because they did not read or did
     * not save (saved by someone in between, or a write error): their links
     * still resolve through the stub, but are not rewritten.
     *
     * @param array<string, string> $map old path => new path
     *
     * @return array{fixed: list<PageRecord>, skippedSigned: int, skipped: int}
     */
    public function relink(array $map, string $actor, string $note): array
    {
        $fixed = [];
        $skippedSigned = 0;
        $skipped = 0;
        $keys = $this->renamedKeys($map);
        foreach ($this->storage->allPaths() as $path) {
            if (\in_array($path, $map, true)) {
                continue;
            }
            try {
                $page = $this->storage->read($path);
            } catch (RuntimeException) {
                ++$skipped;
                continue;
            }
            $body = self::rewriteCitations(self::rewriteMany($page->body, $map), $keys);
            $frontmatter = self::rewriteReferences($page->frontmatter, $map);
            if ($body === $page->body && $frontmatter === $page->frontmatter) {
                continue;
            }
            if ($page->status === 'signed') {
                ++$skippedSigned;
                continue;
            }
            try {
                $fixed[] = $this->storage->save($path, $frontmatter, $body, $page->rev, $actor, $note, auto: true);
            } catch (RevisionConflictException) {
                // Someone saved it in between: left as it is
                ++$skipped;
            } catch (RuntimeException $e) {
                // By class only: a storage message may carry the path (invariant 8)
                error_log('reporion: link fixup could not save a page: ' . $e::class);
                ++$skipped;
            }
        }

        return ['fixed' => $fixed, 'skippedSigned' => $skippedSigned, 'skipped' => $skipped];
    }

    /**
     * The citation keys a move renames: a page directly in the library namespace moved to another
     * name directly in it (its key is its slug, docs/library.md). A move out of the library, or
     * to a name no key can have, renames nothing: the citations stay as written and show as unknown.
     *
     * @param array<string, string> $map old path => new path
     *
     * @return array<string, string> old key => new key
     */
    private function renamedKeys(array $map): array
    {
        $prefix = $this->libraryNamespace . ':';
        $keys = [];
        foreach ($map as $from => $to) {
            $old = substr((string) $from, \strlen($prefix));
            $new = substr($to, \strlen($prefix));
            if (str_starts_with((string) $from, $prefix) && str_starts_with($to, $prefix) && CitationKey::isValid($old) && CitationKey::isValid($new) && $old !== $new) {
                $keys[$old] = $new;
            }
        }

        return $keys;
    }

    /**
     * $body with the renamed keys re-cited: `[@old]`, `[@old, p. 2]`, `[@a; @old]` (only inside a citation)
     *
     * @param array<string, string> $keys old key => new key
     */
    public static function rewriteCitations(string $body, array $keys): string
    {
        if ($keys === []) {
            return $body;
        }
        $key = '[a-z][a-z0-9-]{1,62}';
        $item = '\\s*@' . $key . '(?:\\s*,[^\\];\\[]*)?\\s*';

        return (string) preg_replace_callback(
            '/\\[' . $item . '(?:;' . $item . ')*\\](?![(\\[])/',
            static fn (array $block): string => (string) preg_replace_callback(
                '/(?<=[\\[;\\s])@(' . $key . ')(?![a-z0-9-])/',
                static fn (array $m): string => '@' . ($keys[$m[1]] ?? $m[1]),
                $block[0]
            ),
            $body
        );
    }

    /** $body with every markdown link to $from pointed at $to, in the form it was written */
    public static function rewrite(string $body, string $from, string $to): string
    {
        return self::rewriteMany($body, [$from => $to]);
    }

    /**
     * rewrite() for several moves in one pass: each link is matched once
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
     * The frontmatter keys that name a page follow it when it moves, like a
     * link in the body: a template's `reference:` (phase 25), a report's
     * `priors` (phase 29) and the template it was made from:`template` and
     * each `exams[].template` (D45). Nothing else in frontmatter is touched;
     * settings, grants and pins that name a namespace are not pages and are
     * not rewritten.
     *
     * @param array<string, mixed>  $frontmatter
     * @param array<string, string> $moves from path => to path
     *
     * @return array<string, mixed>
     */
    public static function rewriteReferences(array $frontmatter, array $moves): array
    {
        $ref = \is_string($frontmatter['reference'] ?? null) ? trim($frontmatter['reference'], " \t:/") : null;
        if ($ref !== null && isset($moves[$ref])) {
            $frontmatter['reference'] = $moves[$ref];
        }
        // The template a report was made from, on the report and on each exam
        if (\is_string($frontmatter['template'] ?? null) && isset($moves[trim($frontmatter['template'], " \t:/")])) {
            $frontmatter['template'] = $moves[trim($frontmatter['template'], " \t:/")];
        }
        if (\is_array($frontmatter['exams'] ?? null)) {
            foreach ($frontmatter['exams'] as $n => $exam) {
                if (\is_array($exam) && \is_string($exam['template'] ?? null) && isset($moves[trim($exam['template'], " \t:/")])) {
                    $frontmatter['exams'][$n]['template'] = $moves[trim($exam['template'], " \t:/")];
                }
            }
        }
        // A report's priors (phase 29: a joined parent leaves no redirect stub behind)
        if (\is_array($frontmatter['priors'] ?? null)) {
            $priors = array_map(static fn (mixed $p): mixed => \is_string($p) && isset($moves[$p]) ? $moves[$p] : $p, $frontmatter['priors']);
            if ($priors !== $frontmatter['priors']) {
                $frontmatter['priors'] = array_values(array_unique($priors, SORT_REGULAR));
            }
        }

        return $frontmatter;
    }
}
