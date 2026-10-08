<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Http\Request;
use Reporion\Storage\PageRecord;

/**
 * One assistant request (roadmap phase 15c): the prompt from Context, the
 * answer streamed from the provider piece by piece to $emit, and one audit
 * line — `ai.call` with the action, provider, what the context held, time
 * and tokens, never the prompt or the answer (invariant 8); `ai.refused`
 * when the chokepoint stopped it. One request at a time per user: a local
 * model can hold a PHP worker for a minute.
 */
final class Assistant
{
    public function __construct(
        private readonly Context $context,
        /** @var \Closure(?string): ?ProviderInterface the provider of a server by name (null: the one in use), or null when there is none */
        private readonly \Closure $providers,
        private readonly AuditLog $audit,
        private readonly string $lockDir,
    ) {
    }

    /**
     * @param \Closure(string): void $emit receives each piece of the answer
     *
     * @return array{result: string, ms: int, usage: array<string, int>, contextSet: list<string>, provider: string}
     *
     * @throws AiException busy, identifier_leak, or the provider's reason
     */
    public function run(Action $action, PageRecord $page, string $text, string $textLabel, ?int $exam, string $customPrompt, User $user, ?Request $request, \Closure $emit): array
    {
        $provider = ($this->providers)($action->server);
        if ($provider === null) {
            throw new AiException('unavailable', 'The action\'s server "' . $action->server . '" is not set up (Admin → AI)');
        }
        $lock = $this->lock($user->username);
        $started = hrtime(true);
        $result = '';
        $prompt = null;
        $reason = 'interrupted';
        $status = null;
        try {
            try {
                $prompt = $this->context->build($action, $page, $text, $user, $textLabel, $exam, $customPrompt);
            } catch (AiException $e) {
                $this->audit->record('ai.refused', $user->username, $request, $page->pid, $page->path, $page->rev, 'denied', ['ai_action' => $action->id, 'reason' => $e->reason]);
                throw $e;
            }
            try {
                // An answer Context already knows (no history to compare) asks no model
                foreach ($prompt->reply !== null ? [$prompt->reply] : $provider->stream($prompt) as $piece) {
                    $result .= $piece;
                    $emit($piece);
                }
                $reason = null;
            } catch (AiException $e) {
                $reason = $e->reason;
                $status = $e->status;
                throw $e;
            }
        } finally {
            if ($prompt !== null) {
                $this->audit->record('ai.call', $user->username, $request, $page->pid, $page->path, $page->rev, $reason === null ? 'ok' : 'error', array_filter([
                    'ai_action' => $action->id,
                    'provider' => $provider->describe($action->model),
                    'context' => $prompt->contextSet,
                    'ms' => intdiv(hrtime(true) - $started, 1_000_000),
                    'usage' => $provider->usage() ?: null,
                    'reason' => $reason,
                    'status' => $status,
                ], static fn (mixed $v): bool => $v !== null));
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return [
            'result' => $result,
            'ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $provider->usage(),
            'contextSet' => $prompt->contextSet,
            'provider' => $provider->describe($action->model),
        ];
    }

    /**
     * @return resource
     *
     * @throws AiException busy
     */
    private function lock(string $username)
    {
        if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0775, true) && !is_dir($this->lockDir)) {
            throw new AiException('unavailable', 'Cannot prepare the assistant');
        }
        $lock = fopen($this->lockDir . '/' . $username . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new AiException('busy', 'Another assistant request of yours is still running');
        }

        return $lock;
    }
}
