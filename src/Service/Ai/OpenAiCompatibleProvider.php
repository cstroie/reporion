<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Generator;
use Reporion\Exception\AiException;

/**
 * Any OpenAI-compatible chat server (decided 2026-09-27): vLLM, llama.cpp,
 * LM Studio, Ollama's /v1, or OpenAI itself. `POST {endpoint}/chat/completions`
 * with `stream: true`, read as Server-Sent Events line by line through PHP's
 * HTTP stream wrapper; `<think>…</think>` is filtered out on the way. The
 * endpoint is checked against the egress rule before every request.
 */
final class OpenAiCompatibleProvider implements ProviderInterface
{
    /** @var array{prompt_tokens?: int, completion_tokens?: int} */
    private array $usage = [];

    /** Tries for a request the server refused with 429, and the longest wait between them */
    private const ATTEMPTS = 2;
    private const MAX_WAIT_S = 5;

    public function __construct(
        private readonly AiConfig $config,
        private readonly EgressGuard $egress,
    ) {
    }

    public function stream(Prompt $prompt): Generator
    {
        $this->usage = [];
        if ($this->config->settingsFor($prompt->tier)->model === '') {
            throw new AiException('not_configured', 'The AI assistant is not configured');
        }
        // The action's own timeout (a prompt page's `timeout:`) and the server's: the smaller
        $timeout = $prompt->timeout > 0 ? min($prompt->timeout, $this->config->timeout) : $this->config->timeout;
        $handle = $this->open('POST', '/chat/completions', (string) json_encode($this->body($prompt), JSON_UNESCAPED_UNICODE), $timeout);
        $think = new ThinkFilter();
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    break;
                }
                $event = json_decode($data, true);
                if (!\is_array($event)) {
                    continue;
                }
                // A server that failed after its 200 (OpenRouter does) says so in the stream
                if (isset($event['error'])) {
                    $message = self::errorMessage($event);
                    throw new AiException('provider_error', 'The AI server sent an error in its answer', null, 'in the answer' . ($message !== '' ? ': ' . $message : ''));
                }
                if (\is_array($event['usage'] ?? null)) {
                    $this->usage = array_filter([
                        'prompt_tokens' => \is_int($event['usage']['prompt_tokens'] ?? null) ? $event['usage']['prompt_tokens'] : null,
                        'completion_tokens' => \is_int($event['usage']['completion_tokens'] ?? null) ? $event['usage']['completion_tokens'] : null,
                    ], static fn (?int $v): bool => $v !== null);
                }
                $delta = $event['choices'][0]['delta']['content'] ?? null;
                if (\is_string($delta) && $delta !== '') {
                    $text = $think->push($delta);
                    if ($text !== '') {
                        yield $text;
                    }
                }
            }
            $meta = stream_get_meta_data($handle);
            if (($meta['timed_out'] ?? false) === true) {
                throw new AiException('timeout', 'The AI server took too long');
            }
        } finally {
            fclose($handle);
        }
        $rest = $think->finish();
        if ($rest !== '') {
            yield $rest;
        }
    }

    public function usage(): array
    {
        return $this->usage;
    }

    public function models(): array
    {
        $handle = $this->open('GET', '/models', null);
        $json = json_decode((string) stream_get_contents($handle), true);
        fclose($handle);
        $models = [];
        foreach (\is_array($json['data'] ?? null) ? $json['data'] : [] as $model) {
            if (\is_array($model) && \is_string($model['id'] ?? null)) {
                $models[] = $model['id'];
            }
        }
        sort($models);

        return $models;
    }

    /**
     * Phase 34e: `POST {endpoint}/embeddings` — one vector per text, in the
     * order given
     *
     * @param list<string> $texts
     *
     * @return list<list<float>>
     *
     * @throws AiException
     */
    public function embeddings(array $texts, string $model): array
    {
        $handle = $this->open('POST', '/embeddings', (string) json_encode(['model' => $model, 'input' => $texts], JSON_UNESCAPED_UNICODE));
        $json = json_decode((string) stream_get_contents($handle), true);
        $meta = stream_get_meta_data($handle);
        fclose($handle);
        if (($meta['timed_out'] ?? false) === true) {
            throw new AiException('timeout', 'The AI server took too long');
        }
        $vectors = [];
        foreach (\is_array($json['data'] ?? null) ? $json['data'] : [] as $i => $item) {
            $vector = \is_array($item) && \is_array($item['embedding'] ?? null) ? $item['embedding'] : null;
            if ($vector === null || $vector === [] || array_filter($vector, static fn (mixed $v): bool => !\is_int($v) && !\is_float($v)) !== []) {
                continue;
            }
            $vectors[\is_int($item['index'] ?? null) ? $item['index'] : $i] = array_map('floatval', array_values($vector));
        }
        ksort($vectors);
        if (\count($vectors) !== \count($texts)) {
            throw new AiException('provider_error', 'The AI server did not answer with one embedding per text');
        }

        return array_values($vectors);
    }

    public function describe(string $tier = AiConfig::DEFAULT_TIER): string
    {
        return (string) parse_url($this->config->endpoint, PHP_URL_HOST) . ' · ' . $this->config->modelFor($tier);
    }

    /**
     * The chat request for $prompt
     *
     * @return array<string, mixed>
     */
    private function body(Prompt $prompt): array
    {
        // The alias's own model and parameters (phase 33a): a blank one is not
        // sent, the server decides — newer Claude models refuse temperature,
        // top_p and top_k altogether
        $tier = $this->config->settingsFor($prompt->tier);
        $params = $tier->params();
        // The action's cap (a prompt page's `max_tokens:`) and the alias's: the smaller
        $cap = min(array_filter([(int) ($params['max_tokens'] ?? 0), $prompt->maxTokens], static fn (int $n): bool => $n > 0) ?: [0]);
        unset($params['max_tokens']);

        return [
            'model' => $tier->model,
            // An action on the lite alias gets no system prompt (the owner's choice, 2026-10-08)
            'messages' => array_values(array_filter([
                AiConfig::tier($prompt->tier) === 'lite' ? null : ['role' => 'system', 'content' => $prompt->system],
                ['role' => 'user', 'content' => $prompt->user],
            ])),
        ] + ($cap > 0 ? ['max_tokens' => $cap] : []) + $params + [
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        $headers = ['Content-Type: application/json', 'Accept: text/event-stream, application/json'];
        if ($this->config->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->config->apiKey;
        }
        // Anthropic's own OpenAI-compatible endpoint (api.anthropic.com) is
        // otherwise "any OpenAI-compatible server" like the rest of this
        // class, but it 400s without this header — required on every
        // request, not part of the OpenAI shape, so no other server needs it.
        if (parse_url($this->config->endpoint, PHP_URL_HOST) === 'api.anthropic.com') {
            $headers[] = 'anthropic-version: 2023-06-01';
        }

        return $headers;
    }

    /**
     * @return resource
     *
     * @throws AiException
     */
    private function open(string $method, string $path, ?string $body, ?int $timeout = null)
    {
        $timeout ??= $this->config->timeout;
        // Only an address is needed: listing a server's models comes before
        // choosing one (Admin → AI, phase 33c); whether the assistant is on
        // is its callers' to say
        if ($this->config->endpoint === '') {
            throw new AiException('not_configured', 'The AI assistant is not configured');
        }
        $url = $this->config->endpoint . $path;
        $this->egress->assertAllowed($url, $this->config->externalAck);

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $this->headers()),
                'content' => $body ?? '',
                'timeout' => (float) $timeout,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
        ]);
        // A 429 is often a shared free quota for a few seconds: one more try
        for ($attempt = 1; ; ++$attempt) {
            $handle = @fopen($url, 'r', false, $context);
            if ($handle === false) {
                throw new AiException('unreachable', 'The AI server cannot be reached');
            }
            stream_set_timeout($handle, $timeout);
            $headers = stream_get_meta_data($handle)['wrapper_data'] ?? [];
            $status = self::status($headers);
            if ($status >= 200 && $status < 300) {
                return $handle;
            }
            $error = json_decode((string) stream_get_contents($handle, 4096), true);
            fclose($handle);
            if ($status === 429 && $attempt < self::ATTEMPTS) {
                usleep(self::retryAfter($headers) * 1000);
                continue;
            }
            $message = self::errorMessage($error);
            $reason = match (true) {
                $status === 401 || $status === 403 => 'unauthorized',
                $status === 429 => 'rate_limited',
                default => 'provider_error',
            };
            throw new AiException($reason, 'The AI server answered ' . $status, $status, 'HTTP ' . $status . ($message !== '' ? ': ' . $message : ''));
        }
    }

    /**
     * The server's own explanation from an error body — OpenAI's
     * `{error: {message}}`, or a bare `{error: "…"}` — on one line, capped.
     */
    private static function errorMessage(mixed $error): string
    {
        $message = \is_array($error) ? ($error['error']['message'] ?? $error['error'] ?? $error['message'] ?? '') : '';
        if (!\is_string($message)) {
            return '';
        }
        $message = trim((string) preg_replace('/\s+/u', ' ', $message));

        return mb_strlen($message) > 300 ? mb_substr($message, 0, 300) . '…' : $message;
    }

    /**
     * How long to wait before the retry, in milliseconds: the server's
     * Retry-After when it is short, else two seconds.
     *
     * @param mixed $headers the stream's response headers
     */
    private static function retryAfter(mixed $headers): int
    {
        foreach (\is_array($headers) ? $headers : [] as $header) {
            if (\is_string($header) && preg_match('/^Retry-After:\s*(\d+)\s*$/i', $header, $m) === 1) {
                return min((int) $m[1], self::MAX_WAIT_S) * 1000;
            }
        }

        return 2000;
    }

    /** @param mixed $headers the stream's response headers */
    private static function status(mixed $headers): int
    {
        $status = 0;
        foreach (\is_array($headers) ? $headers : [] as $header) {
            // The last status line wins (after a redirect or a 100 Continue)
            if (\is_string($header) && preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }
}
