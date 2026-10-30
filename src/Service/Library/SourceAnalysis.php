<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Exception\LibraryException;
use Reporion\Http\Request;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\Assistant;
use Reporion\Storage\PageRecord;
use Reporion\Support\SourceChunks;

/**
 * One analysis of a library source by the assistant (docs/library.md, phase 5): key points,
 * definitions, classifications, design and level of evidence, limitations, the questions it
 * answers, or its one-line summary. The instruction is the reserved prompt page of the profile
 * serving the source (D15); this class only decides how much text goes in one request.
 *
 * A source that fits `library.context_tokens` goes whole. A longer one is condensed part by part
 * (`source-part`, each part at the page markers it carries) and the analysis is made from the
 * notes (map-reduce), at most MAX_PARTS parts: beyond that it is refused rather than run for
 * minutes. N parts are N+1 requests, each with the Assistant's retries. Nothing is written: the
 * answer is shown, and the writer applies it (D8).
 */
final class SourceAnalysis
{
    /** The analyses: task => its reserved prompt; `summary` is the existing one-line one */
    public const TASKS = [
        'summary' => 'summary',
        'points' => 'source-points',
        'definitions' => 'source-definitions',
        'classification' => 'source-classification',
        'design' => 'source-design',
        'limitations' => 'source-limitations',
        'questions' => 'source-questions',
    ];

    /** The most parts a source may be cut into */
    public const MAX_PARTS = 6;

    /** Characters of notes a word takes, with its space */
    private const CHARS_PER_WORD = 6;

    public function __construct(
        private readonly Actions $actions,
        private readonly Assistant $assistant,
        private readonly int $contextTokens,
    ) {
    }

    /**
     * The analyses the profile serving $path has a prompt page for
     *
     * @return list<string> task ids, in TASKS' order
     */
    public function available(string $path): array
    {
        return array_values(array_filter(
            array_keys(self::TASKS),
            fn (string $task): bool => $this->actions->special($path, self::TASKS[$task]) !== null,
        ));
    }

    /**
     * How many requests an analysis of $page takes: 1 when the source fits, else its parts and the last
     *
     * @return array{parts: int, requests: int}
     *
     * @throws LibraryException TOO_LARGE when it would need more than MAX_PARTS parts
     */
    public function plan(PageRecord $page): array
    {
        $parts = SourceChunks::split(SourceChunks::withoutReferences($page->body), SourceChunks::budget($this->contextTokens));
        if (\count($parts) > self::MAX_PARTS) {
            throw new LibraryException(LibraryException::TOO_LARGE);
        }

        return ['parts' => \count($parts), 'requests' => \count($parts) > 1 ? \count($parts) + 1 : 1];
    }

    /**
     * @param \Closure(int, int): void $progress called with (step, steps) before each request
     *
     * @return array{result: string, parts: int, requests: int, ms: int, provider: string, usage: array<string, int>, context: list<string>}
     *
     * @throws LibraryException INVALID for an unknown task or a source with no text, TOO_LARGE
     * @throws AiException not_configured when a prompt page is missing, or the provider's reason
     */
    public function run(string $task, PageRecord $page, User $user, ?Request $request, \Closure $progress): array
    {
        if (!isset(self::TASKS[$task])) {
            throw new LibraryException(LibraryException::INVALID);
        }
        $action = $this->actions->special($page->path, self::TASKS[$task]);
        if ($action === null) {
            throw new AiException('not_configured', 'No prompt page for this analysis');
        }
        $text = SourceChunks::withoutReferences($page->body);
        if ($text === '') {
            throw new LibraryException(LibraryException::NO_TEXT);
        }
        $budget = SourceChunks::budget($this->contextTokens);
        $parts = SourceChunks::split($text, $budget);
        if (\count($parts) > self::MAX_PARTS) {
            throw new LibraryException(LibraryException::TOO_LARGE);
        }
        $n = \count($parts);
        $steps = $n > 1 ? $n + 1 : 1;
        $ms = 0;
        $usage = [];
        $context = [];
        $last = null;
        $note = static function (array $done) use (&$ms, &$usage, &$context, &$last): void {
            $ms += $done['ms'];
            foreach ($done['usage'] as $key => $value) {
                $usage[$key] = ($usage[$key] ?? 0) + (int) $value;
            }
            $context = $done['contextSet'];
            $last = $done;
        };

        if ($n > 1) {
            $partAction = $this->actions->special($page->path, 'source-part');
            if ($partAction === null) {
                throw new AiException('not_configured', 'No prompt page for condensing a long source');
            }
            // The notes of all the parts together must fit the request that analyses them
            $words = max(60, intdiv((int) ($budget * 0.8), self::CHARS_PER_WORD * $n));
            $notes = [];
            foreach ($parts as $i => $part) {
                $progress($i + 1, $steps);
                $done = $this->assistant->runSource($partAction, $page, $part, 'source part ' . ($i + 1) . ' of ' . $n, ($i + 1) . ' of ' . $n, $words, $user, $request);
                $note($done);
                $notes[] = mb_substr(trim($done['result']), 0, $words * self::CHARS_PER_WORD * 2);
            }
            $text = implode("\n\n", $notes);
            $label = 'source notes (' . $n . ' parts)';
        } else {
            $label = 'source text';
        }
        $progress($steps, $steps);
        $done = $this->assistant->runSource($action, $page, $text, $label, '', 0, $user, $request);
        $note($done);

        return ['result' => trim($done['result']), 'parts' => $n, 'requests' => $steps, 'ms' => $ms, 'provider' => $done['provider'], 'usage' => $usage, 'context' => $context];
    }
}
