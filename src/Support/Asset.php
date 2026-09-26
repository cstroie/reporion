<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * URLs for files under assets/ with a version in the query string —
 * `/assets/css/wiki.css?v=5f3a2c` — taken from the file's modification
 * time, so a browser may keep an asset for a year (docs/deploy-lighttpd.md)
 * and still fetches the new one the moment a deploy changes it.
 */
final class Asset
{
    public static function url(string $basePath, string $file): string
    {
        $mtime = @filemtime(\dirname(__DIR__, 2) . '/assets/' . $file);

        return $basePath . '/assets/' . $file . ($mtime !== false ? '?v=' . dechex($mtime) : '');
    }
}
