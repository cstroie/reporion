<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Which revisions a person made (TODO 13, 2026-09-27): the dashboard's
 * "recently changed" and "my drafts" follow hand edits, not the thousands
 * of revisions an import or a maintenance run writes under the operator's
 * name. A revlog entry is machine-made when it says so (`"auto": true`,
 * StorageInterface::create/save's $auto) or: for history written before
 * the flag, when its note is one those commands always wrote.
 */
final class Revlog
{
    /** The notes bulk writers wrote before `auto` existed */
    private const MACHINE_NOTES = '/^(?:imported (?:from |template)|normalize report headings$|pages:structure migration$)/';

    /** @param array<string, mixed> $entry one revlog entry from meta.json */
    public static function isHandEdit(array $entry): bool
    {
        if (($entry['auto'] ?? false) === true) {
            return false;
        }

        return preg_match(self::MACHINE_NOTES, \is_string($entry['note'] ?? null) ? $entry['note'] : '') !== 1;
    }

    /**
     * The newest hand edit in a revlog: [ts, by], or null when every
     * revision was machine-made (an imported page nobody has touched).
     *
     * @param list<array<string, mixed>> $revlog
     *
     * @return ?array{0: string, 1: string}
     */
    public static function lastHandEdit(array $revlog): ?array
    {
        for ($i = \count($revlog) - 1; $i >= 0; --$i) {
            if (\is_array($revlog[$i] ?? null) && self::isHandEdit($revlog[$i])) {
                return [(string) ($revlog[$i]['ts'] ?? ''), (string) ($revlog[$i]['by'] ?? '')];
            }
        }

        return null;
    }

    /**
     * The hash chain (2026-10-17): each revlog entry written from now on
     * carries `chain`, sha256 over its predecessor's link and its own
     * n, ts, by and sha256. A link is the predecessor's `chain`, or, for an
     * entry written before the chain, its `sha256` (so the old history is
     * anchored at the point the chain starts); the first revision's
     * predecessor is the page's pid. Changing, removing or reordering a
     * revision from outside the app then breaks every link after it, which
     * `integrity:verify` reports as `chain_broken`. Tamper *evidence*, not
     * proof: whoever can rewrite meta.json can recompute it all.
     *
     * @param array<string, mixed>       $entry  the new entry, without `chain`
     * @param list<array<string, mixed>> $revlog the entries before it
     *
     * @return array<string, mixed> $entry with `chain` last
     */
    public static function chained(array $entry, array $revlog, string $pid): array
    {
        $entry['chain'] = self::link(self::previous($revlog, (int) $entry['n'], $pid), $entry);

        return $entry;
    }

    /**
     * Problems in a revlog's chain, as [n, outcome] pairs: `chain_broken`
     * (an entry's link does not follow from the one before) and
     * `chain_missing` (no link after the chain has started, or a link
     * on an entry that has none to follow: stripped).
     *
     * @param list<array<string, mixed>> $revlog
     *
     * @return list<array{0: int, 1: string}>
     */
    public static function chainProblems(array $revlog, string $pid): array
    {
        $problems = [];
        $started = false;
        foreach ($revlog as $i => $entry) {
            $n = (int) ($entry['n'] ?? 0);
            if (!\is_string($entry['chain'] ?? null)) {
                if ($started) {
                    $problems[] = [$n, 'chain_missing'];
                }

                continue;
            }
            $started = true;
            if (!hash_equals(self::link(self::previous(\array_slice($revlog, 0, $i), $n, $pid), $entry), $entry['chain'])) {
                $problems[] = [$n, 'chain_broken'];
            }
        }

        return $problems;
    }

    /** @param array<string, mixed> $entry */
    private static function link(string $previous, array $entry): string
    {
        return hash('sha256', implode("\n", [
            $previous,
            (int) ($entry['n'] ?? 0),
            (string) ($entry['ts'] ?? ''),
            (string) ($entry['by'] ?? ''),
            (string) ($entry['sha256'] ?? ''),
        ]));
    }

    /** @param list<array<string, mixed>> $revlog the entries before revision $n */
    private static function previous(array $revlog, int $n, string $pid): string
    {
        $last = $revlog === [] ? null : $revlog[array_key_last($revlog)];
        if ($last === null || (int) ($last['n'] ?? 0) !== $n - 1) {
            // The first revision hangs on the pid; a gap leaves nothing to follow
            return $n === 1 ? $pid : '';
        }

        return \is_string($last['chain'] ?? null) ? $last['chain'] : (string) ($last['sha256'] ?? '');
    }
}
