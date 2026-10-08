<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\IndexInterface;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\Assistant;
use Reporion\Service\Ai\EgressGuard;
use Reporion\Storage\StorageInterface;
use Throwable;

/**
 * The assistant over HTTP (roadmap phase 15c):
 *
 * - `POST /api/v1/ai/complete {path, action, text | source: "page", label?, exam?, prompt?, stream?}`
 *   — for a caller who may write the page (404 otherwise, invariant 9).
 *   With `stream: true` the answer comes as Server-Sent Events
 *   (`event: delta` `{"text"}` … then `event: done` `{ms, usage, context,
 *   provider}` or `event: error` `{code, message}`); otherwise one JSON
 *   `{result, ms, usage, context, provider}`. It never writes to the page (A3).
 * - `GET /api/v1/ai/providers` — whether the assistant is on, and where it
 *   goes (host · model, and whether that is outside this network), for a
 *   signed-in user.
 */
final class AiController
{
    private const MAX_TEXT = 200_000;

    public function __construct(
        private readonly AiConfig $config,
        private readonly Actions $actions,
        private readonly Assistant $assistant,
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
    ) {
    }

    public function complete(Request $request, ?User $principal): Response
    {
        $fields = $request->json();
        $path = \is_string($fields['path'] ?? null) ? trim($fields['path'], ': ') : '';
        if ($principal === null || $path === '' || $this->index->findByPath($path, $principal) === null || !$principal->canWrite($path)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        if (!$this->config->isConfigured()) {
            return ApiResponse::error(503, 'not_configured', 'The assistant is not configured.');
        }
        $action = $this->actions->find($path, \is_string($fields['action'] ?? null) ? $fields['action'] : '');
        if ($action === null) {
            return ApiResponse::error(404, 'unknown_action', 'No such assistant action for this page.');
        }
        $page = $this->storage->read($path);
        // `source: "page"` — the saved text rather than one the browser sends
        // (the report view's Summarize button has no textarea to send from)
        $fromPage = ($fields['source'] ?? null) === 'page';
        $text = $fromPage ? $page->body : (\is_string($fields['text'] ?? null) ? $fields['text'] : '');
        if (mb_strlen($text) > self::MAX_TEXT) {
            return ApiResponse::error(413, 'too_long', 'The text is too long for the assistant.');
        }
        $label = \is_string($fields['label'] ?? null) && preg_match('/^[\p{L}\p{N} ]{1,40}$/u', $fields['label']) === 1 ? $fields['label'] : 'text';
        $exam = \is_int($fields['exam'] ?? null) && $fields['exam'] > 0 ? $fields['exam'] : null;
        $custom = \is_string($fields['prompt'] ?? null) ? mb_substr($fields['prompt'], 0, 4000) : '';
        $run = fn (\Closure $emit): array => $this->assistant->run($action, $page, $text, $label, $exam, $custom, $principal, $request, $emit);

        if (($fields['stream'] ?? false) !== true) {
            try {
                $done = $run(static function (string $piece): void {
                });
            } catch (AiException $e) {
                return self::error($e);
            }

            return ApiResponse::json(['result' => $done['result'], 'ms' => $done['ms'], 'usage' => $done['usage'], 'context' => $done['contextSet'], 'provider' => $done['provider']]);
        }

        return Response::eventStream(function () use ($run): void {
            set_time_limit($this->config->timeout + 30);
            $send = static function (string $event, array $data): void {
                echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            };
            try {
                $done = $run(static fn (string $piece) => $send('delta', ['text' => $piece]));
                $send('done', ['ms' => $done['ms'], 'usage' => $done['usage'], 'context' => $done['contextSet'], 'provider' => $done['provider']]);
            } catch (AiException $e) {
                $send('error', ['code' => $e->reason, 'message' => self::message($e)]);
            } catch (Throwable $e) {
                error_log(\sprintf('%s at %s:%d', $e::class, $e->getFile(), $e->getLine()));
                $send('error', ['code' => 'internal', 'message' => self::message('internal')]);
            }
        });
    }

    public function providers(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        $external = null;
        if ($this->config->endpoint !== '') {
            try {
                $external = (new EgressGuard())->isExternal($this->config->endpoint);
            } catch (AiException) {
                $external = null;
            }
        }

        return ApiResponse::json(['data' => $this->config->isConfigured() ? [[
            'type' => 'openai-compatible',
            'host' => (string) parse_url($this->config->endpoint, PHP_URL_HOST),
            'model' => $this->config->model,
            'external' => $external,
        ]] : []]);
    }

    private static function error(AiException $e): Response
    {
        $status = match ($e->reason) {
            'busy', 'rate_limited' => 429,
            'identifier_leak' => 422,
            'not_configured', 'egress_denied', 'bad_endpoint' => 503,
            default => 502,
        };

        return ApiResponse::error($status, $e->reason, self::message($e));
    }

    /**
     * What the user reads: our sentence for the reason, then the server's
     * own words when it answered with an error — to the signed-in user who
     * sent the request only; the audit line keeps just the reason and status
     */
    private static function message(AiException $e): string
    {
        $key = 'ai.err.' . $e->reason;
        $message = t($key) !== $key ? t($key) : t('ai.err.provider_error');

        return $e->detail !== '' ? $message . ' (' . $e->detail . ')' : $message;
    }
}
