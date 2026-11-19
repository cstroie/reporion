<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Generator;
use Reporion\Exception\AiException;

/**
 * Ollama's native API (2026-11-19): `POST {root}/api/chat` streamed as one
 * JSON object per line, `GET /api/tags`, `POST /api/embed`. Over its `/v1`
 * layer it sets the context window (`options.num_ctx`): Ollama otherwise cuts
 * a long prompt to its small default without a word, and a Workup, Dossier or
 * Ask prompt loses its first half. The root is the endpoint without a trailing
 * `/v1` or `/api`, so either address works.
 */
final class OllamaProvider implements ProviderInterface, EmbeddingProvider
{
    /** The context window when the alias sets none: room for the long prompts */
    public const NUM_CTX = 16384;

    /** @var array{prompt_tokens?: int, completion_tokens?: int} */
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
        $handle = $this->http()->open('POST', '/api/chat', (string) json_encode($this->body($prompt), JSON_UNESCAPED_UNICODE), $timeout);
        $think = new ThinkFilter();
        $ended = false;
        try {
            while (($line = fgets($handle)) !== false) {
                $event = json_decode(trim($line), true);
                if (!\is_array($event)) {
                    continue;
                }
                if (isset($event['error'])) {
                    $message = HttpClient::errorMessage($event);
                    throw new AiException('provider_error', 'The AI server sent an error in its answer', null, 'in the answer' . ($message !== '' ? ': ' . $message : ''));
                }
                $delta = $event['message']['content'] ?? null;
                if (\is_string($delta) && $delta !== '') {
                    $text = $think->push($delta);
                    if ($text !== '') {
                        yield $text;
                    }
                }
                if (($event['done'] ?? false) === true) {
                    $this->usage = array_filter([
                        'prompt_tokens' => \is_int($event['prompt_eval_count'] ?? null) ? $event['prompt_eval_count'] : null,
                        'completion_tokens' => \is_int($event['eval_count'] ?? null) ? $event['eval_count'] : null,
                    ], static fn (?int $v): bool => $v !== null);
                    $ended = true;
                    break;
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
        $handle = $this->http()->open('GET', '/api/tags', null);
        $json = json_decode((string) stream_get_contents($handle), true);
        fclose($handle);
        $models = [];
        foreach (\is_array($json['models'] ?? null) ? $json['models'] : [] as $model) {
            $name = \is_array($model) ? ($model['name'] ?? $model['model'] ?? null) : null;
            if (\is_string($name) && $name !== '') {
                $models[] = $name;
            }
        }
        $models = array_values(array_unique($models));
        sort($models);

        return $models;
    }

    public function embeddings(array $texts, string $model): array
    {
        $handle = $this->http()->open('POST', '/api/embed', (string) json_encode(['model' => $model, 'input' => $texts], JSON_UNESCAPED_UNICODE));
        $json = json_decode((string) stream_get_contents($handle), true);
        $timedOut = HttpClient::timedOut($handle);
        fclose($handle);
        if ($timedOut) {
            throw new AiException('timeout', 'The AI server took too long');
        }
        $vectors = [];
        foreach (\is_array($json['embeddings'] ?? null) ? $json['embeddings'] : [] as $vector) {
            if (!\is_array($vector) || $vector === [] || array_filter($vector, static fn (mixed $v): bool => !\is_int($v) && !\is_float($v)) !== []) {
                continue;
            }
            $vectors[] = array_map('floatval', array_values($vector));
        }
        if (\count($vectors) !== \count($texts)) {
            throw new AiException('provider_error', 'The AI server did not answer with one embedding per text');
        }

        return $vectors;
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
        $tier = $this->config->settingsFor($prompt->tier);
        $cap = min(array_filter([$tier->maxTokens, $prompt->maxTokens], static fn (int $n): bool => $n > 0) ?: [0]);
        $options = array_filter([
            'temperature' => $tier->temperature,
            'top_p' => $tier->topP,
            'top_k' => $tier->topK,
            'min_p' => $tier->minP,
            'num_predict' => $cap > 0 ? $cap : null,
            'num_ctx' => $tier->numCtx > 0 ? $tier->numCtx : self::NUM_CTX,
        ], static fn (mixed $v): bool => $v !== null);
        // The owner's own keys (`keep_alive`, `think`, `options`…), never over the request's
        $extra = array_diff_key($tier->extra, array_flip([...TierSettings::RESERVED, 'max_tokens']));
        if (\is_array($extra['options'] ?? null)) {
            $options = $extra['options'] + $options;
            unset($extra['options']);
        }

        return [
            'model' => $tier->model,
            // An action on the lite alias gets no system prompt (the owner's choice, 2026-10-08)
            'messages' => array_values(array_filter([
                AiConfig::tier($prompt->tier) === 'lite' ? null : ['role' => 'system', 'content' => $prompt->system],
                ['role' => 'user', 'content' => $prompt->user],
            ])),
            'options' => $options,
            'stream' => true,
        ] + $extra;
    }

    private function http(): HttpClient
    {
        $headers = ['Content-Type: application/json', 'Accept: application/x-ndjson, application/json'];
        if ($this->config->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->config->apiKey;
        }

        return new HttpClient($this->config, $this->egress, $headers, (string) preg_replace('~/(?:v1|api)$~i', '', $this->config->endpoint));
    }
}
