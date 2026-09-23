<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Line-based unified diff, computed server-side (docs/architecture-api.md
 * Table 1: "/{path}/history — revision list + unified diff, both computed
 * server-side"). Hand-rolled classic LCS, not a Composer dependency: the
 * repo layout already names this class, and reports are short prose
 * documents (~2.4 kB, D2) — O(lines(from) × lines(to)) is nowhere near a
 * real cost at that size, so there is no reason to reach for anything more
 * elaborate (Myers, patience diff) than the textbook algorithm.
 */
final class Diff
{
    /**
     * @return list<array{op: 'equal'|'add'|'remove', line: string}>
     */
    public static function lines(string $from, string $to): array
    {
        $a = explode("\n", $from);
        $b = explode("\n", $to);

        $lengths = self::lcsLengths($a, $b);

        return self::backtrack($a, $b, $lengths, \count($a), \count($b));
    }

    /**
     * Counts only — how many lines were added/removed, for a revision
     * list's summary column, without building the full line-by-line diff
     * the history page's diff panel needs.
     *
     * @return array{add: int, remove: int}
     */
    public static function counts(string $from, string $to): array
    {
        $add = 0;
        $remove = 0;
        foreach (self::lines($from, $to) as $line) {
            match ($line['op']) {
                'add' => $add++,
                'remove' => $remove++,
                'equal' => null,
            };
        }

        return ['add' => $add, 'remove' => $remove];
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<list<int>> LCS length table, (count($a)+1) x (count($b)+1)
     */
    private static function lcsLengths(array $a, array $b): array
    {
        $m = \count($a);
        $n = \count($b);
        $table = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $table[$i][$j] = $a[$i - 1] === $b[$j - 1]
                    ? $table[$i - 1][$j - 1] + 1
                    : max($table[$i - 1][$j], $table[$i][$j - 1]);
            }
        }

        return $table;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @param list<list<int>> $table
     *
     * @return list<array{op: 'equal'|'add'|'remove', line: string}>
     */
    private static function backtrack(array $a, array $b, array $table, int $i, int $j): array
    {
        $result = [];
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $a[$i - 1] === $b[$j - 1]) {
                $result[] = ['op' => 'equal', 'line' => $a[$i - 1]];
                --$i;
                --$j;
            } elseif ($j > 0 && ($i === 0 || $table[$i][$j - 1] >= $table[$i - 1][$j])) {
                $result[] = ['op' => 'add', 'line' => $b[$j - 1]];
                --$j;
            } else {
                $result[] = ['op' => 'remove', 'line' => $a[$i - 1]];
                --$i;
            }
        }

        return array_reverse($result);
    }
}
