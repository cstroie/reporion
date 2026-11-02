<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Closure;
use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Http\Request;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\Assistant;
use Reporion\Storage\FileTree;

/**
 * Ask the library (2026-11-02, docs/library.md): a question answered from every library source the
 * caller can read, on /library, apart from Ask the archive: library passages only (SourceAsk:
 * passage_fts and passage_vectors), never a report, never HippoBridge (no `ask.sources` hook). The
 * `ask-library` prompt of the profile serving the library namespace, its search words from
 * `notebook-terms`; the earlier turns ({history}, at most TURNS) come from the browser and are not
 * kept. Shown, never written; audited `ai.call` as every request.
 */
final class LibraryAsk
{
    /** The earlier turns a question may carry */
    public const TURNS = 5;

    public function __construct(
        private readonly Actions $actions,
        private readonly Assistant $assistant,
        private readonly FileTree $storage,
        private readonly PassageSearch $search,
        private readonly string $libraryNamespace,
        private readonly int $contextTokens,
    ) {
    }

    /** Whether the profile serving the library has the `ask-library` prompt */
    public function available(): bool
    {
        return $this->actions->special($this->libraryNamespace, 'ask-library') !== null;
    }

    /**
     * @param list<array{question: string, answer: string}> $history
     * @param ?Closure(string, array<string, mixed>): void $event
     *
     * @return array{answer: ?string, sources: list<array{n: int, key: ?string, title: string, page: ?int, path: string}>, whole: bool, understood: ?string, notices: list<string>, dropped: int, ms: int, provider: string, context: list<string>}
     *
     * @throws AiException not_configured without the prompt, or the provider's reason
     */
    public function ask(string $question, array $history, User $principal, ?Request $request, ?Closure $event = null): array
    {
        $action = $this->actions->special($this->libraryNamespace, 'ask-library');
        if ($action === null) {
            throw new AiException('not_configured', 'No prompt page for Ask the library');
        }

        return (new SourceAsk($this->assistant, $this->storage, $this->search, $this->libraryNamespace, $this->contextTokens))->answer(
            $action,
            $this->actions->special($this->libraryNamespace, 'notebook-terms'),
            $question,
            \array_slice($history, -self::TURNS),
            [],
            null,
            // The library's language (`library.language`, Context's default)
            null,
            $principal,
            $request,
            $event,
        );
    }
}
