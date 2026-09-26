<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * How markdown refers to an attached file (D27): `![name](media:{sha256}.{ext})`,
 * independent of where the app is mounted. The page view resolves it to
 * `{basePath}/media/{sha256}.{ext}`; print and export embed the bytes.
 * assets/js/markdown-preview.js resolves it the same way for the preview.
 */
final class MediaRef
{
    public const PATTERN = '/^media:([0-9a-f]{64})\.(png|jpg|gif|webp)$/';

    /** @return ?array{sha256: string, ext: string} */
    public static function parse(string $url): ?array
    {
        return preg_match(self::PATTERN, $url, $m) === 1 ? ['sha256' => $m[1], 'ext' => $m[2]] : null;
    }

    public static function markdown(string $name, string $sha256, string $ext): string
    {
        return '![' . str_replace(['[', ']', '\\'], '', $name) . '](media:' . $sha256 . '.' . $ext . ')';
    }
}
