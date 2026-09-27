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

    public function testModelsAreListed(): void
    {
        self::assertSame(['other-model', 'test-model'], (new OpenAiCompatibleProvider($this->config(), new EgressGuard()))->models());
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
        return new AiConfig($enabled, $endpoint ?? $this->server->url, $model, 0.3, 0.8, 0, 5, [], false, 'secret-key');
    }
}
