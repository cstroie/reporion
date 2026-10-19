<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Http\Request;
use Reporion\Support\ReportPath;

/**
 * What a plugin may use of Ask the archive (D53): the `ask-archive` prompt
 * answered from passages the plugin found elsewhere (HippoBridge's HIS
 * texts). It takes passages and a question and nothing else: the prompt is
 * built by Ai\Context (D15), de-identified from the name and CNP each
 * passage carries, and the call is the Assistant's, with its lock, retries
 * and `ai.call` audit line. The plugin never holds the Assistant, Actions
 * or Context.
 */
final class ArchiveAnswer
{
    public function __construct(
        private readonly AiConfig $config,
        private readonly Actions $actions,
        private readonly Assistant $assistant,
    ) {
    }

    /** Whether there is anything to ask: the assistant on and the prompt there */
    public function available(): bool
    {
        return $this->action() !== null;
    }

    /** Seconds the call may take with its retries */
    public function seconds(): int
    {
        return Assistant::maxSeconds($this->config->timeout) + 30;
    }

    /**
     * The passages as the model reads them, de-identified, for the screen to
     * show: a name and CNP the HIS text holds never reach the caller's page
     * either (invariant 8)
     *
     * @param list<array{n: int, text: string, date: ?string, names: list<string>, cnp: string}> $passages
     *
     * @return list<array{n: int, text: string, date: ?string}>
     */
    public function shown(array $passages): array
    {
        return array_map(static fn (array $p): array => ['n' => $p['n'], 'text' => $p['text'], 'date' => $p['date']], Context::deidentifyPassages($passages)[1]);
    }

    /**
     * @param list<array{n: int, text: string, date: ?string, names: list<string>, cnp: string}> $passages
     *
     * @return array{answer: string, context: list<string>, ms: int, provider: string}
     *
     * @throws AiException unavailable, busy, identifier_leak, or the provider's reason
     */
    public function answer(string $question, array $passages, User $user, ?Request $request = null): array
    {
        $action = $this->action() ?? throw new AiException('unavailable', 'Ask the archive is not set up (Admin → AI)');
        $done = $this->assistant->runArchivePassages($action, $question, $passages, $user, $request);

        return ['answer' => $done['result'], 'context' => $done['contextSet'], 'ms' => $done['ms'], 'provider' => $done['provider']];
    }

    private function action(): ?Action
    {
        return $this->config->isConfigured() ? $this->actions->specialUnder(ReportPath::ROOT, 'ask-archive') : null;
    }
}
