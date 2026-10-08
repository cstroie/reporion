<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Reporion\Service\Ai\Usage;

/**
 * Admin → AI's *Usage* (roadmap phase 34b): counts, times and tokens read
 * from the audit's ai.call / ai.refused lines over a period; nothing else
 * in the audit counted, nothing of a prompt or an answer anywhere.
 */
final class UsageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/reporion-usage-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testCountsTimesAndTokensPerActionModelAndUser(): void
    {
        $now = new DateTimeImmutable('2026-10-08T12:00:00+03:00');
        $line = static fn (array $l): string => json_encode($l + ['ts' => '2026-10-07T10:00:00+03:00', 'outcome' => 'ok']) . "\n";
        file_put_contents($this->dir . '/2026-10.ndjson',
            $line(['actor' => 'ana', 'action' => 'ai.call', 'ai_action' => 'conclusion', 'provider' => 'local · qwen', 'ms' => 1000, 'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 50]])
            . $line(['actor' => 'ana', 'action' => 'ai.call', 'ai_action' => 'conclusion', 'provider' => 'local · qwen', 'ms' => 3000])
            . $line(['actor' => 'mihai', 'action' => 'ai.call', 'ai_action' => 'summary', 'provider' => 'api.x · big', 'ms' => 90, 'outcome' => 'error', 'reason' => 'timeout'])
            . $line(['actor' => 'mihai', 'action' => 'ai.refused', 'ai_action' => 'summary', 'outcome' => 'denied', 'reason' => 'identifier_leak'])
            . $line(['actor' => 'owner', 'action' => 'page.save']));
        // Older than the period, in last month's file: not counted
        file_put_contents($this->dir . '/2026-09.ndjson', $line(['ts' => '2026-09-01T10:00:00+03:00', 'actor' => 'ana', 'action' => 'ai.call', 'ai_action' => 'old', 'ms' => 5]));

        $usage = (new Usage($this->dir))->compute($now, 30);

        self::assertSame([3, 1, 1, 300, 50], [$usage['calls'], $usage['errors'], $usage['refused'], $usage['tokensIn'], $usage['tokensOut']]);
        self::assertSame([1000, 3000], [$usage['p50'], $usage['p90']], 'only successful calls are timed');
        self::assertSame(['conclusion', 'summary'], array_keys($usage['actions']), 'most used first; the old one outside the period');
        self::assertSame(['calls' => 2, 'errors' => 0, 'tokensIn' => 300, 'tokensOut' => 50, 'p50' => 1000, 'p90' => 3000], $usage['actions']['conclusion']);
        self::assertSame(1, $usage['models']['api.x · big']['errors']);
        self::assertSame(['ana' => 2, 'mihai' => 1], $usage['users']);
        self::assertSame(['timeout' => 1, 'identifier_leak' => 1], $usage['reasons']);
        self::assertSame(30, (new Usage($this->dir))->compute($now, 12)['days'], 'only 7, 30 or 90 days');
    }
}
