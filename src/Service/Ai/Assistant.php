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
 *
 * A failed call is tried again, once per entry of $delays (DELAYS, or
 * `conf['ai']['retry_delays']`), after that many seconds (2026-10-09): busy (another request of the same user still running), a
 * server that cannot be reached, a timeout, any error the server answers.
 * Not when part of the answer has already gone to $emit — the caller has
 * it, and a second answer would follow the first — and not a refusal of
 * Context's (identifier_leak: the same text is refused the same way). Each
 * try that reaches a model is its own `ai.call` line, `attempt` from 2.
 */
final class Assistant
{
    public function __construct(
        private readonly Context $context,
        /** @var \Closure(?string): ?ProviderInterface the provider of a server by name (null: the one in use), or null when there is none */
        private readonly \Closure $providers,
        private readonly AuditLog $audit,
        private readonly string $lockDir,
        /** @var list<int> seconds before each retry, one retry each */
        private readonly array $delays = self::DELAYS,
    ) {
    }

    /** Three retries, each after a longer wait */
    public const DELAYS = [2, 4, 8];

    /** The longest a run() can take with the default retries and a server that times out every time, for set_time_limit() */
    public static function maxSeconds(int $timeout): int
    {
        return (\count(self::DELAYS) + 1) * $timeout + array_sum(self::DELAYS);
    }

    /**
     * `conf['ai']['retry_delays']`: a list of seconds, at most a minute each; anything else is DELAYS
     *
     * @param array<string, mixed> $config
     *
     * @return list<int>
     */
    public static function delaysFromConfig(array $config): array
    {
        $delays = $config['ai']['retry_delays'] ?? null;
        if (!\is_array($delays) || \count($delays) > 5) {
            return self::DELAYS;
        }

        return array_values(array_map(static fn (mixed $d): int => max(0, min(60, (int) $d)), $delays));
    }

    /**
     * @param \Closure(string): void $emit receives each piece of the answer
     * @param ?string $with narrows {history} to one study (Context::build())
     *
     * @return array{result: string, ms: int, usage: array<string, int>, contextSet: list<string>, provider: string}
     *
     * @throws AiException busy, identifier_leak, or the provider's reason
     */
    public function run(Action $action, PageRecord $page, string $text, string $textLabel, ?int $exam, string $customPrompt, User $user, ?Request $request, \Closure $emit, ?string $with = null): array
    {
        $provider = ($this->providers)($action->server);
        if ($provider === null) {
            throw new AiException('unavailable', 'The action\'s server "' . $action->server . '" is not set up (Admin → AI)');
        }
        $emitted = false;
        $emitOnce = static function (string $piece) use ($emit, &$emitted): void {
            $emitted = true;
            $emit($piece);
        };
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $this->attempt($provider, $action, $page, $text, $textLabel, $exam, $customPrompt, $user, $request, $emitOnce, $with, $attempt);
            } catch (AiException $e) {
                if ($attempt > \count($this->delays) || $emitted || $e->reason === 'identifier_leak') {
                    throw $e;
                }
                if ($this->delays[$attempt - 1] > 0) {
                    sleep($this->delays[$attempt - 1]);
                }
            }
        }
    }

    /**
     * One try of run()
     *
     * @return array{result: string, ms: int, usage: array<string, int>, contextSet: list<string>, provider: string}
     *
     * @throws AiException
     */
    private function attempt(ProviderInterface $provider, Action $action, PageRecord $page, string $text, string $textLabel, ?int $exam, string $customPrompt, User $user, ?Request $request, \Closure $emit, ?string $with, int $attempt): array
    {
        $lock = $this->lock($user->username);
        $started = hrtime(true);
        $result = '';
        $prompt = null;
        $reason = 'interrupted';
        $status = null;
        try {
            try {
                $prompt = $this->context->build($action, $page, $text, $user, $textLabel, $exam, $customPrompt, $with);
            } catch (AiException $e) {
                $this->audit->record('ai.refused', $user->username, $request, $page->pid, $page->path, $page->rev, 'denied', ['ai_action' => $action->id, 'reason' => $e->reason]);
                throw $e;
            }
            try {
                // An answer Context already knows (no history to compare) asks no model;
                // what Context escaped comes back as written
                $entities = new EntityFilter();
                foreach ($prompt->reply !== null ? [$prompt->reply] : $provider->stream($prompt) as $piece) {
                    $piece = $entities->push($piece);
                    if ($piece !== '') {
                        $result .= $piece;
                        $emit($piece);
                    }
                }
                if (($rest = $entities->finish()) !== '') {
                    $result .= $rest;
                    $emit($rest);
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
                    // Phase 34f: the server could not answer and its fallback did (or was tried)
                    'failover' => $provider instanceof FailoverProvider ? $provider->failover() : null,
                    'context' => $prompt->contextSet,
                    'ms' => intdiv(hrtime(true) - $started, 1_000_000),
                    'usage' => $provider->usage() ?: null,
                    'reason' => $reason,
                    'status' => $status,
                    'attempt' => $attempt > 1 ? $attempt : null,
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
