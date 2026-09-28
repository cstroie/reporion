<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A one-line teaser under a page title (templates/namespace.php's "Pages
 * in this namespace" table): whole words only, up to $maxChars, then an
 * ellipsis — never a word cut mid-way.
 */
final class Snippet
{
    public static function words(string $text, int $maxChars): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '' || mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $kept = '';
        foreach (explode(' ', $text) as $word) {
            $candidate = $kept === '' ? $word : $kept . ' ' . $word;
            if (mb_strlen($candidate) > $maxChars) {
                break;
            }
            $kept = $candidate;
        }

        // A single word already longer than $maxChars: keep the limit, not the word
        if ($kept === '') {
            $kept = mb_substr($text, 0, $maxChars);
        }

        return $kept . '…';
    }
}
