<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use PHPUnit\Framework\TestCase;
use Reporion\Exception\AiException;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\AnthropicProvider;
use Reporion\Service\Ai\EgressGuard;
use Reporion\Service\Ai\OllamaProvider;
use Reporion\Service\Ai\OpenAiCompatibleProvider;
use Reporion\Service\Ai\Prompt;
use Reporion\Service\Ai\Providers;

/**
 * The native protocols (2026-11-19): Anthropic's Messages API and Ollama's
 * own API, against the fake server, and which one a server's address picks.
 */
final class NativeProvidersTest extends TestCase
{
    private FakeServer $server;

    protected function setUp(): void
    {
        $this->server = new FakeServer();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function testTheProtocolComesFromTheSettingElseFromTheAddress(): void
    {
        self::assertSame('anthropic', AiConfig::apiFor('https://api.anthropic.com/v1', 'auto'));
        self::assertSame('ollama', AiConfig::apiFor('http://10.0.0.5:11434', 'auto'));
        self::assertSame('ollama', AiConfig::apiFor('http://10.0.0.5:11434/v1', ''));
        self::assertSame('openai', AiConfig::apiFor('https://openrouter.ai/api/v1', 'auto'));
        self::assertSame('openai', AiConfig::apiFor('https://api.anthropic.com/v1', 'openai'), 'an explicit choice wins');
        self::assertSame('ollama', AiConfig::apiFor('http://gpu.lan:8080', 'ollama'));
        self::assertSame('openai', AiConfig::apiFor('http://gpu.lan:8080', 'nonsense'));

        self::assertInstanceOf(AnthropicProvider::class, Providers::for($this->config('anthropic')));
        self::assertInstanceOf(OllamaProvider::class, Providers::for($this->config('ollama')));
        self::assertInstanceOf(OpenAiCompatibleProvider::class, Providers::for($this->config('auto')));
        self::assertNull(Providers::embedder($this->config('anthropic')), 'Anthropic has no embeddings');
        self::assertSame('https://api.anthropic.com/v1', AiConfig::base('https://api.anthropic.com/v1/messages'));
        self::assertSame('http://h:11434', AiConfig::base('http://h:11434/api/chat'));
    }

    public function testAnthropicStreamsTheTextOnlyCachesThePromptAndSendsNoSampling(): void
    {
        $provider = new AnthropicProvider($this->config('anthropic', ['temperature' => 0.3, 'top_p' => 0.9, 'effort' => 'high', 'extra' => ['metadata' => ['user_id' => 'x'], 'model' => 'evil']]), new EgressGuard());

        $pieces = iterator_to_array($provider->stream(new Prompt('Ești radiolog.', 'Scrie concluzia.', [], 'normal', null, 900)), false);

        self::assertSame('Concluzie: fără leziuni.', implode('', $pieces), 'thinking never reaches the text');
        self::assertSame(['prompt_tokens' => 42, 'completion_tokens' => 7, 'cache_read_tokens' => 30, 'cache_write_tokens' => 2], $provider->usage());
        $sent = $this->server->lastRequest();
        self::assertSame('/v1/messages', $sent['path']);
        self::assertSame('secret-key', $sent['x-api-key']);
        self::assertSame('', $sent['auth'], 'the key goes in x-api-key, not as a bearer');
        self::assertSame(AnthropicProvider::VERSION, $sent['anthropic-version']);
        $body = $sent['body'];
        self::assertSame('test-model', $body['model'], 'extra never overrides the request');
        self::assertSame(900, $body['max_tokens']);
        self::assertSame([['type' => 'text', 'text' => 'Ești radiolog.', 'cache_control' => ['type' => 'ephemeral']]], $body['system']);
        self::assertSame(['type' => 'ephemeral'], $body['cache_control']);
        self::assertSame([['role' => 'user', 'content' => 'Scrie concluzia.']], $body['messages']);
        self::assertSame(['effort' => 'high'], $body['output_config']);
        self::assertSame(['user_id' => 'x'], $body['metadata']);
        foreach (['temperature', 'top_p', 'top_k', 'min_p'] as $key) {
            self::assertArrayNotHasKey($key, $body);
        }
    }

    public function testAnthropicNeedsAMaxTokensAndGivesLiteNoSystemPrompt(): void
    {
        iterator_to_array((new AnthropicProvider($this->config('anthropic'), new EgressGuard()))->stream(new Prompt('s', 'u', [], 'lite')), false);
        $body = $this->server->lastRequest()['body'];

        self::assertSame(AnthropicProvider::MAX_TOKENS, $body['max_tokens']);
        self::assertArrayNotHasKey('system', $body);
        self::assertArrayNotHasKey('output_config', $body);
    }

    public function testAnthropicRefusalsCutsAndErrorsAreErrors(): void
    {
        foreach (['refuse' => 'provider_error', 'cut-mid-stream' => 'interrupted', 'fail-mid-stream' => 'provider_error'] as $model => $reason) {
            try {
                iterator_to_array((new AnthropicProvider($this->config('anthropic', [], $model), new EgressGuard()))->stream(new Prompt('s', 'u', [])), false);
                self::fail($model . ' must fail');
            } catch (AiException $e) {
                self::assertSame($reason, $e->reason, $model);
            }
        }
    }

    public function testAnthropicTriesOnceMoreWhenOverloaded(): void
    {
        $pieces = iterator_to_array((new AnthropicProvider($this->config('anthropic', [], 'overloaded'), new EgressGuard()))->stream(new Prompt('s', 'u', [])), false);

        self::assertSame('Concluzie: fără leziuni.', implode('', $pieces));
        self::assertSame(2, $this->server->requests());
    }

    public function testAnthropicListsEveryPageOfModels(): void
    {
        self::assertSame(['claude-a', 'claude-b', 'claude-c'], (new AnthropicProvider($this->config('anthropic'), new EgressGuard()))->models());
    }

    public function testOllamaSetsTheContextWindowAndReadsItsLines(): void
    {
        $provider = new OllamaProvider($this->config('ollama', ['temperature' => 0.2, 'top_k' => 20, 'max_tokens' => 500, 'extra' => ['keep_alive' => '30m', 'options' => ['repeat_penalty' => 1.1]]]), new EgressGuard());

        $pieces = iterator_to_array($provider->stream(new Prompt('Ești radiolog.', 'Scrie concluzia.', [])), false);

        self::assertSame('Concluzie: fără leziuni.', implode('', $pieces));
        self::assertSame(['prompt_tokens' => 42, 'completion_tokens' => 7], $provider->usage());
        $sent = $this->server->lastRequest();
        self::assertSame('/api/chat', $sent['path'], 'the endpoint\'s /v1 is dropped: the native API is at the root');
        $body = $sent['body'];
        self::assertEquals(['temperature' => 0.2, 'top_k' => 20, 'num_predict' => 500, 'num_ctx' => OllamaProvider::NUM_CTX, 'repeat_penalty' => 1.1], $body['options'], 'the alias\'s parameters as options, extra options beside them');
        self::assertSame('30m', $body['keep_alive']);
        self::assertSame([['role' => 'system', 'content' => 'Ești radiolog.'], ['role' => 'user', 'content' => 'Scrie concluzia.']], $body['messages']);

        iterator_to_array((new OllamaProvider($this->config('ollama', ['num_ctx' => 65536]), new EgressGuard()))->stream(new Prompt('s', 'u', [])), false);
        self::assertSame(65536, $this->server->lastRequest()['body']['options']['num_ctx']);
    }

    public function testOllamaListsModelsEmbedsAndNoticesACut(): void
    {
        $provider = new OllamaProvider($this->config('ollama'), new EgressGuard());
        self::assertSame(['llama3.3:70b', 'qwen3:32b'], $provider->models());
        self::assertSame([[1.0, 2.0, 0.5], [1.0, 3.0, 0.5]], $provider->embeddings(['ab', 'abc'], 'nomic-embed-text'));
        self::assertSame('/api/embed', $this->server->lastRequest()['path']);

        $this->expectException(AiException::class);
        iterator_to_array((new OllamaProvider($this->config('ollama', [], 'cut-mid-stream'), new EgressGuard()))->stream(new Prompt('s', 'u', [])), false);
    }

    /** @param array<string, mixed> $tier */
    private function config(string $api, array $tier = [], string $model = 'test-model'): AiConfig
    {
        return AiConfig::fromConfig(['ai' => ['enabled' => true, 'servers' => [[
            'endpoint' => $this->server->url, 'api' => $api, 'api_key' => 'secret-key', 'timeout' => 10,
            'tiers' => ['normal' => ['model' => $model] + $tier],
        ]]]]);
    }
}
