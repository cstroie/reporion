<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use Collator;
use Reporion\Auth\User;
use Reporion\Index\IndexInterface;

/**
 * How a namespace lists its pages: the `sort` key of its description page's
 * frontmatter (docs/FORMATS.md §3). `title` is the page title (the title
 * column of the index, else the page name), `name` the last path segment,
 * `updated` the newest edit first. Unset or unknown means the listing's own
 * order. Text compares as Romanian, numbers by value (`Cap 2` before
 * `Cap 10`), without regard to case, so `Șold` sits among the S's.
 */
final class NamespaceSort
{
    public const MODES = ['title', 'name', 'updated'];

    /** The mode a `meta_json` (or a frontmatter array) names, null when none or an unknown one */
    public static function modeOf(string|array|null $meta): ?string
    {
        if (\is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        $mode = \is_array($meta) && \is_string($meta['sort'] ?? null) ? trim($meta['sort']) : '';

        return \in_array($mode, self::MODES, true) ? $mode : null;
    }

    /**
     * The mode $ns names on its description page (the page named like it, else `{ns}:_index`),
     * read through the page access rule: a description the caller cannot see leaves the default order
     */
    public static function modeFor(IndexInterface $index, string $ns, ?User $principal): ?string
    {
        if ($ns === '') {
            return null;
        }
        $rows = $index->findByPaths([$ns, $ns . ':_index'], $principal);
        $row = $rows[$ns] ?? $rows[$ns . ':_index'] ?? null;

        return $row !== null ? self::modeOf((string) ($row['meta_json'] ?? '')) : null;
    }

    /**
     * $rows (each with `path`, `title`, `updated`) in the order $mode says; as given when null
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public static function sort(array $rows, ?string $mode): array
    {
        if ($mode === null) {
            return $rows;
        }
        $collator = new Collator('ro');
        $collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
        $name = static fn (array $row): string => ReportPath::leaf((string) $row['path']);
        $title = static fn (array $row): string => trim((string) ($row['title'] ?? '')) !== '' ? trim((string) $row['title']) : $name($row);

        usort($rows, static function (array $a, array $b) use ($mode, $collator, $name, $title): int {
            if ($mode === 'updated') {
                return strcmp((string) ($b['updated'] ?? ''), (string) ($a['updated'] ?? '')) ?: $collator->compare($name($a), $name($b));
            }
            $first = $mode === 'title' ? $collator->compare($title($a), $title($b)) : 0;

            return $first ?: $collator->compare($name($a), $name($b));
        });

        return $rows;
    }
}
