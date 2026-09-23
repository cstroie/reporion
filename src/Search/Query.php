<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Search;

use Reporion\Auth\User;

/**
 * D35–D37 (superseding D6): the whole access model is still one predicate,
 * applied in one place, never after the fact (CLAUDE.md invariant 6) — but
 * it now takes the caller's principal, not just an owner boolean. A grant
 * covering a row's namespace bypasses the visibility filter **entirely**:
 * a grant-holder sees `private` pages inside their own namespace, the same
 * as the owner does. That is the whole point of a namespace grant being
 * "ordinary staff access, not a token" (docs/architecture-storage-index.md)
 * — do not "fix" the grant branch to also check visibility, that would
 * make an editor unable to read their own private drafts.
 *
 * There are still two variants because a *listing* (search, tree, sitemap)
 * and a *direct* lookup of one known path follow different rules for
 * everyone else (docs/architecture-storage-index.md Table 2): with no
 * covering grant, a listing shows public pages only — an unlisted page is
 * reachable, but never listed — while direct access to a known path
 * additionally allows unlisted; only private is refused, and CLAUDE.md
 * invariant 9 requires that refusal to look like "this does not exist"
 * (404), never "you may not see this" (403) — a private page with no
 * covering grant simply does not match the query, the same as one that
 * does not exist.
 */
final class Query
{
    /**
     * @return array{0: string, 1: array<string, string>} SQL fragment (may
     *                                                      be empty) and its bound parameters
     */
    public static function visibilityClause(?User $principal, string $visibilityColumn = 'visibility', string $nsColumn = 'ns'): array
    {
        return self::clause($principal, $visibilityColumn, $nsColumn, "('public')");
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    public static function pageAccessClause(?User $principal, string $visibilityColumn = 'visibility', string $nsColumn = 'ns'): array
    {
        return self::clause($principal, $visibilityColumn, $nsColumn, "('public', 'unlisted')");
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private static function clause(?User $principal, string $visibilityColumn, string $nsColumn, string $baseVisibilitySet): array
    {
        if ($principal?->isOwner === true) {
            return ['', []];
        }

        $sql = " AND ({$visibilityColumn} IN {$baseVisibilitySet}";
        $params = [];

        foreach ($principal?->grants ?? [] as $i => $grant) {
            $exactParam = "grant_ns_{$i}";
            $prefixParam = "grant_ns_prefix_{$i}";
            $sql .= " OR {$nsColumn} = :{$exactParam} OR {$nsColumn} LIKE :{$prefixParam} ESCAPE '\\'";
            $params[$exactParam] = $grant->namespace;
            // A namespace is validated (Auth\Grant) to contain no "/", but
            // "%" and "_" are both valid namespace characters and both are
            // LIKE wildcards — unescaped, a grant on "reports_mri" would
            // also match the unrelated namespace "reportsXmri" via "_".
            $params[$prefixParam] = self::likeEscape($grant->namespace) . ':%';
        }

        $sql .= ')';

        return [$sql, $params];
    }

    /**
     * Public: `Index\Sqlite::listSubnamespaces()` needs the same escaping
     * for its own `ns LIKE` prefix match — a namespace segment can contain
     * "%"/"_", both SQL LIKE wildcards, same reasoning as the grant
     * matching above.
     */
    public static function likeEscape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
