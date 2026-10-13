<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * What counts as a report (decided 2026-09-26): a page under `reports:`
 * whose last path segment is a report name: six digits, then the name
 * (`reports:mri:mioveni:260926-popescu-ana-maria`, and the collision form
 * `…-maria-2`). The namespace is what protects reports, so no frontmatter
 * field decides it; the other pages in `reports:` (a site's description, a
 * modality overview) are ordinary pages.
 */
final class ReportPath
{
    /** The namespace every report lives under */
    public const ROOT = 'reports';

    private const NAME = '/^\d{6}-[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public static function isReport(string $path): bool
    {
        return str_starts_with($path, self::ROOT . ':') && self::looksLikeReportName(self::leaf($path));
    }

    /**
     * `reports` itself or any namespace below it, where a new page is a
     * report (the guided form), and where a listing is a patient worklist.
     * The namespace counterpart of isReport(), which also needs the leaf.
     */
    public static function isReportNamespace(string $ns): bool
    {
        return $ns === self::ROOT || str_starts_with($ns, self::ROOT . ':');
    }

    /**
     * The D1 shape `{yymmdd}-{name}` in the last segment, wherever the page
     * is: a path that would put a patient's name in a URL (Publishing's
     * warning before making a page public).
     */
    public static function looksLikeReportName(string $segment): bool
    {
        return preg_match(self::NAME, strtolower($segment)) === 1;
    }

    /** The path of a report: `reports:{modality ns}:{site}:{yymmdd}-{name}` */
    public static function pathFor(string $modalityNs, string $site, string $day, string $name): string
    {
        return self::ROOT . ':' . $modalityNs . ':' . $site . ':' . $day . '-' . $name;
    }

    public static function leaf(string $path): string
    {
        $colon = strrpos($path, ':');

        return $colon === false ? $path : substr($path, $colon + 1);
    }
}
