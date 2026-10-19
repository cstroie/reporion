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
use Reporion\Service\Ai\Action;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\Assistant;
use Reporion\Service\ArchiveAsk;
use Reporion\Support\ReportPath;

/**
 * Ask the archive (D53, 2026-10-19):
 *
 * - `GET /ask[?q=…]`: the screen, for a signed-in user (anyone else 404); it says so when
 *   the assistant is off or the profile serving `reports` has no
 *   `ask-archive` prompt.
 * - `POST /api/v1/ask {question}` → `{data: {answer, sources: [{n, href,
 *   heading, text, study_date, modality, mine}], context, ms, provider}}`:
 *   the passages of finished reports the caller can read that the question
 *   is about (Service\ArchiveAsk, invariant 6), and the `ask-archive`
 *   prompt's answer from them, de-identified (Ai\Context::archive(),
 *   invariant 8), citing them by number. A source is linked by pid and rev
 *   (`/r/{pid}/{rev}`), never by path. No source: `answer` null and no
 *   model asked. Shown, never written; audited as any assistant call
 *   (`ai.call`, no page).
 */
final class AskController
{
    public function __construct(
        private readonly AiConfig $config,
        private readonly Actions $actions,
        private readonly Assistant $assistant,
        private readonly ArchiveAsk $archive,
        private readonly IndexInterface $index,
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
        $sources = $this->archive->sources($question, $principal);
        $shown = array_map(static fn (array $s): array => [
            'n' => $s['n'],
            'href' => '/r/' . rawurlencode($s['pid']) . '/' . $s['rev'],
            'heading' => $s['heading'],
            'text' => $s['text'],
            'study_date' => $s['study_date'],
            'modality' => $s['modality'],
            'mine' => $s['mine'],
        ], $sources);
        if ($sources === []) {
            return ApiResponse::json(['data' => ['answer' => null, 'sources' => []]]);
        }
        // Room for Assistant's retries
        @set_time_limit(Assistant::maxSeconds($this->config->timeout) + 30);
        try {
            $done = $this->assistant->runArchive($action, $question, $sources, $principal, $request);
        } catch (AiException $e) {
            return AiController::error($e);
        }

        return ApiResponse::json(['data' => [
            'answer' => $done['result'],
            'sources' => $shown,
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

    private function action(): ?Action
    {
        return $this->config->isConfigured() ? $this->actions->specialUnder(ReportPath::ROOT, 'ask-archive') : null;
    }
}
