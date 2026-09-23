<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Search;

/**
 * D6: visibility replaces ACL entirely — the whole access model is one
 * predicate, applied in one place, never after the fact (CLAUDE.md
 * invariant 6). There are two variants because a *listing* (search results,
 * the namespace tree, the sitemap) and a *direct* lookup of one known path
 * follow different rules (docs/architecture-storage-index.md Table 2): an
 * anonymous caller's listing shows public pages only — an unlisted page is
 * reachable, but never listed. Direct access to a known path additionally
 * allows unlisted; only private is refused, and CLAUDE.md invariant 9
 * requires that refusal to look like "this does not exist" (404), never
 * "you may not see this" (403) — pageAccessClause() achieves that by making
 * a private page simply not match the query, the same as a nonexistent one.
 */
final class Query
{
    public static function visibilityClause(bool $isOwner, string $column = 'visibility'): string
    {
        return $isOwner ? '' : " AND {$column} = 'public'";
    }

    public static function pageAccessClause(bool $isOwner, string $column = 'visibility'): string
    {
        return $isOwner ? '' : " AND {$column} IN ('public', 'unlisted')";
    }
}
