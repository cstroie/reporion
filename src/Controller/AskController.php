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
use Reporion\Service\ArchiveAsk;
use Reporion\Support\ReportPath;

/**
 * Ask the archive (D53, 2026-10-19):
 *
 * - `GET /ask[?q=…]`: the screen, for a signed-in user (anyone else 404); it says so when
 *   the assistant is off or the profile serving `reports` has no
 *   `ask-archive` prompt.
 * - `POST /api/v1/ask {question}` → `{data: {answer, sources: [{n, href,
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
    public function __construct(
        private readonly AiConfig $config,
        private readonly Actions $actions,
        private readonly Assistant $assistant,
        private readonly ArchiveAsk $archive,
        private readonly IndexInterface $index,
        private readonly Hooks $hooks,
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
        // Passages from outside the wiki (the `ask.sources` hook: HippoBridge), waited for with the rest
        $passages = [];
        $notices = [];
        foreach ($this->hooks->all('ask.sources', $question, $principal) as $item) {
            if (\is_array($item) && \is_string($item['notice'] ?? null)) {
                $notices[] = $item['notice'];
            } elseif (\is_array($item) && \is_string($item['text'] ?? null)) {
                $passages[] = $item + ['date' => null, 'label' => '', 'names' => [], 'cnp' => ''];
            }
        }
        // At most SOURCES in all: the outside ones keep a few places (three) so a full archive does not hide them
        $passages = \array_slice($passages, 0, min(\count($passages), 3));
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
            return ApiResponse::json(['data' => ['answer' => null, 'sources' => [], 'notices' => $notices]]);
        }
        // Room for Assistant's retries
        @set_time_limit(Assistant::maxSeconds($this->config->timeout) + 30);
        try {
            $done = $this->assistant->runArchive($action, $question, $sources, $principal, $request, $passages);
        } catch (AiException $e) {
            return AiController::error($e);
        }

        return ApiResponse::json(['data' => [
            'answer' => $done['result'],
            'sources' => $shown,
            'notices' => $notices,
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
