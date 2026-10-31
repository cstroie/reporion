<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Index\IndexInterface;
use Reporion\Storage\StorageInterface;
use Reporion\Storage\PageRecord;

/**
 * The assistant's saved results (docs/library.md, owner's decision 2026-10-31): every analysis of
 * a library source (phase 5) and every Studio text of a notebook (phase 6) is kept, as it is
 * made, in a page beside it named by its prompt: `library:iwata2019:source-points`,
 * `wiki:white-matter:studio-overview`. A run again is a new revision of that page; the history
 * keeps the earlier answers. The page carries frontmatter `analysis` {prompt, of (the pid it was
 * made from), of_rev, of_sha (its text's), provider, at}, so it is never taken for a source or a notebook: Ask the
 * notebook and Studio read `library:{key}` and the notebook only, and the assistant's text is
 * never cited as the article's (D8). It is the writer's revision (note `assisted: {prompt}`),
 * private, and moves and goes to the trash with the page it belongs to (PageMoves, the deletes).
 * A page at that path that is not a saved analysis, or one signed, is never written over.
 */
final class Analyses
{
    /** The frontmatter key that marks a saved analysis */
    public const KEY = 'analysis';

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly ?IndexInterface $index = null,
    ) {
    }

    /** The page's text as an analysis is made from it */
    public static function textSha(PageRecord $page): string
    {
        return substr(hash('sha256', trim($page->body)), 0, 16);
    }

    public static function pathFor(string $page, string $prompt): string
    {
        return $page . ':' . $prompt;
    }

    /** @param array<string, mixed> $frontmatter */
    public static function isAnalysis(array $frontmatter): bool
    {
        return \is_array($frontmatter[self::KEY] ?? null);
    }

    /**
     * Keeps $text as the analysis $prompt of $of: a new page, or a new revision of the one there
     *
     * @param array<string, mixed> $extra frontmatter the result needs to read as it did (a notebook's `language`, `citation_style`)
     *
     * @return ?array{path: string, rev: int, pid: string, created: bool} null: a page there that is not a saved analysis, or signed
     */
    public function save(PageRecord $of, string $prompt, string $label, string $text, User $by, string $provider, array $extra = []): ?array
    {
        $path = self::pathFor($of->path, $prompt);
        $title = $label . ': ' . (\is_string($of->frontmatter['title'] ?? null) && $of->frontmatter['title'] !== '' ? $of->frontmatter['title'] : substr($of->path, (int) strrpos($of->path, ':') + 1));
        $frontmatter = ['title' => $title, 'visibility' => 'private'] + array_filter($extra, static fn (mixed $v): bool => $v !== null && $v !== '') + [self::KEY => [
            'prompt' => $prompt,
            'of' => $of->pid,
            'of_rev' => $of->rev,
            // What it was made from: a later change of the text (not of the record or the summary) makes it stale
            'of_sha' => self::textSha($of),
            'provider' => $provider,
            'at' => date('c'),
        ]];
        $body = rtrim($text) . "\n";
        $note = 'assisted: ' . $prompt;
        try {
            $there = $this->storage->read($path);
        } catch (PageNotFoundException) {
            $made = $this->storage->create($path, $frontmatter, $body, $by->username, $note, exclusive: true);

            return ['path' => $made->path, 'rev' => $made->rev, 'pid' => $made->pid, 'created' => true];
        }
        if (!self::isAnalysis($there->frontmatter) || $there->status === 'signed') {
            return null;
        }
        $saved = $this->storage->save($path, $frontmatter, $body, $there->rev, $by->username, $note);

        return ['path' => $saved->path, 'rev' => $saved->rev, 'pid' => $saved->pid, 'created' => false];
    }

    /**
     * The saved analyses directly under $path, whoever may read them (a move or a delete takes them all)
     *
     * @return list<string>
     */
    public function under(string $path): array
    {
        $prefix = $path . ':';
        $out = [];
        foreach ($this->storage->allPaths() as $child) {
            if (!str_starts_with($child, $prefix) || str_contains(substr($child, \strlen($prefix)), ':')) {
                continue;
            }
            try {
                if (self::isAnalysis($this->storage->read($child)->frontmatter)) {
                    $out[] = $child;
                }
            } catch (PageNotFoundException) {
            }
        }

        return $out;
    }

    /**
     * The saved analyses under $path to the trash with it (after it went there), each audited
     * `page.delete` as the caller's; back through Admin → Trash, each on its own
     *
     * @return int how many went
     */
    public function trashWith(string $path, string $actor, \Reporion\Audit\AuditLog $audit, ?\Reporion\Http\Request $request = null): int
    {
        $gone = 0;
        foreach ($this->under($path) as $child) {
            try {
                $page = $this->storage->read($child);
                $this->storage->delete($child, $actor);
                $audit->record('page.delete', $actor, $request, $page->pid, $page->path, $page->rev, extra: ['with' => 'analysis']);
                ++$gone;
            } catch (\RuntimeException) {
                // Gone meanwhile, or a write that failed: the page itself is in the trash all the same
            }
        }

        return $gone;
    }

    /**
     * The saved analyses of $page the caller reads, for its card: in $order, each with its text
     *
     * @param list<string> $order the prompts, in the order the card shows them
     *
     * @return list<array{prompt: string, pid: string, path: string, text: string, at: string, provider: string, stale: bool, audio: ?array{ext: string, seconds: int}}>
     */
    public function shown(PageRecord $page, ?User $principal, array $order): array
    {
        if ($this->index === null) {
            return [];
        }
        $found = [];
        foreach ($this->index->listNamespaceMeta($page->path, $principal) as $row) {
            $analysis = $row['meta'][self::KEY] ?? null;
            if (!\is_array($analysis) || !\is_string($analysis['prompt'] ?? null) || ($analysis['of'] ?? null) !== $page->pid) {
                continue;
            }
            try {
                $record = $this->storage->read((string) $row['path']);
            } catch (PageNotFoundException) {
                continue;
            }
            $found[$analysis['prompt']] = [
                'prompt' => $analysis['prompt'],
                'pid' => $record->pid,
                'path' => $record->path,
                'text' => $record->body,
                'at' => \is_string($analysis['at'] ?? null) ? $analysis['at'] : '',
                'provider' => \is_string($analysis['provider'] ?? null) ? $analysis['provider'] : '',
                // Made from an older text of the page
                'stale' => ($analysis['of_sha'] ?? null) !== self::textSha($page),
                // The audio overview's file (phase 8): its type and length
                'audio' => \is_array($record->frontmatter['audio'] ?? null) && \is_string($record->frontmatter['audio']['ext'] ?? null)
                    ? ['ext' => $record->frontmatter['audio']['ext'], 'seconds' => (int) ($record->frontmatter['audio']['seconds'] ?? 0)] : null,
            ];
        }
        $out = [];
        foreach ($order as $prompt) {
            if (isset($found[$prompt])) {
                $out[] = $found[$prompt];
            }
        }

        return $out;
    }
}
