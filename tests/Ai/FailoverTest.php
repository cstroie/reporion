<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use PHPUnit\Framework\TestCase;
use Reporion\Exception\AiException;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\EgressGuard;
use Reporion\Service\Ai\FailoverProvider;
use Reporion\Service\Ai\OpenAiCompatibleProvider;
use Reporion\Service\Ai\Prompt;

/**
 * Failover (roadmap phase 34f): a server that cannot be reached hands the
 * same prompt to its fallback, once; a server that answers with a refusal
 * or a 4xx does not.
 */
final class FailoverTest extends TestCase
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

    private function provider(string $endpoint, string $model): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider(new AiConfig(true, $endpoint, $model, null, null, 0, 5, 'reports', ['reports'], false, ''), new EgressGuard());
    }

    public function testAnUnreachableServerHandsThePromptToItsFallback(): void
    {
        // Port 9 (discard) on loopback: nothing listens, the connection is refused
        $failover = new FailoverProvider($this->provider('http://127.0.0.1:9/v1', 'test-model'), $this->provider($this->server->url, 'test-model'), 'Down', 'Fake');

        $answer = implode('', iterator_to_array($failover->stream(new Prompt('s', 'u', [])), false));

        self::assertSame('Concluzie: fără leziuni.', $answer);
        self::assertSame(['from' => 'Down', 'to' => 'Fake', 'reason' => 'unreachable'], $failover->failover());
        self::assertStringContainsString('127.0.0.1', $failover->describe(), 'the one that answered');
        self::assertSame(['prompt_tokens' => 42, 'completion_tokens' => 7], $failover->usage());
    }

    public function testARefusalOrA4xxIsNotRetriedElsewhere(): void
    {
        $failover = new FailoverProvider($this->provider($this->server->url, 'fail-400'), $this->provider($this->server->url, 'test-model'), 'First', 'Second');

        try {
            iterator_to_array($failover->stream(new Prompt('s', 'u', [])));
            self::fail('a 400 is the request\'s fault: no failover');
        } catch (AiException $e) {
            self::assertSame(400, $e->status);
            self::assertNull($failover->failover());
        }
        self::assertFalse(FailoverProvider::movesOn(new AiException('identifier_leak')));
        self::assertTrue(FailoverProvider::movesOn(new AiException('provider_error', '', 502)));
        self::assertTrue(FailoverProvider::movesOn(new AiException('timeout')));
    }

    public function testTheSettingIsAnotherSlotNeverItself(): void
    {
        $config = ['ai' => ['servers' => [['endpoint' => 'http://a/v1', 'fallback' => 2], ['endpoint' => 'http://b/v1', 'fallback' => 2], ['endpoint' => 'http://c/v1', 'fallback' => 9]]]];

        self::assertSame(2, AiConfig::fromConfig($config, 1)->fallback);
        self::assertNull(AiConfig::fromConfig($config, 2)->fallback, 'not itself');
        self::assertNull(AiConfig::fromConfig($config, 3)->fallback, 'not a slot that is not one');
    }
}
