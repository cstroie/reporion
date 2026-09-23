<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use InvalidArgumentException;
use Normalizer;

/**
 * Path-segment normalisation (docs/architecture-storage-index.md §2): NFKD-fold
 * to ASCII, lowercase, collapse non-alphanumerics to a single hyphen, trim to
 * 64 characters. Applies to one colon-separated segment at a time.
 */
final class Slug
{
    private const MAX_LENGTH = 64;

    public static function normalize(string $segment): string
    {
        $folded = Normalizer::normalize($segment, Normalizer::FORM_D) ?: $segment;
        $stripped = preg_replace('/\p{Mn}+/u', '', $folded) ?? $folded;
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $stripped);
        $ascii = $transliterated !== false ? $transliterated : $stripped;

        $lower = mb_strtolower($ascii);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? '';
        $slug = trim($slug, '-');
        $slug = mb_substr($slug, 0, self::MAX_LENGTH);
        $slug = trim($slug, '-');

        if ($slug === '') {
            throw new InvalidArgumentException('Path segment normalises to an empty slug');
        }

        return $slug;
    }
}
