<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

use Reporion\Support\ReportPath;

/**
 * What an account can reach and why (D46, D47), one row per grant: its own, each group's, the
 * implicit one on its own namespace, and, for an account that is not a radiologist, the report
 * grants it holds but that do not count. Read-only, for Admin → Users and /profile; the access
 * checks themselves read User::effectiveGrants().
 */
final class AccessSummary
{
    /**
     * @param array<string, Group> $groups every group, by name
     *
     * @return list<array{namespace: string, role: string, source: string, dropped: bool}>
     *         source: `own`, `@{group}`, or `personal`
     */
    public static function rows(User $user, array $groups): array
    {
        if ($user->isOwner) {
            return [];
        }

        $rows = [];
        $add = static function (Grant $grant, string $source) use ($user, &$rows): void {
            $rows[] = [
                'namespace' => $grant->namespace,
                'role' => $grant->role->value,
                'source' => $source,
                'dropped' => !$user->isRadiologist() && ReportPath::isReportNamespace($grant->namespace),
            ];
        };
        foreach ($user->grants as $grant) {
            $add($grant, 'own');
        }
        foreach ($user->groups as $name) {
            foreach (($groups[$name] ?? null)?->grants ?? [] as $grant) {
                $add($grant, '@' . $name);
            }
        }
        $own = $user->personalNamespace();
        if ($own !== null) {
            $add(new Grant($own, GrantRole::Editor), 'personal');
        }

        return $rows;
    }

    /**
     * Grant lines as typed in a textarea (`ns:role`, one per line) → grants; throws on the first bad one
     *
     * @return list<Grant>
     */
    public static function parseLines(string $text): array
    {
        $grants = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $grants[] = GrantParser::parse($line);
            }
        }

        return $grants;
    }

    /**
     * Grants as textarea lines, the inverse of parseLines()
     *
     * @param list<Grant> $grants
     */
    public static function lines(array $grants): string
    {
        return implode("\n", array_map(static fn (Grant $g): string => $g->namespace . ':' . $g->role->value, $grants));
    }

    /**
     * @param list<Group> $groups
     *
     * @return array<string, Group>
     */
    public static function byName(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            $out[$g->name] = $g;
        }

        return $out;
    }
}
