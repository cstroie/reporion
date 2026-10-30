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
use Reporion\Service\ArchiveAsk;
use Reporion\Storage\FileTree;
use Reporion\Storage\PageRecord;
use Reporion\Support\AskExpansion;
use Reporion\Support\InternalLink;
use Reporion\Support\LibrarySource;
use Reporion\Support\PageTitle;
use Reporion\Support\RecallTerms;
use Reporion\Support\SourceChunks;

/**
 * Ask the notebook (docs/library.md, phase 5): a question answered from a notebook's own text and
 * the library sources it cites (`[@key]` in its body, a `sources:` list), each source read only
 * when the caller may read it (invariant 6: the index under the caller, then the file; never the
 * system-side citation records).
 *
 * Adaptive, as the owner chose: when the notebook and its sources fit `library.context_tokens`
 * they go whole; otherwise their passages are chosen by the question's words and those the
 * `notebook-terms` prompt adds in English and in the question's language (the sources are mostly
 * English, the notebooks Romanian), best first, until the budget is full. The answer cites
 * `[@key, p. N]`; a citation of a key that was not sent is taken out, so a source the model
 * remembers but was not given never reads as a valid citation. Shown, never written.
 */
final class NotebookAsk
{
    /** The most passages a source gives when they are chosen */
    private const PER_SOURCE = 4;

    /** A passage: about this many characters, cut at paragraphs */
    private const PASSAGE = 1200;

    public function __construct(
        private readonly Actions $actions,
        private readonly Assistant $assistant,
        private readonly FileTree $storage,
        private readonly IndexInterface $index,
        private readonly string $libraryNamespace,
        private readonly int $contextTokens,
    ) {
    }

    /** The `ask-notebook` prompt of the profile serving $path, when there is one */
    public function available(string $path): bool
    {
        return $this->actions->special($path, 'ask-notebook') !== null;
    }

    /**
     * The sources a notebook cites that the caller may read, in the order they are first cited
     *
     * @return list<array{key: string, path: string, title: string, text: string}>
     */
    public function sources(PageRecord $notebook, User $principal): array
    {
        $keys = InternalLink::citedKeys($notebook->body);
        foreach (\is_array($notebook->frontmatter['sources'] ?? null) ? $notebook->frontmatter['sources'] : [] as $key) {
            if (\is_string($key) && !\in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        $sources = [];
        foreach ($keys as $key) {
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
            $sources[] = ['key' => $key, 'path' => $path, 'title' => PageTitle::of($page->frontmatter, $page->body), 'text' => SourceChunks::withoutReferences($page->body)];
        }

        return $sources;
    }

    /**
     * @return array{answer: ?string, sources: list<array{n: int, key: ?string, title: string, page: ?int, path: string}>, whole: bool, understood: ?string, notices: list<string>, dropped: int, ms: int, provider: string, context: list<string>}
     *
     * @throws AiException not_configured without the prompt, or the provider's reason
     */
    public function ask(PageRecord $notebook, string $question, User $principal, ?Request $request): array
    {
        $action = $this->actions->special($notebook->path, 'ask-notebook');
        if ($action === null) {
            throw new AiException('not_configured', 'No prompt page for Ask the notebook');
        }
        $own = ['key' => null, 'path' => $notebook->path, 'title' => PageTitle::of($notebook->frontmatter, $notebook->body), 'text' => trim($notebook->body)];
        $texts = array_merge([$own], $this->sources($notebook, $principal));
        $budget = SourceChunks::budget($this->contextTokens);
        $notices = [];
        $understood = null;
        $whole = array_sum(array_map(static fn (array $t): int => mb_strlen($t['text']), $texts)) <= $budget;

        if ($whole) {
            $passages = [];
            foreach ($texts as $t) {
                if ($t['text'] !== '') {
                    $passages[] = ['key' => $t['key'], 'path' => $t['path'], 'title' => $t['title'], 'page' => null, 'text' => $t['text']];
                }
            }
        } else {
            $terms = ArchiveAsk::terms($question);
            $expand = $this->actions->special($notebook->path, 'notebook-terms');
            if ($expand !== null) {
                try {
                    $parsed = AskExpansion::parse($this->assistant->runNotebookTerms($expand, $question, $principal, $request)['result']);
                    $understood = $parsed['question'] !== '' && $parsed['question'] !== $question ? $parsed['question'] : null;
                    $terms = array_values(array_unique([...$terms, ...$parsed['terms']]));
                } catch (AiException) {
                    $notices[] = t('notebook.expand_failed');
                }
            }
            $passages = $this->passages($texts, $terms, $budget);
        }

        $sources = [];
        foreach ($passages as $i => $p) {
            $sources[] = ['n' => $i + 1] + $p;
        }
        // No source: no model call (as Ask the archive, D53)
        if ($sources === []) {
            return ['answer' => null, 'sources' => [], 'whole' => $whole, 'understood' => $understood, 'notices' => [...$notices, t('notebook.nothing')], 'dropped' => 0, 'ms' => 0, 'provider' => '', 'context' => []];
        }
        $done = $this->assistant->runNotebook($action, $understood ?? $question, array_map(static fn (array $s): array => ['n' => $s['n'], 'key' => $s['key'], 'title' => $s['title'], 'page' => $s['page'], 'text' => $s['text']], $sources), $notebook->frontmatter['language'] ?? null, $principal, $request);
        $sent = array_values(array_unique(array_filter(array_column($sources, 'key'), 'is_string')));
        [$answer, $dropped] = self::onlySent($done['result'], $sent);

        return [
            'answer' => $answer,
            'sources' => array_map(static fn (array $s): array => ['n' => $s['n'], 'key' => $s['key'], 'title' => $s['title'], 'page' => $s['page'], 'path' => $s['path']], $sources),
            'whole' => $whole,
            'understood' => $understood,
            'notices' => $notices,
            'dropped' => $dropped,
            'ms' => $done['ms'],
            'provider' => $done['provider'],
            'context' => $done['contextSet'],
        ];
    }

    /**
     * The passages the question's words are in, best first, at most PER_SOURCE a text, until $budget
     * is full; each with the page of the marker in force where it starts
     *
     * @param list<array{key: ?string, path: string, title: string, text: string}> $texts
     * @param list<string> $terms folded
     *
     * @return list<array{key: ?string, path: string, title: string, page: ?int, text: string}>
     */
    private function passages(array $texts, array $terms, int $budget): array
    {
        $scored = [];
        foreach ($texts as $order => $t) {
            foreach (SourceChunks::split($t['text'], self::PASSAGE) as $part) {
                // A part opens with the page marker in force where it starts (SourceChunks::split())
                $page = preg_match('/\*— p\. (\d+) —\*/u', $part, $m) === 1 ? (int) $m[1] : null;
                $score = \count(RecallTerms::matched($terms, RecallTerms::fold($part)));
                if ($score > 0) {
                    $scored[] = ['score' => $score, 'order' => $order, 'key' => $t['key'], 'path' => $t['path'], 'title' => $t['title'], 'page' => $page, 'text' => $part];
                }
            }
        }
        usort($scored, static fn (array $a, array $b): int => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);
        $chosen = [];
        $used = 0;
        $per = [];
        foreach ($scored as $p) {
            if (($per[$p['path']] ?? 0) >= self::PER_SOURCE || $used + mb_strlen($p['text']) > $budget) {
                continue;
            }
            $per[$p['path']] = ($per[$p['path']] ?? 0) + 1;
            $used += mb_strlen($p['text']);
            unset($p['score'], $p['order']);
            $chosen[] = $p;
        }

        return $chosen;
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
