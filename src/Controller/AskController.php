<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ApiResponse;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Plugin\Hooks;
use Reporion\Service\Ai\Action;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\Assistant;
use Reporion\Service\Ai\Context;
use Reporion\Service\Ai\Embedder;
use Reporion\Service\ArchiveAsk;
use Reporion\Support\AskExpansion;
use Reporion\Support\ReportPath;

/**
 * Ask the archive (D53, 2026-10-19):
 *
 * - `GET /ask[?q=…]`: the screen, for a signed-in user (anyone else 404); it says so when
 *   the assistant is off or the profile serving `reports` has no
 *   `ask-archive` prompt.
 * - `POST /api/v1/ask {question, history?}` → `{data: {answer, sources: [{n, href,
 *   heading, text, study_date, modality, mine}], notices, context, ms, provider}}`:
 *   the passages of finished reports the caller can read that the question
 *   is about (Service\ArchiveAsk, invariant 6), and the `ask-archive`
 *   prompt's answer from them, de-identified (Ai\Context::archive(),
 *   invariant 8), citing them by number. A source is linked by pid and rev
 *   (`/r/{pid}/{rev}`), never by path. Plugins may add passages from outside
 *   the wiki (hook `ask.sources`: HippoBridge's HIS texts), numbered after the
 *   wiki's, shown de-identified and without a link; a source they could not
 *   read comes back as a line in `notices`. No source: `answer` null and no
 *   model asked. Shown, never written; audited as any assistant call
 *   (`ai.call`, no page).
 */
final class AskController
{
    /** Places the passages from outside the wiki may take of the sources */
    private const OUTSIDE = 4;

    /** Earlier turns of the conversation the browser may send (question and answer each), the latest kept */
    private const HISTORY = 5;

    /** The longest earlier answer kept */
    private const HISTORY_ANSWER = 4_000;

    public function __construct(
        private readonly AiConfig $config,
        private readonly Actions $actions,
        private readonly Assistant $assistant,
        private readonly ArchiveAsk $archive,
        private readonly IndexInterface $index,
        private readonly Hooks $hooks,
        private readonly ?Embedder $embedder = null,
    ) {
    }

    public function show(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }
        $question = mb_substr(trim($request->query['q'] ?? ''), 0, ArchiveAsk::MAX_QUESTION);

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/ask.php', [
            'question' => $question,
            'available' => $this->available(),
            'maxQuestion' => ArchiveAsk::MAX_QUESTION,
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('ask.title')));
    }

    public function ask(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        $action = $this->action();
        if ($action === null) {
            return ApiResponse::error(503, 'not_configured', t('ask.unavailable'));
        }
        $fields = $request->json();
        $question = \is_string($fields['question'] ?? null) ? trim($fields['question']) : '';
        if ($question === '' || mb_strlen($question) > ArchiveAsk::MAX_QUESTION) {
            return ApiResponse::error(422, 'invalid_question', t('ask.err_question'));
        }
        $notices = [];
        // Room for the Assistant's retries: the expansion, then the answer
        @set_time_limit(2 * Assistant::maxSeconds($this->config->timeout) + 60);
        // Step 1: the question as a standalone one (an earlier turn resolved) and the words a report would use
        $standalone = $question;
        $extra = [];
        $expand = $this->expandAction();
        if ($expand !== null) {
            try {
                $parsed = AskExpansion::parse($this->assistant->runExpand($expand, $question, self::history($fields['history'] ?? null), $principal, $request)['result']);
                $standalone = $parsed['question'] !== '' ? $parsed['question'] : $question;
                $extra = $parsed['terms'];
            } catch (AiException) {
                // The search goes on with the question's own words
                $notices[] = t('ask.expand_failed');
            }
        }
        // Step 2: its meaning, by the one embedding model, when one is set up (the question de-identified first)
        $vector = null;
        if ($this->embedder !== null && $this->archive->semantic()) {
            try {
                $vector = $this->embedder->embed([$this->assistant->safeQuestion(trim($standalone . ' ' . implode(' ', \array_slice($extra, 0, 10))))])[0] ?? null;
            } catch (AiException) {
                $notices[] = t('ask.embed_failed');
            }
        }
        // Step 3: the passages, by words and by meaning
        $sources = $this->archive->sources($standalone, $principal, $extra, $vector);
        // Passages from outside the wiki (the `ask.sources` hook: HippoBridge), waited for with the rest
        $passages = [];
        foreach ($this->hooks->all('ask.sources', $standalone, $principal) as $item) {
            if (\is_array($item) && \is_string($item['notice'] ?? null)) {
                $notices[] = $item['notice'];
            } elseif (\is_array($item) && \is_string($item['text'] ?? null)) {
                $passages[] = $item + ['date' => null, 'label' => '', 'names' => [], 'cnp' => ''];
            }
        }
        // At most SOURCES in all: the outside ones keep a few places so a full archive does not hide them
        $passages = \array_slice($passages, 0, self::OUTSIDE);
        $sources = \array_slice($sources, 0, ArchiveAsk::SOURCES - \count($passages));
        foreach ($passages as $i => $passage) {
            $passages[$i]['n'] = \count($sources) + $i + 1;
        }
        /** @var list<array{n: int, text: string, date: ?string, names: list<string>, cnp: string, label: string}> $passages */
        $shown = array_map(static fn (array $s): array => [
            'n' => $s['n'],
            'href' => '/r/' . rawurlencode($s['pid']) . '/' . $s['rev'],
            'heading' => $s['heading'],
            'text' => $s['text'],
            'study_date' => $s['study_date'],
            'modality' => $s['modality'],
            'mine' => $s['mine'],
        ], $sources);
        // What the model reads of the outside texts, not what their system holds: no name or CNP on the page either
        $labels = array_column($passages, 'label', 'n');
        foreach (Context::deidentifyPassages($passages)[1] as $passage) {
            $shown[] = ['n' => $passage['n'], 'heading' => '', 'text' => $passage['text'], 'study_date' => $passage['date'], 'modality' => $labels[$passage['n']], 'mine' => false];
        }
        if ($sources === [] && $passages === []) {
            return ApiResponse::json(['data' => ['answer' => null, 'sources' => [], 'notices' => $notices, 'understood' => $standalone !== $question ? $standalone : null]]);
        }
        try {
            $done = $this->assistant->runArchive($action, $standalone, $sources, $principal, $request, $passages);
        } catch (AiException $e) {
            return AiController::error($e);
        }

        return ApiResponse::json(['data' => [
            'answer' => $done['result'],
            'sources' => $shown,
            'notices' => $notices,
            // The question as searched, when the first step made it another; and what was searched
            'understood' => $standalone !== $question ? $standalone : null,
            'searched' => ['terms' => \array_slice(array_values(array_unique([...ArchiveAsk::terms($standalone), ...$extra])), 0, 12), 'meaning' => $vector !== null],
            'context' => $done['contextSet'],
            'ms' => $done['ms'],
            'provider' => $done['provider'],
        ]]);
    }

    /** Whether there is anything to ask: the assistant on and the prompt there */
    public function available(): bool
    {
        return $this->action() !== null;
    }

    /**
     * The earlier turns the browser sends, as far as they are well formed:
     * the latest HISTORY, each a question and its answer, cut to size. Nothing
     * here is trusted: Context::expand() de-identifies them before any model.
     *
     * @return list<array{question: string, answer: string}>
     */
    private static function history(mixed $raw): array
    {
        $turns = [];
        foreach (\is_array($raw) ? \array_slice(array_values($raw), -self::HISTORY) : [] as $turn) {
            $q = \is_array($turn) && \is_string($turn['question'] ?? null) ? mb_substr(trim($turn['question']), 0, ArchiveAsk::MAX_QUESTION) : '';
            $a = \is_array($turn) && \is_string($turn['answer'] ?? null) ? mb_substr(trim($turn['answer']), 0, self::HISTORY_ANSWER) : '';
            if ($q !== '' && $a !== '') {
                $turns[] = ['question' => $q, 'answer' => $a];
            }
        }

        return $turns;
    }

    private function expandAction(): ?Action
    {
        return $this->config->isConfigured() ? $this->actions->specialUnder(ReportPath::ROOT, 'ask-expand') : null;
    }

    private function action(): ?Action
    {
        return $this->config->isConfigured() ? $this->actions->specialUnder(ReportPath::ROOT, 'ask-archive') : null;
    }
}
