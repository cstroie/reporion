<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Line-based unified diff, computed server-side (docs/architecture-api.md
 * Table 1: "/{path}/revisions — revision list + unified diff, both computed
 * server-side"). Hand-rolled classic LCS, not a Composer dependency: the
 * repo layout already names this class, and reports are short prose
 * documents (~2.4 kB, D2) — O(lines(from) × lines(to)) is nowhere near a
 * real cost at that size, so there is no reason to reach for anything more
 * elaborate (Myers, patience diff) than the textbook algorithm.
 *
 * That assumption held for lines() (a report has dozens of lines) but not
 * for words() (2026-09-28): its LCS table is one PHP array per row, and a
 * ~12 kB body — not an outlier, well inside "short prose" — already
 * tokenizes to ~3 000 words+spaces, a ~9.25M-cell table that exhausted a
 * 128M PHP-FPM worker in production (2026-09-30 incident). wordsFits()
 * gates it; CompareController falls back to the side-by-side raw render
 * above the cap, the same fallback an unparseable revision already gets.
 * The real fix is a linear-space LCS (Hirschberg's algorithm) so nothing
 * needs gating at all — not done here under incident pressure; this stops
 * the crash correctly in the meantime and is worth keeping regardless.
 */
final class Diff
{
    /**
     * words()'s LCS table is (tokens(a)+1) × (tokens(b)+1) *nested* PHP
     * arrays — real overhead per cell, not just an int's worth of bytes
     * (see class docblock). 1,000,000 is a wide safety margin under the
     * ~9.25M cells that exhausted a 128M worker: room for a report with
     * up to roughly 500 words on each side, comfortably above D2's "~2.4 kB"
     * typical size, before falling back to the side-by-side render.
     */
    private const WORD_DIFF_SAFE_CELLS = 1_000_000;

    /**
     * Whether words($from, $to) is safe to call — checked by
     * CompareController before it does, never inside words() itself (its
     * return type and existing callers/tests expect an array, always).
     */
    public static function wordsFits(string $from, string $to): bool
    {
        $a = \count(self::tokenize($from));
        $b = \count(self::tokenize($to));

        return ($a + 1) * ($b + 1) <= self::WORD_DIFF_SAFE_CELLS;
    }

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
     * A word-level diff (TODO 13: the Compare screen, "compare old to new
     * word-by-word"), for the doctor comparing two prose revisions rather
     * than reading a line-oriented patch. Tokens are words *and* the
     * whitespace runs between them (both kept as ops), so re-joining every
     * op's text reproduces the original exactly — same LCS as lines(), a
     * finer token.
     *
     * @return list<array{op: 'equal'|'add'|'remove', line: string}>
     */
    public static function words(string $from, string $to): array
    {
        $a = self::tokenize($from);
        $b = self::tokenize($to);

        $lengths = self::lcsLengths($a, $b);
        $ops = self::backtrack($a, $b, $lengths, \count($a), \count($b));

        return self::coalesce($ops);
    }

    /**
     * @return list<string>
     */
    private static function tokenize(string $text): array
    {
        $tokens = preg_split('/(\s+)/u', $text, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [$text] : $tokens;
    }

    /**
     * Adjacent ops of the same kind merged into one — a run of several
     * changed words becomes a single <ins>/<del>, not one tag per token.
     *
     * @param list<array{op: 'equal'|'add'|'remove', line: string}> $ops
     *
     * @return list<array{op: 'equal'|'add'|'remove', line: string}>
     */
    private static function coalesce(array $ops): array
    {
        $result = [];
        foreach ($ops as $op) {
            $last = array_key_last($result);
            if ($last !== null && $result[$last]['op'] === $op['op']) {
                $result[$last]['line'] .= $op['line'];
            } else {
                $result[] = $op;
            }
        }

        return $result;
    }

    /**
     * Counts only — how many lines were added/removed, for a revision
     * list's summary column, without building the full line-by-line diff
     * the revisions page's diff panel needs.
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
