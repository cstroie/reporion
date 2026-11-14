<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Head pages (D61): the page `a:b` owns the namespace `a:b:` beside it. It is
 * the namespace's title, description, `sort`, starting visibility and tags,
 * and its frontmatter `children` says how its pages show under its body:
 * `list` (the default), `chapters`, `toc` or `none`. The older description
 * page `{ns}:_index` is read only as a fallback, until the namespace's
 * description is next edited. Report namespaces (`reports`, `reports:*`)
 * are left out: patient pages are never a head's chapters.
 */
final class HeadPage
{
    public const MODES = ['list', 'chapters', 'toc', 'none'];
    public const DEFAULT_MODE = 'list';
    public const LEGACY_LEAF = '_index';

    /** The head page of $ns (the page of its name); null for the root, which has none */
    public static function headOf(string $ns): ?string
    {
        $ns = trim($ns, ':');

        return $ns === '' ? null : $ns;
    }

    /** The namespace $head owns */
    public static function namespaceOf(string $head): string
    {
        return trim($head, ':');
    }

    /**
     * The pages that may describe $ns, the head first, then the legacy
     * `{ns}:_index` (`_index` at the root, which has no head)
     *
     * @return list<string>
     */
    public static function descriptionPaths(string $ns): array
    {
        $head = self::headOf($ns);

        return $head === null ? [self::LEGACY_LEAF] : [$head, $head . ':' . self::LEGACY_LEAF];
    }

    /** Whether $path is a legacy `_index` description page */
    public static function isLegacy(string $path): bool
    {
        return $path === self::LEGACY_LEAF || str_ends_with($path, ':' . self::LEGACY_LEAF);
    }

    /** Whether head-page rules apply to $ns (or to a head page's path): not in the reports tree */
    public static function applies(string $ns): bool
    {
        $ns = trim($ns, ':');

        return $ns !== '' && !ReportPath::isReportNamespace($ns);
    }

    /** The `children` mode a frontmatter (or `meta_json`) names; the default when unset or unknown */
    public static function childrenMode(string|array|null $meta): string
    {
        if (\is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        $mode = \is_array($meta) && \is_string($meta['children'] ?? null) ? trim($meta['children']) : '';

        return \in_array($mode, self::MODES, true) ? $mode : self::DEFAULT_MODE;
    }
}
