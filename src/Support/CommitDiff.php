<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The diff a revision note is written from (the `commit` prompt's {diff},
 * 2026-10-10), shaped for a very small model (300M–3B): a unified diff
 * with the noise taken out — no file headers, no line numbers, no context
 * lines — where each hunk's `@@` line carries the section it is in, the
 * way git puts a function name there, and a paragraph changed in a few
 * words is one line in git's `--word-diff=plain` form instead of a whole
 * paragraph removed and added again:
 *
 *     @@ ### Concluzie
 *      Leziune nodulară în lobul drept, [-12-]{+15+} mm.
 *     @@ ### Tehnică
 *     +Examinare efectuată cu contrast i.v.
 *
 * Null when there is nothing a small model can describe: no change, a
 * rewrite of most of the text (a template's first fill), or more than MAX
 * characters even after keeping only the first hunks.
 */
final class CommitDiff
{
    /** The most characters of diff sent: a tiny model's useful context, and a bounded request */
    public const MAX = 1500;

    /** Above this share of the words changed, the save is a rewrite: nothing to summarise as a change */
    private const REWRITE_SHARE = 0.6;

    /** Below this share of a paragraph's words kept, it shows as removed and added, not marked inline */
    private const INLINE_SHARE = 0.4;

    /** lines() is an LCS over lines, ungated: a body of thousands of lines is not a report */
    private const MAX_LINE_CELLS = 1_000_000;

    public static function build(string $from, string $to): ?string
    {
        $from = self::normalise($from);
        $to = self::normalise($to);
        if ($from === $to) {
            return null;
        }
        $a = explode("\n", $from);
        $b = explode("\n", $to);
        if ((\count($a) + 1) * (\count($b) + 1) > self::MAX_LINE_CELLS) {
            return null;
        }

        $hunks = [];
        $heading = '';
        $changed = 0;
        $removed = [];
        $added = [];
        $flush = function () use (&$hunks, &$heading, &$removed, &$added, &$changed): void {
            if ($removed === [] && $added === []) {
                return;
            }
            $lines = [];
            $pairs = min(\count($removed), \count($added));
            for ($i = 0; $i < $pairs; ++$i) {
                $inline = self::inline($removed[$i], $added[$i], $changed);
                array_push($lines, ...($inline !== null ? [' ' . $inline] : ['-' . $removed[$i], '+' . $added[$i]]));
                if ($inline === null) {
                    $changed += self::words($removed[$i]) + self::words($added[$i]);
                }
            }
            foreach (\array_slice($removed, $pairs) as $line) {
                $lines[] = '-' . $line;
                $changed += self::words($line);
            }
            foreach (\array_slice($added, $pairs) as $line) {
                $lines[] = '+' . $line;
                $changed += self::words($line);
            }
            $last = array_key_last($hunks);
            if ($last !== null && $hunks[$last]['heading'] === $heading) {
                array_push($hunks[$last]['lines'], ...$lines);
            } else {
                $hunks[] = ['heading' => $heading, 'lines' => $lines];
            }
            $removed = [];
            $added = [];
        };
        foreach (Diff::lines($from, $to) as $op) {
            if ($op['op'] === 'equal') {
                $flush();
                if (self::isHeading($op['line'])) {
                    $heading = trim($op['line']);
                }
                continue;
            }
            if (trim($op['line']) === '') {
                continue;
            }
            if ($op['op'] === 'remove') {
                $removed[] = $op['line'];
            } else {
                $added[] = $op['line'];
                // A heading added is the section what follows it belongs to
                if (self::isHeading($op['line'])) {
                    $flush();
                    $heading = trim($op['line']);
                }
            }
        }
        $flush();

        if ($hunks === []) {
            return null;
        }
        $total = max(self::words($from), self::words($to));
        if ($total > 0 && $changed / $total > self::REWRITE_SHARE) {
            return null;
        }

        $out = '';
        foreach ($hunks as $hunk) {
            $text = '@@' . ($hunk['heading'] !== '' ? ' ' . $hunk['heading'] : '') . "\n" . implode("\n", $hunk['lines']) . "\n";
            if (mb_strlen($out . $text) > self::MAX) {
                break;
            }
            $out .= $text;
        }

        return $out !== '' ? rtrim($out, "\n") : null;
    }

    /**
     * One changed paragraph in `[-old-]{+new+}` form, or null when it is
     * too big to diff word by word or mostly different; adds the words
     * changed to $changed when it is marked inline
     */
    private static function inline(string $old, string $new, int &$changed): ?string
    {
        if (!Diff::wordsFits($old, $new)) {
            return null;
        }
        $ops = Diff::words($old, $new);
        $kept = 0;
        $diff = 0;
        foreach ($ops as $op) {
            if ($op['op'] === 'equal') {
                $kept += self::words($op['line']);
            } else {
                $diff += self::words($op['line']);
            }
        }
        if ($kept === 0 || $kept / max(self::words($old), self::words($new)) < self::INLINE_SHARE) {
            return null;
        }
        $changed += $diff;
        $line = '';
        foreach ($ops as $op) {
            $line .= match ($op['op']) {
                'equal' => $op['line'],
                'remove' => '[-' . $op['line'] . '-]',
                'add' => '{+' . $op['line'] . '+}',
            };
        }

        return $line;
    }

    private static function normalise(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    private static function isHeading(string $line): bool
    {
        return preg_match('/^#{1,6}[ \t]+\S/u', $line) === 1;
    }

    private static function words(string $text): int
    {
        return \count(preg_split('/\s+/u', trim($text), -1, \PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
