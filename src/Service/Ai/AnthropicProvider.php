<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Generator;
use Reporion\Exception\AiException;

/**
 * Anthropic's own Messages API (2026-11-19): `POST {endpoint}/messages`
 * (`https://api.anthropic.com/v1`) streamed as Server-Sent Events, plain HTTP
 * through HttpClient (no SDK; the same egress rule, time limit and retry as
 * every server). Over the OpenAI-compatible layer it adds prompt caching
 * (the system prompt and the whole request are marked, so a repeated
 * instruction or context is read back at a tenth of the price), the alias's
 * `effort`, and usage that tells cache reads and writes apart.
 *
 * Sampling parameters (temperature, top_p, top_k, min_p) are never sent:
 * current Claude models refuse them. Thinking is left to the model (adaptive
 * by default); its blocks are not shown, only the text.
 */
final class AnthropicProvider implements ProviderInterface
{
    /** The API version every request names (pinned: newer ones change the request's shape) */
    public const VERSION = '2023-06-01';

    /** The answer's room when neither the action nor the alias caps it: the Messages API needs one */
    public const MAX_TOKENS = 16000;

    /** @var array{prompt_tokens?: int, completion_tokens?: int, cache_read_tokens?: int, cache_write_tokens?: int} */
    private array $usage = [];

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
        $timeout = $prompt->timeout > 0 ? min($prompt->timeout, $this->config->timeout) : $this->config->timeout;
        $handle = $this->http()->open('POST', '/messages', (string) json_encode($this->body($prompt), JSON_UNESCAPED_UNICODE), $timeout);
        // Whole only when the server says so (`message_stop`): a connection that just stops is cut short
        $ended = false;
        $usage = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }
                $event = json_decode(trim(substr($line, 5)), true);
                if (!\is_array($event)) {
                    continue;
                }
                switch ($event['type'] ?? '') {
                    case 'message_start':
                        $usage = self::usageOf($event['message']['usage'] ?? null) + $usage;
                        break;
                    case 'content_block_delta':
                        // Only the text: thinking and signature deltas are the model's own
                        $delta = $event['delta'] ?? null;
                        if (\is_array($delta) && ($delta['type'] ?? '') === 'text_delta' && \is_string($delta['text'] ?? null) && $delta['text'] !== '') {
                            yield $delta['text'];
                        }
                        break;
                    case 'message_delta':
                        $usage = self::usageOf($event['usage'] ?? null) + $usage;
                        if (($event['delta']['stop_reason'] ?? null) === 'refusal') {
                            throw new AiException('provider_error', 'The model declined to answer', null, 'refusal');
                        }
                        break;
                    case 'message_stop':
                        $ended = true;
                        break 2;
                    case 'error':
                        $message = HttpClient::errorMessage($event);
                        throw new AiException('provider_error', 'The AI server sent an error in its answer', null, 'in the answer' . ($message !== '' ? ': ' . $message : ''));
                }
            }
            if (HttpClient::timedOut($handle)) {
                throw new AiException('timeout', 'The AI server took too long');
            }
            if (!$ended) {
                throw new AiException('interrupted', 'The AI server stopped before the end of its answer');
            }
        } finally {
            fclose($handle);
            $this->usage = self::totals($usage);
        }
    }

    public function usage(): array
    {
        return $this->usage;
    }

    public function models(): array
    {
        $models = [];
        $after = '';
        // Paged: at most a few pages of a hundred
        for ($page = 0; $page < 10; ++$page) {
            $handle = $this->http()->open('GET', '/models?limit=100' . ($after !== '' ? '&after_id=' . rawurlencode($after) : ''), null);
            $json = json_decode((string) stream_get_contents($handle), true);
            fclose($handle);
            foreach (\is_array($json['data'] ?? null) ? $json['data'] : [] as $model) {
                if (\is_array($model) && \is_string($model['id'] ?? null)) {
                    $models[] = $model['id'];
                }
            }
            $after = \is_string($json['last_id'] ?? null) ? $json['last_id'] : '';
            if (($json['has_more'] ?? false) !== true || $after === '') {
                break;
            }
        }
        $models = array_values(array_unique($models));
        sort($models);

        return $models;
    }

    public function describe(string $tier = AiConfig::DEFAULT_TIER): string
    {
        return (string) parse_url($this->config->endpoint, PHP_URL_HOST) . ' · ' . $this->config->modelFor($tier);
    }

    /**
     * The Messages request for $prompt
     *
     * @return array<string, mixed>
     */
    private function body(Prompt $prompt): array
    {
        $tier = $this->config->settingsFor($prompt->tier);
        $cap = min(array_filter([$tier->maxTokens, $prompt->maxTokens], static fn (int $n): bool => $n > 0) ?: [self::MAX_TOKENS]);
        // The owner's own keys, never over the request's (as TierSettings::RESERVED), nor sampling ones
        $extra = array_diff_key($tier->extra, array_flip([...TierSettings::RESERVED, 'system', 'max_tokens', 'temperature', 'top_p', 'top_k', 'min_p']));
        $body = [
            'model' => $tier->model,
            'max_tokens' => $cap,
            // Caching (2026-11-19): the request as a whole is marked, the last block cached; a
            // prefix shorter than the model's minimum is simply not cached
            'cache_control' => ['type' => 'ephemeral'],
            'messages' => [['role' => 'user', 'content' => $prompt->user]],
            'stream' => true,
        ];
        // An action on the lite alias gets no system prompt (the owner's choice, 2026-10-08)
        if (AiConfig::tier($prompt->tier) !== 'lite' && $prompt->system !== '') {
            $body['system'] = [['type' => 'text', 'text' => $prompt->system, 'cache_control' => ['type' => 'ephemeral']]];
        }
        if ($tier->effort !== '') {
            $body['output_config'] = ['effort' => $tier->effort];
        }

        return $body + $extra;
    }

    private function http(): HttpClient
    {
        $headers = ['Content-Type: application/json', 'Accept: text/event-stream, application/json', 'anthropic-version: ' . self::VERSION];
        if ($this->config->apiKey !== '') {
            $headers[] = 'x-api-key: ' . $this->config->apiKey;
        }

        return new HttpClient($this->config, $this->egress, $headers);
    }

    /**
     * The counts a usage block carries
     *
     * @return array<string, int>
     */
    private static function usageOf(mixed $usage): array
    {
        $out = [];
        foreach (['input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens'] as $key) {
            if (\is_array($usage) && \is_int($usage[$key] ?? null)) {
                $out[$key] = $usage[$key];
            }
        }

        return $out;
    }

    /**
     * The usage as ProviderInterface reports it: the prompt counts every input token, cached or not
     *
     * @param array<string, int> $usage
     *
     * @return array{prompt_tokens?: int, completion_tokens?: int, cache_read_tokens?: int, cache_write_tokens?: int}
     */
    private static function totals(array $usage): array
    {
        if ($usage === []) {
            return [];
        }
        $read = $usage['cache_read_input_tokens'] ?? 0;
        $write = $usage['cache_creation_input_tokens'] ?? 0;

        return array_filter([
            'prompt_tokens' => isset($usage['input_tokens']) ? $usage['input_tokens'] + $read + $write : null,
            'completion_tokens' => $usage['output_tokens'] ?? null,
            'cache_read_tokens' => $read > 0 ? $read : null,
            'cache_write_tokens' => $write > 0 ? $write : null,
        ], static fn (?int $v): bool => $v !== null);
    }
}
