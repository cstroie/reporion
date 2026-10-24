<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A line-level three-way merge (diff3), for the notes app's offline saves
 * (D55): `base` is the revision both sides started from, `ours` what the
 * server holds now, `theirs` what the device sends.
 *
 * Both sides are diffed against the base with Support\Diff (Myers). A base
 * line both sides kept is an anchor; between two anchors the chunk is
 * taken from the side that changed it, once when both changed it alike,
 * else it is a conflict, written with git's markers. Two edits touching
 * neighbouring lines (no anchor between them) are a conflict, as in git.
 * Past Diff's edit limit a side reads as the whole text replaced, so the
 * result is one conflict, never a crash.
 *
 * Line endings are folded to LF and the text ends with one newline, as
 * Storage writes a body.
 */
final class Merge3
{
    public const MARK_OURS = '<<<<<<< ';
    public const MARK_SPLIT = '=======';
    public const MARK_THEIRS = '>>>>>>> ';

    /**
     * @return array{text: string, conflicts: int}
     */
    public static function merge(string $base, string $ours, string $theirs, string $oursLabel = 'server', string $theirsLabel = 'this device'): array
    {
        $b = self::lines($base);
        $o = self::lines($ours);
        $t = self::lines($theirs);
        $toOurs = self::matches($b, $o);
        $toTheirs = self::matches($b, $t);

        $out = [];
        $conflicts = 0;
        [$pb, $po, $pt] = [0, 0, 0];
        $count = \count($b);
        for ($i = 0; $i <= $count; ++$i) {
            $anchor = $i === $count || ($toOurs[$i] !== null && $toTheirs[$i] !== null);
            if (!$anchor) {
                continue;
            }
            [$jo, $jt] = $i === $count ? [\count($o), \count($t)] : [$toOurs[$i], $toTheirs[$i]];
            $chunkB = \array_slice($b, $pb, $i - $pb);
            $chunkO = \array_slice($o, $po, $jo - $po);
            $chunkT = \array_slice($t, $pt, $jt - $pt);

            if ($chunkO === $chunkB || $chunkO === $chunkT) {
                array_push($out, ...$chunkT);
            } elseif ($chunkT === $chunkB) {
                array_push($out, ...$chunkO);
            } else {
                ++$conflicts;
                $out[] = self::MARK_OURS . $oursLabel;
                array_push($out, ...$chunkO);
                $out[] = self::MARK_SPLIT;
                array_push($out, ...$chunkT);
                $out[] = self::MARK_THEIRS . $theirsLabel;
            }

            if ($i < $count) {
                $out[] = $b[$i];
            }
            [$pb, $po, $pt] = [$i + 1, $jo + 1, $jt + 1];
        }

        return ['text' => $out === [] ? '' : implode("\n", $out) . "\n", 'conflicts' => $conflicts];
    }

    /** Whether a text still holds a conflict block (a line starting with the opening marker) */
    public static function hasMarkers(string $text): bool
    {
        return preg_match('/^' . preg_quote(self::MARK_OURS, '/') . '/m', $text) === 1;
    }

    /**
     * For each base line, its index on the other side when it was kept, else null.
     *
     * @param list<string> $base
     * @param list<string> $other
     *
     * @return list<?int>
     */
    private static function matches(array $base, array $other): array
    {
        $map = array_fill(0, \count($base), null);
        foreach (Diff::compare($base, $other) as [$op, $i, $j]) {
            if ($op === 'equal') {
                $map[$i] = $j;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $text = rtrim(str_replace(["\r\n", "\r"], "\n", $text), "\n");

        return $text === '' ? [] : explode("\n", $text);
    }
}
