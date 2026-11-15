<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

/**
 * An account's marks on the library's sources (owner, 2026-11-15; amends D36 by one key and
 * reverses 2026-10-30's "no read marks"): where its reading stands (`toread`, `reading`, `read`)
 * and a star, stored as `marks` in `data/users/{username}.json`, keyed by the source's pid (a key
 * may change; a pid never does):
 *
 *     "marks": {"01J…": {"status": "toread"}, "01K…": {"status": "read", "star": true}}
 *
 * A mark is the account's own: no one else sees it, it is no part of the source and grants
 * nothing: the list it filters still runs the visibility predicate (invariant 6), so a mark on a
 * source the account can no longer read simply shows nowhere. Pure functions.
 */
final class LibraryMarks
{
    public const STATUSES = ['toread', 'reading', 'read'];

    /** Enough for any library; the oldest marks go first beyond it */
    public const MAX = 5000;

    /**
     * A stored map made safe: pids only, a known status or none, a star or none, an empty mark dropped
     *
     * @return array<string, array{status?: string, star?: true}>
     */
    public static function clean(mixed $raw): array
    {
        $marks = [];
        foreach (\is_array($raw) ? $raw : [] as $pid => $mark) {
            if (!\is_string($pid) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $pid) !== 1 || !\is_array($mark)) {
                continue;
            }
            $kept = [];
            if (\in_array($mark['status'] ?? null, self::STATUSES, true)) {
                $kept['status'] = (string) $mark['status'];
            }
            if (($mark['star'] ?? false) === true) {
                $kept['star'] = true;
            }
            if ($kept !== []) {
                $marks[$pid] = $kept;
            }
        }

        return \count($marks) > self::MAX ? \array_slice($marks, -self::MAX, null, true) : $marks;
    }

    /**
     * $marks with $pid's mark set: a status ('' for none) and/or the star, each left as it is when null
     *
     * @param array<string, array{status?: string, star?: true}> $marks
     *
     * @return array<string, array{status?: string, star?: true}>
     */
    public static function set(array $marks, string $pid, ?string $status, ?bool $star): array
    {
        $mark = $marks[$pid] ?? [];
        if ($status !== null) {
            unset($mark['status']);
            if (\in_array($status, self::STATUSES, true)) {
                $mark['status'] = $status;
            }
        }
        if ($star !== null) {
            unset($mark['star']);
            if ($star) {
                $mark['star'] = true;
            }
        }
        unset($marks[$pid]);
        if ($mark !== []) {
            // Set again: the newest last, so the cap drops the marks set longest ago
            $marks[$pid] = $mark;
        }

        return self::clean($marks);
    }
}
