<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Line and word diffs, computed server-side (docs/architecture-api.md
 * Table 1: "/{path}/revisions: revision list + unified diff, both computed
 * server-side").
 *
 * Myers' O(ND) algorithm after the common prefix and suffix are trimmed
 * (2026-10-21; was the textbook LCS table, whose O(n x m) memory exhausted a
 * 128M PHP-FPM worker on a ~12 kB body in the 2026-09-30 incident, hence
 * wordsFits() and the line-diff fallback). Memory now follows the number of
 * *edits*, not the text's size; past MAX_EDITS the unchanged-looking middle
 * is reported as removed and added, never a crash.
 */
final class Diff
{
    /** The most edit distance Myers' trace is kept for: ~D^2 integers, a few tens of MB at most */
    private const MAX_EDITS = 1500;

    /** Tokens per side past which words() is not asked for: tokenising alone would be the cost */
    private const WORD_DIFF_MAX_TOKENS = 100_000;

    /**
     * Whether words($from, $to) is worth calling: checked by the Revisions
     * screen before it does. Only an absurdly large body is refused now.
     */
    public static function wordsFits(string $from, string $to): bool
    {
        return \strlen($from) <= 4_000_000 && \strlen($to) <= 4_000_000
            && \count(self::tokenize($from)) <= self::WORD_DIFF_MAX_TOKENS
            && \count(self::tokenize($to)) <= self::WORD_DIFF_MAX_TOKENS;
    }

    /**
     * @return list<array{op: 'equal'|'add'|'remove', line: string}>
     */
    public static function lines(string $from, string $to): array
    {
        $out = [];
        foreach (self::numbered($from, $to) as $op) {
            $out[] = ['op' => $op['op'], 'line' => $op['line']];
        }

        return $out;
    }

    /**
     * The line diff with each line's 1-based number in the old and new text
     * (null on the side it is not on), for the gutters of the Revisions
     * screen. $ignoreSpace compares lines with their runs of whitespace
     * collapsed and the ends trimmed; an unchanged line then shows the new
     * text.
     *
     * @return list<array{op: 'equal'|'add'|'remove', line: string, a: ?int, b: ?int}>
     */
    public static function numbered(string $from, string $to, bool $ignoreSpace = false): array
    {
        $a = explode("\n", $from);
        $b = explode("\n", $to);
        $ka = $ignoreSpace ? array_map(self::squash(...), $a) : $a;
        $kb = $ignoreSpace ? array_map(self::squash(...), $b) : $b;

        $out = [];
        foreach (self::compare($ka, $kb) as [$op, $i, $j]) {
            $out[] = match ($op) {
                'equal' => ['op' => 'equal', 'line' => $b[$j], 'a' => $i + 1, 'b' => $j + 1],
                'add' => ['op' => 'add', 'line' => $b[$j], 'a' => null, 'b' => $j + 1],
                'remove' => ['op' => 'remove', 'line' => $a[$i], 'a' => $i + 1, 'b' => null],
            };
        }

        return $out;
    }

    /**
     * A word-level diff (TODO 13: the Compare screen, "compare old to new
     * word-by-word"), for the doctor comparing two prose revisions rather
     * than reading a line-oriented patch. Tokens are words *and* the
     * whitespace runs between them (both kept as ops), so re-joining every
     * op's text reproduces the new text exactly (the old text with ignore
     * off, when joined without the adds): same algorithm as lines(), a
     * finer token. $ignoreSpace treats every whitespace run as equal to any
     * other.
     *
     * @return list<array{op: 'equal'|'add'|'remove', line: string}>
     */
    public static function words(string $from, string $to, bool $ignoreSpace = false): array
    {
        $a = self::tokenize($from);
        $b = self::tokenize($to);
        $ka = $ignoreSpace ? array_map(self::squash(...), $a) : $a;
        $kb = $ignoreSpace ? array_map(self::squash(...), $b) : $b;

        $ops = [];
        foreach (self::compare($ka, $kb) as [$op, $i, $j]) {
            $ops[] = match ($op) {
                'equal' => ['op' => 'equal', 'line' => $b[$j]],
                'add' => ['op' => 'add', 'line' => $b[$j]],
                'remove' => ['op' => 'remove', 'line' => $a[$i]],
            };
        }

        return self::coalesce($ops);
    }

    /**
     * Tokens for a diff *inside* a line (code or prose): a run of word
     * characters, a run of whitespace, or one punctuation mark, so
     * `foo(bar)` -> `foo` `(` `bar` `)`. Used by Support\DiffView.
     *
     * @return list<string>
     */
    public static function fineTokens(string $text): array
    {
        preg_match_all('/\w+|\s+|[^\w\s]/u', $text, $m);

        return $m[0] === [] && $text !== '' ? [$text] : $m[0];
    }

    /**
     * Counts only: how many lines were added/removed, for a revision
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
     * Fine-grained edit script over two token lists, as [op, i, j]: i the
     * index in $a (remove, equal), j the index in $b (add, equal). Within
     * a run of changes the removals come first, then the additions.
     *
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{0: 'equal'|'add'|'remove', 1: int, 2: int}>
     */
    public static function compare(array $a, array $b): array
    {
        $n = \count($a);
        $m = \count($b);
        $prefix = 0;
        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            ++$prefix;
        }
        $suffix = 0;
        while ($suffix < $n - $prefix && $suffix < $m - $prefix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            ++$suffix;
        }

        $script = [];
        for ($i = 0; $i < $prefix; ++$i) {
            $script[] = ['equal', $i, $i];
        }
        $middle = self::myers(
            \array_slice($a, $prefix, $n - $prefix - $suffix),
            \array_slice($b, $prefix, $m - $prefix - $suffix),
        );
        foreach ($middle as [$op, $i, $j]) {
            $script[] = [$op, $i + $prefix, $j + $prefix];
        }
        for ($k = $suffix; $k > 0; --$k) {
            $script[] = ['equal', $n - $k, $m - $k];
        }

        return self::removalsFirst($script);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{0: 'equal'|'add'|'remove', 1: int, 2: int}>
     */
    private static function myers(array $a, array $b): array
    {
        $n = \count($a);
        $m = \count($b);
        if ($n === 0 || $m === 0) {
            $script = [];
            for ($i = 0; $i < $n; ++$i) {
                $script[] = ['remove', $i, 0];
            }
            for ($j = 0; $j < $m; ++$j) {
                $script[] = ['add', 0, $j];
            }

            return $script;
        }

        $v = [1 => 0];
        $trace = [];
        $limit = min($n + $m, self::MAX_EDITS);
        $found = false;
        for ($d = 0; $d <= $limit && !$found; ++$d) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && $v[$k - 1] < $v[$k + 1])) {
                    $x = $v[$k + 1];
                } else {
                    $x = $v[$k - 1] + 1;
                }
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    ++$x;
                    ++$y;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            // Past the edit limit: the middle is all removed, then all added
            $script = [];
            for ($i = 0; $i < $n; ++$i) {
                $script[] = ['remove', $i, 0];
            }
            for ($j = 0; $j < $m; ++$j) {
                $script[] = ['add', 0, $j];
            }

            return $script;
        }

        $script = [];
        $x = $n;
        $y = $m;
        for ($d = \count($trace) - 1; $d >= 0; --$d) {
            $vv = $trace[$d];
            $k = $x - $y;
            if ($k === -$d || ($k !== $d && $vv[$k - 1] < $vv[$k + 1])) {
                $prevK = $k + 1;
            } else {
                $prevK = $k - 1;
            }
            $prevX = $vv[$prevK];
            $prevY = $prevX - $prevK;
            while ($x > $prevX && $y > $prevY) {
                $script[] = ['equal', $x - 1, $y - 1];
                --$x;
                --$y;
            }
            if ($d > 0) {
                if ($x === $prevX) {
                    $script[] = ['add', 0, $y - 1];
                    --$y;
                } else {
                    $script[] = ['remove', $x - 1, 0];
                    --$x;
                }
            }
        }

        return array_reverse($script);
    }

    /**
     * Each run of non-equal steps reordered: removals, then additions
     * (Myers may interleave them).
     *
     * @param list<array{0: 'equal'|'add'|'remove', 1: int, 2: int}> $script
     *
     * @return list<array{0: 'equal'|'add'|'remove', 1: int, 2: int}>
     */
    private static function removalsFirst(array $script): array
    {
        $out = [];
        $removed = [];
        $added = [];
        foreach ($script as $step) {
            if ($step[0] === 'equal') {
                array_push($out, ...$removed, ...$added);
                $removed = $added = [];
                $out[] = $step;
            } elseif ($step[0] === 'remove') {
                $removed[] = $step;
            } else {
                $added[] = $step;
            }
        }
        array_push($out, ...$removed, ...$added);

        return $out;
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
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
     * Adjacent ops of the same kind merged into one: a run of several
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
}
