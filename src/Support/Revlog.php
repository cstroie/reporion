<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Which revisions a person made (TODO 13, 2026-09-27): the dashboard's
 * "recently changed" and "my drafts" follow hand edits, not the thousands
 * of revisions an import or a maintenance run writes under the operator's
 * name. A revlog entry is machine-made when it says so (`"auto": true`,
 * StorageInterface::create/save's $auto) or — for history written before
 * the flag — when its note is one those commands always wrote.
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
}
