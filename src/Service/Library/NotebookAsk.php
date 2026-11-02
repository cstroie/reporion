<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Index\IndexInterface;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\Assistant;
use Reporion\Storage\FileTree;
use Reporion\Storage\PageRecord;
use Reporion\Support\InternalLink;
use Reporion\Support\LibrarySource;
use Reporion\Support\PageTitle;
use Reporion\Support\SourceChunks;

/**
 * Ask the notebook (docs/library.md, phase 5): a question answered from a notebook's own text and
 * the library sources it cites (`[@key]` in its body, a `sources:` list), each source read only
 * when the caller may read it (invariant 6: the index under the caller, then the file; never the
 * system-side citation records).
 *
 * Adaptive, as the owner chose: when the notebook and its sources fit `library.context_tokens`
 * they go whole; otherwise their passages are chosen (PassageSearch) by the question's words and
 * those the `notebook-terms` prompt adds in English and in the question's language (the sources
 * are mostly English, the notebooks Romanian), and by meaning when the library's passages have
 * vectors (phase 7), best first, until the budget is full. The answer cites
 * `[@key, p. N]`; a citation of a key that was not sent is taken out, so a source the model
 * remembers but was not given never reads as a valid citation. Shown, never written.
 */
final class NotebookAsk
{
    public function __construct(
        private readonly Actions $actions,
        private readonly Assistant $assistant,
        private readonly FileTree $storage,
        private readonly IndexInterface $index,
        private readonly string $libraryNamespace,
        private readonly int $contextTokens,
        private readonly PassageSearch $search = new PassageSearch(),
    ) {
    }

    /** How passages are chosen when a notebook does not fit (by words, and by meaning when vectors are set) */
    public function search(): PassageSearch
    {
        return $this->search;
    }

    /** The `ask-notebook` prompt of the profile serving $path, when there is one */
    public function available(string $path): bool
    {
        return $this->actions->special($path, 'ask-notebook') !== null;
    }

    /**
     * The sources a notebook cites that the caller may read, in the order they are first cited
     *
     * @return list<array{key: string, path: string, title: string, text: string, record: PageRecord}>
     */
    public function sources(PageRecord $notebook, User $principal): array
    {
        $sources = [];
        foreach ($this->keys($notebook) as $key) {
            $path = $this->libraryNamespace . ':' . $key;
            if ($this->index->findByPath($path, $principal) === null) {
                continue;
            }
            try {
                $page = $this->storage->read($path);
            } catch (PageNotFoundException) {
                continue;
            }
            if (!LibrarySource::isSource($page->frontmatter)) {
                continue;
            }
            $sources[] = ['key' => $key, 'path' => $path, 'title' => PageTitle::of($page->frontmatter, $page->body), 'text' => SourceChunks::withoutReferences($page->body), 'record' => $page];
        }

        return $sources;
    }

    /**
     * The sources a notebook cites that the caller may read, from the index only (2026-11-02: a
     * notebook may cite many; none is read until it is needed), in the order first cited
     *
     * @return list<array{key: string, path: string, pid: string, title: string}>
     */
    public function cited(PageRecord $notebook, User $principal): array
    {
        $out = [];
        foreach ($this->keys($notebook) as $key) {
            $path = $this->libraryNamespace . ':' . $key;
            $row = $this->index->findByPath($path, $principal);
            $meta = $row !== null ? json_decode((string) ($row['meta_json'] ?? ''), true) : null;
            if (\is_array($meta) && LibrarySource::isSource($meta)) {
                $out[] = ['key' => $key, 'path' => $path, 'pid' => (string) $row['pid'], 'title' => (string) ($row['title'] ?? $key)];
            }
        }

        return $out;
    }

    /**
     * @param ?\Closure(string, array<string, mixed>): void $event progress, sources and the answer's pieces (SourceAsk)
     *
     * @return array{answer: ?string, sources: list<array{n: int, key: ?string, title: string, page: ?int, path: string}>, whole: bool, understood: ?string, notices: list<string>, dropped: int, ms: int, provider: string, context: list<string>}
     *
     * @throws AiException not_configured without the prompt, or the provider's reason
     */
    public function ask(PageRecord $notebook, string $question, User $principal, ?Request $request, ?\Closure $event = null): array
    {
        $action = $this->actions->special($notebook->path, 'ask-notebook');
        if ($action === null) {
            throw new AiException('not_configured', 'No prompt page for Ask the notebook');
        }
        $own = ['key' => null, 'path' => $notebook->path, 'title' => PageTitle::of($notebook->frontmatter, $notebook->body), 'text' => trim($notebook->body)];

        return (new SourceAsk($this->assistant, $this->storage, $this->search, $this->libraryNamespace, $this->contextTokens))->answer(
            $action,
            $this->actions->special($notebook->path, 'notebook-terms'),
            $question,
            [],
            [$own],
            $this->cited($notebook, $principal),
            $notebook->frontmatter['language'] ?? null,
            $principal,
            $request,
            $event,
        );
    }

    /** @return list<string> the keys a notebook cites (`[@key]`), then those its `sources:` lists */
    private function keys(PageRecord $notebook): array
    {
        $keys = InternalLink::citedKeys($notebook->body);
        foreach (\is_array($notebook->frontmatter['sources'] ?? null) ? $notebook->frontmatter['sources'] : [] as $key) {
            if (\is_string($key) && !\in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The answer with only the citations of keys that were sent: an unknown key is taken out of its
     * bracket, an empty bracket out of the text
     *
     * @param list<string> $sent
     *
     * @return array{0: string, 1: int} the answer, how many citations were taken out
     */
    public static function onlySent(string $answer, array $sent): array
    {
        $dropped = 0;
        $answer = (string) preg_replace_callback('/\[(\s*@[a-z][a-z0-9-]{1,62}(?:\s*,[^\];\[]*)?\s*(?:;\s*@[a-z][a-z0-9-]{1,62}(?:\s*,[^\];\[]*)?\s*)*)\]/u', static function (array $m) use ($sent, &$dropped): string {
            $kept = [];
            foreach (explode(';', $m[1]) as $item) {
                if (preg_match('/^\s*@([a-z][a-z0-9-]{1,62})/u', $item, $k) === 1 && \in_array($k[1], $sent, true)) {
                    $kept[] = trim($item);
                } else {
                    ++$dropped;
                }
            }

            return $kept === [] ? '' : '[' . implode('; ', $kept) . ']';
        }, $answer);

        return [trim((string) preg_replace('/ +([.,;:])/u', '$1', $answer)), $dropped];
    }
}
