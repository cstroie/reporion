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
        $body = array_filter([
            'model' => $this->config->model,
            'messages' => [
                ['role' => 'system', 'content' => $prompt->system],
                ['role' => 'user', 'content' => $prompt->user],
            ],
            'temperature' => $this->config->temperature,
            'top_p' => $this->config->topP,
            'max_tokens' => $this->config->maxTokens > 0 ? $this->config->maxTokens : null,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ], static fn (mixed $v): bool => $v !== null);

        $handle = $this->open('POST', '/chat/completions', (string) json_encode($body, JSON_UNESCAPED_UNICODE));
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

    public function describe(): string
    {
        return (string) parse_url($this->config->endpoint, PHP_URL_HOST) . ' · ' . $this->config->model;
    }

    /**
     * @return resource
     *
     * @throws AiException
     */
    private function open(string $method, string $path, ?string $body)
    {
        if (!$this->config->isConfigured()) {
            throw new AiException('not_configured', 'The AI assistant is not configured');
        }
        $url = $this->config->endpoint . $path;
        $this->egress->assertAllowed($url, $this->config->externalAck);

        $headers = ['Content-Type: application/json', 'Accept: text/event-stream, application/json'];
        if ($this->config->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->config->apiKey;
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => (float) $this->config->timeout,
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
            stream_set_timeout($handle, $this->config->timeout);
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
            $message = \is_array($error) ? (string) ($error['error']['message'] ?? $error['error'] ?? '') : '';
            $reason = match (true) {
                $status === 401 || $status === 403 => 'unauthorized',
                $status === 429 => 'rate_limited',
                default => 'provider_error',
            };
            throw new AiException($reason, 'The AI server answered ' . $status . ($message !== '' ? ': ' . mb_substr($message, 0, 200) : ''), $status);
        }
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
