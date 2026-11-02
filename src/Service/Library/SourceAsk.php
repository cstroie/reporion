<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Closure;
use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Service\Ai\Action;
use Reporion\Service\Ai\Assistant;
use Reporion\Service\ArchiveAsk;
use Reporion\Storage\FileTree;
use Reporion\Support\AskExpansion;
use Reporion\Support\SourceChunks;

/**
 * A question answered from library sources (2026-11-02): the one engine of Ask the notebook (a
 * notebook's own text and the sources it cites) and Ask the library (every source the caller can
 * read). Never reports, never HippoBridge: library passages only.
 *
 * The budget is `library.context_tokens` less what the request really carries (the prompt page,
 * the question, the earlier turns, the answer's room: SourceChunks::budgetFor()). A notebook whose
 * own text and sources fit go whole (the sources' sizes read from the index, a source read only
 * then); otherwise, and always for the whole library, the passages are found in the index
 * (PassageSearch::find(): passage_fts by words, passage_vectors by meaning), the search words
 * widened first by the `notebook-terms` prompt when the profile has it. The caller may follow
 * along ($event): `progress` {step: terms|searching|answering}, `sources` (the passages chosen,
 * before the model is asked), `delta` {text} (the answer's pieces). The answer keeps only the
 * citations of keys that were sent (NotebookAsk::onlySent()). Shown, never written.
 */
final class SourceAsk
{
    public function __construct(
        private readonly Assistant $assistant,
        private readonly FileTree $storage,
        private readonly PassageSearch $search,
        private readonly string $libraryNamespace,
        private readonly int $contextTokens,
    ) {
    }

    public function search(): PassageSearch
    {
        return $this->search;
    }

    /**
     * @param list<array{key: ?string, path: string, title: string, text: string}> $own the notebook's own page ([] for the library)
     * @param ?list<array{key: string, path: string, pid: string, title: string}> $sources a notebook's sources; null: the whole library
     * @param list<array{question: string, answer: string}> $history earlier turns (Ask the library)
     * @param ?Closure(string, array<string, mixed>): void $event
     *
     * @return array{answer: ?string, sources: list<array{n: int, key: ?string, title: string, page: ?int, path: string}>, whole: bool, understood: ?string, notices: list<string>, dropped: int, ms: int, provider: string, context: list<string>}
     *
     * @throws AiException the provider's reason
     */
    public function answer(Action $action, ?Action $expand, string $question, array $history, array $own, ?array $sources, mixed $language, User $principal, ?Request $request, ?Closure $event = null): array
    {
        $event ??= static function (string $name, array $data): void {
        };
        $budget = SourceChunks::budgetFor($this->contextTokens, $action->prompt . $action->system, $action->maxTokens, $question, ...array_map(static fn (array $t): string => $t['question'] . $t['answer'], $history));
        $notices = [];
        $understood = null;
        $whole = false;
        $passages = [];

        if ($sources !== null) {
            $lengths = $this->search->lengths(array_column($sources, 'pid'));
            $size = array_sum(array_map(static fn (array $t): int => mb_strlen($t['text']), $own)) + array_sum($lengths);
            $whole = $size <= $budget;
        }
        if ($whole) {
            foreach ($own as $t) {
                if (trim($t['text']) !== '') {
                    $passages[] = ['key' => $t['key'], 'path' => $t['path'], 'title' => $t['title'], 'page' => null, 'text' => trim($t['text'])];
                }
            }
            foreach ($sources ?? [] as $s) {
                try {
                    $text = trim(SourceChunks::withoutReferences($this->storage->read($s['path'])->body));
                } catch (PageNotFoundException) {
                    continue;
                }
                if ($text !== '') {
                    $passages[] = ['key' => $s['key'], 'path' => $s['path'], 'title' => $s['title'], 'page' => null, 'text' => $text];
                }
            }
        } else {
            $terms = ArchiveAsk::terms($question);
            if ($expand !== null) {
                $event('progress', ['step' => 'terms']);
                try {
                    $parsed = AskExpansion::parse($this->assistant->runNotebookTerms($expand, $question, $principal, $request)['result']);
                    $understood = $parsed['question'] !== '' && $parsed['question'] !== $question ? $parsed['question'] : null;
                    $terms = array_values(array_unique([...$terms, ...$parsed['terms']]));
                } catch (AiException) {
                    $notices[] = t('notebook.expand_failed');
                }
            }
            $event('progress', ['step' => 'searching']);
            $found = $this->search->find($own, $sources !== null ? array_column($sources, 'pid') : null, $terms, $budget, $this->assistant->safeQuestion($understood ?? $question), $principal);
            $ownPaths = array_flip(array_column($own, 'path'));
            $prefix = $this->libraryNamespace . ':';
            foreach ($found['passages'] as $p) {
                // A source's passage: its key is its path in the library
                if (!isset($ownPaths[$p['path']]) && str_starts_with($p['path'], $prefix)) {
                    $p['key'] = substr($p['path'], \strlen($prefix));
                }
                $passages[] = $p;
            }
            if ($found['failed']) {
                $notices[] = t('notebook.meaning_failed');
            }
        }

        $numbered = [];
        foreach ($passages as $i => $p) {
            $numbered[] = ['n' => $i + 1] + $p;
        }
        $listed = array_map(static fn (array $s): array => ['n' => $s['n'], 'key' => $s['key'], 'title' => $s['title'], 'page' => $s['page'], 'path' => $s['path']], $numbered);
        // No source: no model call (as Ask the archive, D53)
        if ($numbered === []) {
            return ['answer' => null, 'sources' => [], 'whole' => $whole, 'understood' => $understood, 'notices' => [...$notices, t('notebook.nothing')], 'dropped' => 0, 'ms' => 0, 'provider' => '', 'context' => []];
        }
        $event('sources', ['sources' => $listed, 'whole' => $whole, 'understood' => $understood]);
        $event('progress', ['step' => 'answering']);
        $done = $this->assistant->runNotebook(
            $action,
            $understood ?? $question,
            array_map(static fn (array $s): array => ['n' => $s['n'], 'key' => $s['key'], 'title' => $s['title'], 'page' => $s['page'], 'text' => $s['text']], $numbered),
            $language,
            $principal,
            $request,
            static function (string $piece) use ($event): void {
                $event('delta', ['text' => $piece]);
            },
            $history,
        );
        $sent = array_values(array_unique(array_filter(array_column($numbered, 'key'), 'is_string')));
        [$answer, $dropped] = NotebookAsk::onlySent($done['result'], $sent);

        return [
            'answer' => $answer,
            'sources' => $listed,
            'whole' => $whole,
            'understood' => $understood,
            'notices' => $notices,
            'dropped' => $dropped,
            'ms' => $done['ms'],
            'provider' => $done['provider'],
            'context' => $done['contextSet'],
        ];
    }
}
