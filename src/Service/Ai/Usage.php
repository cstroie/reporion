<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use DateTimeImmutable;

/**
 * The assistant's use over a period, for Admin → AI's *Usage* panel
 * (roadmap phase 34b) — read from the audit lines already written
 * (`ai.call`, `ai.refused`; data/audit/YYYY-MM.ndjson), nothing stored for
 * it. Counts, times and tokens per action, per server and model, per user;
 * failures by reason. The audit carries no prompt or answer, so neither
 * does this.
 */
final class Usage
{
    public const PERIODS = [7, 30, 90];

    public function __construct(private readonly string $auditDir)
    {
    }

    /**
     * @return array{days: int, calls: int, errors: int, refused: int, tokensIn: int, tokensOut: int, p50: ?int, p90: ?int,
     *               actions: array<string, array<string, mixed>>, models: array<string, array<string, mixed>>,
     *               users: array<string, int>, reasons: array<string, int>}
     */
    public function compute(DateTimeImmutable $now, int $days): array
    {
        $days = \in_array($days, self::PERIODS, true) ? $days : 30;
        $since = $now->modify('-' . $days . ' days');
        $out = ['days' => $days, 'calls' => 0, 'errors' => 0, 'refused' => 0, 'tokensIn' => 0, 'tokensOut' => 0, 'p50' => null, 'p90' => null, 'actions' => [], 'models' => [], 'users' => [], 'reasons' => []];
        $times = [];
        $byAction = [];
        $byModel = [];

        foreach ($this->lines($since, $now) as $line) {
            $action = \is_string($line['ai_action'] ?? null) ? $line['ai_action'] : '?';
            $reason = \is_string($line['reason'] ?? null) ? $line['reason'] : '';
            if ($line['action'] === 'ai.refused') {
                ++$out['refused'];
                $out['reasons'][$reason !== '' ? $reason : 'refused'] = ($out['reasons'][$reason !== '' ? $reason : 'refused'] ?? 0) + 1;
                continue;
            }
            $ok = ($line['outcome'] ?? 'ok') === 'ok';
            $ms = \is_int($line['ms'] ?? null) ? $line['ms'] : null;
            $in = \is_int($line['usage']['prompt_tokens'] ?? null) ? $line['usage']['prompt_tokens'] : 0;
            $tokens = \is_int($line['usage']['completion_tokens'] ?? null) ? $line['usage']['completion_tokens'] : 0;
            $model = \is_string($line['provider'] ?? null) && $line['provider'] !== '' ? $line['provider'] : '?';
            $actor = \is_string($line['actor'] ?? null) ? $line['actor'] : '?';

            ++$out['calls'];
            $out['errors'] += $ok ? 0 : 1;
            $out['tokensIn'] += $in;
            $out['tokensOut'] += $tokens;
            $out['users'][$actor] = ($out['users'][$actor] ?? 0) + 1;
            if (!$ok) {
                $out['reasons'][$reason !== '' ? $reason : 'error'] = ($out['reasons'][$reason !== '' ? $reason : 'error'] ?? 0) + 1;
            }
            foreach ([[&$byAction, $action], [&$byModel, $model]] as [&$group, $key]) {
                $group[$key] ??= ['calls' => 0, 'errors' => 0, 'tokensIn' => 0, 'tokensOut' => 0, 'times' => []];
                ++$group[$key]['calls'];
                $group[$key]['errors'] += $ok ? 0 : 1;
                $group[$key]['tokensIn'] += $in;
                $group[$key]['tokensOut'] += $tokens;
                if ($ok && $ms !== null) {
                    $group[$key]['times'][] = $ms;
                }
            }
            unset($group);
            if ($ok && $ms !== null) {
                $times[] = $ms;
            }
        }

        $out['p50'] = self::percentile($times, 50);
        $out['p90'] = self::percentile($times, 90);
        $finish = static function (array $group): array {
            $rows = [];
            foreach ($group as $key => $row) {
                $rows[(string) $key] = ['calls' => $row['calls'], 'errors' => $row['errors'], 'tokensIn' => $row['tokensIn'], 'tokensOut' => $row['tokensOut'],
                    'p50' => self::percentile($row['times'], 50), 'p90' => self::percentile($row['times'], 90)];
            }
            uasort($rows, static fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);

            return $rows;
        };
        $out['actions'] = $finish($byAction);
        $out['models'] = $finish($byModel);
        arsort($out['users']);
        arsort($out['reasons']);

        return $out;
    }

    /**
     * The ai.call / ai.refused lines from $since to $now, month file by month file
     *
     * @return iterable<array<string, mixed>>
     */
    private function lines(DateTimeImmutable $since, DateTimeImmutable $now): iterable
    {
        $month = $since->modify('first day of this month')->setTime(0, 0);
        $from = $since->format('Y-m-d\TH:i:sP');
        while ($month <= $now) {
            $file = $this->auditDir . '/' . $month->format('Y-m') . '.ndjson';
            $month = $month->modify('+1 month');
            $handle = is_file($file) ? @fopen($file, 'rb') : false;
            if ($handle === false) {
                continue;
            }
            while (($raw = fgets($handle)) !== false) {
                // Most lines are not the assistant's: skip them before decoding
                if (!str_contains($raw, '"ai.call"') && !str_contains($raw, '"ai.refused"')) {
                    continue;
                }
                $line = json_decode($raw, true);
                if (!\is_array($line) || !\in_array($line['action'] ?? null, ['ai.call', 'ai.refused'], true)) {
                    continue;
                }
                $ts = \is_string($line['ts'] ?? null) ? $line['ts'] : '';
                if (strtotime($ts) === false || strtotime($ts) < strtotime($from)) {
                    continue;
                }
                yield $line;
            }
            fclose($handle);
        }
    }

    /** @param list<int> $values nearest-rank percentile, null for none */
    private static function percentile(array $values, int $p): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);

        return $values[max(0, (int) ceil($p / 100 * \count($values)) - 1)];
    }
}
