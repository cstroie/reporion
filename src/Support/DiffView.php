<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * What the Revisions screen's line and split diffs draw (2026-10-21): the
 * line diff of Support\Diff grouped into unchanged runs and changes, each
 * removed line paired with the added line it became so the words that
 * changed inside it can be marked, long unchanged runs folded away with
 * the section (or function) they sit in, and the line numbers of both
 * sides. Pure data: the template draws it, nothing here knows HTML.
 *
 * A *chunk* is either
 *   ['eq' => list<array{a: int, b: int, text: string}>]
 * or
 *   ['del' => list<Line>, 'add' => list<Line>]
 * where a Line is {n: int, text: string, segs: ?list<array{0: 'eq'|'chg', 1: string}>};
 * `segs` is set only on a paired line that is mostly the same as its partner.
 */
final class DiffView
{
    /** Unchanged lines shown around a change */
    public const CONTEXT = 3;

    /** A run is folded only if at least this many lines would be hidden */
    private const MIN_FOLD = 4;

    /** A pair is marked inside only when this share of the (non-space) characters is shared */
    private const SIMILAR = 0.35;

    /** Longest line (in tokens) the inner diff is tried on */
    private const MAX_TOKENS = 600;

    /**
     * @return array{chunks: list<array<string, mixed>>, add: int, remove: int}
     */
    public static function chunks(string $from, string $to, bool $ignoreSpace = false): array
    {
        $chunks = [];
        $eq = [];
        $del = [];
        $add = [];
        $added = 0;
        $removed = 0;
        $flushChange = static function () use (&$chunks, &$del, &$add): void {
            if ($del === [] && $add === []) {
                return;
            }
            self::mark($del, $add);
            $chunks[] = ['del' => $del, 'add' => $add];
            $del = [];
            $add = [];
        };
        foreach (Diff::numbered($from, $to, $ignoreSpace) as $op) {
            if ($op['op'] === 'equal') {
                $flushChange();
                $eq[] = ['a' => (int) $op['a'], 'b' => (int) $op['b'], 'text' => $op['line']];
                continue;
            }
            if ($eq !== []) {
                $chunks[] = ['eq' => $eq];
                $eq = [];
            }
            if ($op['op'] === 'remove') {
                $del[] = ['n' => (int) $op['a'], 'text' => $op['line'], 'segs' => null];
                ++$removed;
            } else {
                $add[] = ['n' => (int) $op['b'], 'text' => $op['line'], 'segs' => null];
                ++$added;
            }
        }
        $flushChange();
        if ($eq !== []) {
            $chunks[] = ['eq' => $eq];
        }

        return ['chunks' => $chunks, 'add' => $added, 'remove' => $removed];
    }

    /**
     * One unified list of rows: {t: ctx|del|add|fold, a, b, text, segs} and
     * {t: fold, n, heading, rows: ctx rows hidden until opened}.
     *
     * @param list<array<string, mixed>> $chunks
     *
     * @return list<array<string, mixed>>
     */
    public static function unified(array $chunks, int $context = self::CONTEXT): array
    {
        $rows = [];
        $heading = '';
        $last = \count($chunks) - 1;
        foreach ($chunks as $i => $chunk) {
            if (isset($chunk['eq'])) {
                $lines = $chunk['eq'];
                $ctx = [];
                foreach ($lines as $line) {
                    $ctx[] = ['t' => 'ctx', 'a' => $line['a'], 'b' => $line['b'], 'text' => $line['text'], 'segs' => null];
                }
                $count = \count($ctx);
                $head = $i === 0 ? 0 : $context;
                $tail = $i === $last ? 0 : $context;
                if ($count - $head - $tail < self::MIN_FOLD) {
                    array_push($rows, ...$ctx);
                } else {
                    array_push($rows, ...\array_slice($ctx, 0, $head));
                    $hidden = \array_slice($ctx, $head, $count - $head - $tail);
                    // The section the folded lines (and the change after them) belong to
                    $near = $heading;
                    foreach ($lines as $line) {
                        $near = self::headingOf($line['text']) ?? $near;
                    }
                    $rows[] = ['t' => 'fold', 'n' => \count($hidden), 'heading' => $near, 'rows' => $hidden];
                    array_push($rows, ...\array_slice($ctx, $count - $tail));
                }
                foreach ($lines as $line) {
                    $heading = self::headingOf($line['text']) ?? $heading;
                }
                continue;
            }
            foreach ($chunk['del'] as $line) {
                $rows[] = ['t' => 'del', 'a' => $line['n'], 'b' => null, 'text' => $line['text'], 'segs' => $line['segs']];
            }
            foreach ($chunk['add'] as $line) {
                $rows[] = ['t' => 'add', 'a' => null, 'b' => $line['n'], 'text' => $line['text'], 'segs' => $line['segs']];
                $heading = self::headingOf($line['text']) ?? $heading;
            }
        }

        return $rows;
    }

    /**
     * Side by side: {l, r} rows, each cell {t: ctx|del|add, n, text, segs} or
     * null (nothing opposite); folds as {fold: true, n, heading, rows}.
     *
     * @param list<array<string, mixed>> $chunks
     *
     * @return list<array<string, mixed>>
     */
    public static function split(array $chunks, int $context = self::CONTEXT): array
    {
        $out = [];
        $queue = self::unified($chunks, $context);
        $i = 0;
        $n = \count($queue);
        while ($i < $n) {
            $row = $queue[$i];
            if ($row['t'] === 'fold') {
                $hidden = [];
                foreach ($row['rows'] as $ctx) {
                    $hidden[] = ['l' => self::cell('ctx', $ctx['a'], $ctx['text']), 'r' => self::cell('ctx', $ctx['b'], $ctx['text'])];
                }
                $out[] = ['fold' => true, 'n' => $row['n'], 'heading' => $row['heading'], 'rows' => $hidden];
                ++$i;
            } elseif ($row['t'] === 'ctx') {
                $out[] = ['l' => self::cell('ctx', $row['a'], $row['text']), 'r' => self::cell('ctx', $row['b'], $row['text'])];
                ++$i;
            } else {
                $dels = [];
                $adds = [];
                while ($i < $n && $queue[$i]['t'] === 'del') {
                    $dels[] = $queue[$i++];
                }
                while ($i < $n && $queue[$i]['t'] === 'add') {
                    $adds[] = $queue[$i++];
                }
                for ($k = 0, $max = max(\count($dels), \count($adds)); $k < $max; ++$k) {
                    $l = $dels[$k] ?? null;
                    $r = $adds[$k] ?? null;
                    $out[] = [
                        'l' => $l !== null ? self::cell('del', $l['a'], $l['text'], $l['segs']) : null,
                        'r' => $r !== null ? self::cell('add', $r['b'], $r['text'], $r['segs']) : null,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * The same change in two columns' worth of words: `[kind, text]` pairs for
     * the old line and for the new one, or null when the lines are not alike.
     *
     * @return ?array{0: list<array{0: 'eq'|'chg', 1: string}>, 1: list<array{0: 'eq'|'chg', 1: string}>}
     */
    public static function inner(string $old, string $new): ?array
    {
        $a = Diff::fineTokens($old);
        $b = Diff::fineTokens($new);
        if (\count($a) > self::MAX_TOKENS || \count($b) > self::MAX_TOKENS || $old === '' || $new === '') {
            return null;
        }
        $left = [];
        $right = [];
        $shared = 0;
        $dropped = 0;
        $gained = 0;
        foreach (Diff::compare($a, $b) as [$op, $i, $j]) {
            if ($op === 'equal') {
                $left[] = ['eq', $a[$i]];
                $right[] = ['eq', $b[$j]];
                if (trim($a[$i]) !== '') {
                    $shared += mb_strlen($a[$i]);
                }
            } elseif ($op === 'remove') {
                $left[] = ['chg', $a[$i]];
                ++$dropped;
            } else {
                $right[] = ['chg', $b[$j]];
                ++$gained;
            }
        }
        $size = max(mb_strlen((string) preg_replace('/\s+/u', '', $old)), mb_strlen((string) preg_replace('/\s+/u', '', $new)));
        // Words only added to a line, or only taken out of it, are always the same line edited
        if ($size === 0 || ($shared / $size < self::SIMILAR && $dropped > 0 && $gained > 0)) {
            return null;
        }

        return [self::merge($left), self::merge($right)];
    }

    /**
     * Pair the i-th removed line with the i-th added one and mark the words
     * that changed inside each.
     *
     * @param list<array{n: int, text: string, segs: mixed}> $del
     * @param list<array{n: int, text: string, segs: mixed}> $add
     */
    private static function mark(array &$del, array &$add): void
    {
        for ($i = 0, $pairs = min(\count($del), \count($add)); $i < $pairs; ++$i) {
            $inner = self::inner($del[$i]['text'], $add[$i]['text']);
            if ($inner !== null) {
                $del[$i]['segs'] = $inner[0];
                $add[$i]['segs'] = $inner[1];
            }
        }
    }

    /**
     * @param list<array{0: 'eq'|'chg', 1: string}> $segs
     *
     * @return list<array{0: 'eq'|'chg', 1: string}>
     */
    private static function merge(array $segs): array
    {
        $out = [];
        foreach ($segs as $seg) {
            $last = array_key_last($out);
            if ($last !== null && $out[$last][0] === $seg[0]) {
                $out[$last][1] .= $seg[1];
            } else {
                $out[] = $seg;
            }
        }

        return $out;
    }

    /**
     * @param list<array{0: 'eq'|'chg', 1: string}>|null $segs
     *
     * @return array{t: string, n: int, text: string, segs: ?array}
     */
    private static function cell(string $t, int $n, string $text, ?array $segs = null): array
    {
        return ['t' => $t, 'n' => $n, 'text' => $text, 'segs' => $segs];
    }

    /** A markdown heading, or the line a function / class / method starts on: what a fold is "in" */
    private static function headingOf(string $line): ?string
    {
        if (preg_match('/^#{1,6}[ \t]+\S/u', $line) === 1) {
            return trim($line);
        }
        if (preg_match('/^\s{0,8}(?:(?:public|private|protected|static|final|abstract|async|export)\s+)*(?:function|class|interface|trait|def|fn|func|struct|enum)\s+\S[^{;]*/u', $line, $m) === 1) {
            return trim($m[0]);
        }

        return null;
    }
}
