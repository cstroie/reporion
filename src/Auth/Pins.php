<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

use Reporion\Support\ReportPath;

/**
 * An account's pinned namespaces (quick navigation, 2026-10-01), stored as
 * `pins` in `data/users/{username}.json` (D36). A pin is a namespace only —
 * never a page — and never a segment shaped like a report name, so a
 * patient's path cannot end up in the list by construction (invariant 8).
 * Pinning grants nothing: the namespace index a pin links to is filtered
 * like every other listing (invariant 6).
 */
final class Pins
{
    public const MAX = 20;

    private const SEGMENT = '/^[a-z0-9](?:[a-z0-9_.-]{0,62}[a-z0-9])?$/';

    /** $raw as a pin (`reports:mri:`, ` reports:mri ` → `reports:mri`), or null when it may not be one */
    public static function normalize(string $raw): ?string
    {
        $ns = trim(trim($raw), ':');
        if ($ns === '' || \strlen($ns) > 200) {
            return null;
        }
        foreach (explode(':', $ns) as $segment) {
            if (preg_match(self::SEGMENT, $segment) !== 1 || ReportPath::looksLikeReportName($segment)) {
                return null;
            }
        }

        return $ns;
    }

    /**
     * A stored list made safe: each entry normalized, invalid ones and
     * repeats dropped, at most MAX kept — a hand-edited record never
     * refuses the account.
     *
     * @return list<string>
     */
    public static function clean(mixed $raw): array
    {
        $pins = [];
        foreach (\is_array($raw) ? $raw : [] as $entry) {
            $ns = \is_string($entry) ? self::normalize($entry) : null;
            if ($ns !== null && !\in_array($ns, $pins, true)) {
                $pins[] = $ns;
            }
        }

        return \array_slice($pins, 0, self::MAX);
    }
}
