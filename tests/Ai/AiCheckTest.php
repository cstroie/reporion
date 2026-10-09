<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use PHPUnit\Framework\TestCase;
use Reporion\Cli\AiCheckCommand;
use Reporion\Cli\Output;

/** bin/reporion ai:check: settings, egress verdict, models — never report text */
final class AiCheckTest extends TestCase
{
    public function testItListsTheModelsOfAReachableServer(): void
    {
        $server = new FakeServer();
        try {
            [$exit, $out] = $this->run2(['ai' => ['enabled' => true, 'servers' => [['endpoint' => $server->url, 'api_key' => 'k', 'tiers' => ['normal' => ['model' => 'test-model']]]]]], ['--json']);
            $report = json_decode($out, true);
            self::assertSame(0, $exit);
            self::assertSame('allowed', $report['egress']);
            self::assertFalse($report['external']);
            self::assertSame(['other-model', 'test-model'], $report['models']);
            self::assertSame('set', $report['api_key'], 'never the key itself');
            self::assertSame('/v1/models', $server->lastRequest()['path'], 'the only request it makes');

            [$exit] = $this->run2(['ai' => ['enabled' => true, 'servers' => [['endpoint' => $server->url, 'tiers' => ['normal' => ['model' => 'missing-model']]]]]], []);
            self::assertSame(1, $exit, 'a model the server does not list');

            // LM Studio's native /api/v1 answers, but not with an OpenAI list: said, not "ok"
            [$exit, $out] = $this->run2(['ai' => ['enabled' => true, 'servers' => [['endpoint' => str_replace('/v1', '/api/v1', $server->url), 'tiers' => ['normal' => ['model' => 'test-model']]]]]], ['--json']);
            self::assertSame(1, $exit);
            self::assertStringContainsString('…/v1 base', json_decode($out, true)['error']);
        } finally {
            $server->stop();
        }
    }

    public function testNotConfiguredSaysSo(): void
    {
        [$exit, $out] = $this->run2([], ['--json']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Not configured', json_decode($out, true)['error']);
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $args
     *
     * @return array{0: int, 1: string}
     */
    private function run2(array $config, array $args): array
    {
        $stdout = fopen('php://memory', 'w+');
        $exit = (new AiCheckCommand($config))->run($args, new Output($stdout, fopen('php://memory', 'w+')));
        rewind($stdout);

        return [$exit, (string) stream_get_contents($stdout)];
    }
}
