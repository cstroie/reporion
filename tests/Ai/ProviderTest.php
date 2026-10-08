<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use PHPUnit\Framework\TestCase;
use Reporion\Exception\AiException;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\EgressGuard;
use Reporion\Service\Ai\OpenAiCompatibleProvider;
use Reporion\Service\Ai\Prompt;
use Reporion\Service\Ai\ThinkFilter;

/**
 * The OpenAI-compatible provider against a fake server (phase 15a): the
 * stream reassembles, <think> is gone even when split, usage is read, and
 * the egress rule holds.
 */
final class ProviderTest extends TestCase
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

    public function testTheAnswerStreamsWithoutItsThinking(): void
    {
        $provider = new OpenAiCompatibleProvider($this->config(), new EgressGuard());

        $pieces = iterator_to_array($provider->stream(new Prompt('Ești radiolog.', 'Scrie concluzia.', [])), false);

        self::assertSame('Concluzie: fără leziuni.', implode('', $pieces));
        self::assertGreaterThan(1, \count($pieces), 'it streams, not one block');
        self::assertSame(['prompt_tokens' => 42, 'completion_tokens' => 7], $provider->usage());

        $sent = $this->server->lastRequest();
        self::assertSame('/v1/chat/completions', $sent['path']);
        self::assertSame('Bearer secret-key', $sent['auth']);
        self::assertSame([['role' => 'system', 'content' => 'Ești radiolog.'], ['role' => 'user', 'content' => 'Scrie concluzia.']], $sent['body']['messages']);
        self::assertTrue($sent['body']['stream']);
        self::assertSame('test-model', $sent['body']['model']);
    }

    public function testAnActionsAliasPicksTheModelAndAnEmptyOneFallsBackToNormal(): void
    {
        $config = AiConfig::fromConfig(['ai' => ['enabled' => true, 'endpoint' => $this->server->url, 'model' => 'test-model', 'model_expert' => 'other-model']]);

        self::assertSame('other-model', $config->modelFor('expert'));
        self::assertSame('test-model', $config->modelFor('lite'), 'no lite model: the normal one');
        self::assertSame('test-model', $config->modelFor('normal'));
        self::assertSame('test-model', $config->modelFor('whatever'), 'an unknown alias is normal');
        self::assertSame('expert', AiConfig::tier(' Expert '));
        self::assertSame('normal', AiConfig::tier(''));

        $provider = new OpenAiCompatibleProvider($config, new EgressGuard());
        iterator_to_array($provider->stream(new Prompt('s', 'u', [], 'expert')), false);
        self::assertSame('other-model', $this->server->lastRequest()['body']['model']);
        self::assertStringEndsWith('· other-model', $provider->describe('expert'));
        iterator_to_array($provider->stream(new Prompt('s', 'u', [])), false);
        self::assertSame('test-model', $this->server->lastRequest()['body']['model'], 'a prompt with no alias is normal');
    }

    public function testAnAliasSendsOnlyItsFilledParametersAndItsExtraFieldsLast(): void
    {
        $config = AiConfig::fromConfig(['ai' => ['enabled' => true, 'servers' => [['endpoint' => $this->server->url, 'tiers' => [
            'normal' => ['model' => 'test-model', 'temperature' => '', 'top_k' => 20, 'max_tokens' => 500, 'extra' => ['reasoning_effort' => 'low', 'stream' => false]],
        ]]]]]);
        iterator_to_array((new OpenAiCompatibleProvider($config, new EgressGuard()))->stream(new Prompt('s', 'u', [], 'normal', null, 300)), false);
        $body = $this->server->lastRequest()['body'];

        self::assertArrayNotHasKey('temperature', $body, 'blank: not sent');
        self::assertArrayNotHasKey('top_p', $body);
        self::assertSame(20, $body['top_k']);
        self::assertSame('low', $body['reasoning_effort'], 'extra merged into the request');
        self::assertTrue($body['stream'], 'extra never overrides the request itself');
        self::assertSame(300, $body['max_tokens'], 'the action\'s cap and the alias\'s: the smaller');
    }

    public function testModelsAreListed(): void
    {
        self::assertSame(['other-model', 'test-model'], (new OpenAiCompatibleProvider($this->config(), new EgressGuard()))->models());
    }

    /**
     * The anthropic-version header (2026-09-28): Anthropic's own
     * OpenAI-compatible endpoint 400s without it — "anthropic-version:
     * header is required" — even though every other OpenAI-compatible
     * server this class talks to (vLLM, llama.cpp, LM Studio, Ollama)
     * neither needs nor understands it, so it is sent only to
     * api.anthropic.com, not egress-testable end to end (DNS), hence
     * reflection on the pure header-building method.
     */
    public function testAnthropicVersionHeaderOnlyForApiAnthropicCom(): void
    {
        $anthropic = new OpenAiCompatibleProvider(
            new AiConfig(true, 'https://api.anthropic.com/v1', 'claude-sonnet-4-6', 0.3, 0.8, 0, 5, 'reports', ['reports'], false, 'secret-key'),
            new EgressGuard()
        );
        $other = new OpenAiCompatibleProvider($this->config(), new EgressGuard());

        $headersFor = static function (OpenAiCompatibleProvider $provider): array {
            $method = new \ReflectionMethod($provider, 'headers');
            $method->setAccessible(true);

            return $method->invoke($provider);
        };

        self::assertContains('anthropic-version: 2023-06-01', $headersFor($anthropic));
        self::assertNotContains('anthropic-version: 2023-06-01', $headersFor($other));
    }

    /**
     * 2026-09-29: claude-sonnet-4-6 answered every "Create" with a 400 —
     * "`temperature` and `top_p` cannot both be specified for this model".
     * A blank setting is not sent, so the owner leaves one of them blank.
     */
    public function testABlankSamplingSettingIsNotSent(): void
    {
        $bodyFor = static function (AiConfig $config): array {
            $provider = new OpenAiCompatibleProvider($config, new EgressGuard());
            $method = new \ReflectionMethod($provider, 'body');
            $method->setAccessible(true);

            return $method->invoke($provider, new Prompt('s', 'u', []));
        };
        $both = $bodyFor($this->config());
        $blank = $bodyFor(new AiConfig(true, 'https://api.anthropic.com/v1', 'claude-sonnet-4-6', 0.3, null, 0, 5, 'reports', ['reports'], false, 'secret-key'));

        self::assertSame([0.3, 0.8], [$both['temperature'], $both['top_p']]);
        self::assertSame(0.3, $blank['temperature']);
        self::assertArrayNotHasKey('top_p', $blank);
        self::assertArrayNotHasKey('max_tokens', $blank);
    }

    public function testAFullCompletionsAddressIsTakenBackToItsBase(): void
    {
        foreach (['https://openrouter.ai/api/v1/chat/completions', 'https://openrouter.ai/api/v1/', 'https://openrouter.ai/api/v1/models', ' https://openrouter.ai/api/v1 '] as $endpoint) {
            self::assertSame('https://openrouter.ai/api/v1', \Reporion\Service\Ai\AiConfig::fromConfig(['ai' => ['endpoint' => $endpoint]])->endpoint, $endpoint);
        }
    }

    public function testA429IsTriedOnceMoreThenSaidPlainly(): void
    {
        $answer = implode('', iterator_to_array((new OpenAiCompatibleProvider($this->config(model: 'flaky-429'), new EgressGuard()))->stream(new Prompt('s', 'u', [])), false));
        self::assertSame('Concluzie: fără leziuni.', $answer, 'the second try answers');

        try {
            iterator_to_array((new OpenAiCompatibleProvider($this->config(model: 'fail-429'), new EgressGuard()))->stream(new Prompt('s', 'u', [])));
            self::fail('expected an AiException');
        } catch (AiException $e) {
            self::assertSame('rate_limited', $e->reason);
            self::assertSame(429, $e->status);
        }
    }

    public function testAServerErrorIsAReasonNeverTheReportText(): void
    {
        try {
            iterator_to_array((new OpenAiCompatibleProvider($this->config(model: 'fail-401'), new EgressGuard()))->stream(new Prompt('s', 'u', [])));
            self::fail('expected an AiException');
        } catch (AiException $e) {
            self::assertSame('unauthorized', $e->reason);
        }
    }

    public function testAnErrorKeepsTheServersOwnWordsOnOneLine(): void
    {
        try {
            iterator_to_array((new OpenAiCompatibleProvider($this->config(model: 'fail-400'), new EgressGuard()))->stream(new Prompt('s', 'u', [])));
            self::fail('expected an AiException');
        } catch (AiException $e) {
            self::assertSame('provider_error', $e->reason);
            self::assertSame(400, $e->status);
            self::assertSame('HTTP 400: `temperature` and `top_p` cannot both be specified for this model. Please use only one.', $e->detail);
            self::assertSame('The AI server answered 400', $e->getMessage(), 'the message, which may be logged, stays without the server\'s words');
        }
    }

    public function testAnErrorInsideTheStreamIsSaidNotSwallowed(): void
    {
        try {
            iterator_to_array((new OpenAiCompatibleProvider($this->config(model: 'fail-mid-stream'), new EgressGuard()))->stream(new Prompt('s', 'u', [])));
            self::fail('expected an AiException');
        } catch (AiException $e) {
            self::assertSame('provider_error', $e->reason);
            self::assertSame('in the answer: Upstream model overloaded', $e->detail);
        }
    }

    public function testNotConfiguredOrAnUnreachableServerIsSaid(): void
    {
        foreach ([[$this->config(enabled: false), 'not_configured'], [$this->config(endpoint: 'http://127.0.0.1:9/v1'), 'unreachable']] as [$config, $reason]) {
            try {
                iterator_to_array((new OpenAiCompatibleProvider($config, new EgressGuard()))->stream(new Prompt('s', 'u', [])));
                self::fail('expected ' . $reason);
            } catch (AiException $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function testEgressStaysOnThePrivateNetworkUnlessAccepted(): void
    {
        $guard = new EgressGuard(static fn (string $host): array => ['llm.lan' => ['192.168.1.20'], 'api.example.com' => ['93.184.216.34']][$host] ?? []);

        $guard->assertAllowed('http://127.0.0.1:8080/v1', false);
        $guard->assertAllowed('http://localhost/v1', false);
        $guard->assertAllowed('http://10.0.0.5/v1', false);
        $guard->assertAllowed('http://llm.lan:11434/v1', false);
        self::assertTrue($guard->isExternal('https://api.example.com/v1'));

        try {
            $guard->assertAllowed('https://api.example.com/v1', false);
            self::fail('a public host needs the acknowledgement');
        } catch (AiException $e) {
            self::assertSame('egress_denied', $e->reason);
        }
        $guard->assertAllowed('https://api.example.com/v1', true);

        $this->expectException(AiException::class);
        $guard->assertAllowed('file:///etc/passwd', true);
    }

    public function testTheThinkFilterHandlesEveryWayATagCanBeCut(): void
    {
        $text = 'a<think>hidden</think>b<think>x</think>c';
        foreach ([1, 2, 3, 5, 7] as $size) {
            $filter = new ThinkFilter();
            $out = '';
            foreach (str_split($text, $size) as $chunk) {
                $out .= $filter->push($chunk);
            }
            self::assertSame('abc', $out . $filter->finish(), 'chunks of ' . $size);
        }
        $filter = new ThinkFilter();
        self::assertSame('a <thin', $filter->push('a <thin') . $filter->finish(), 'a partial tag at the end is just text');
    }

    private function config(bool $enabled = true, ?string $endpoint = null, string $model = 'test-model'): AiConfig
    {
        return new AiConfig($enabled, $endpoint ?? $this->server->url, $model, 0.3, 0.8, 0, 5, 'reports', ['reports'], false, 'secret-key');
    }
}
