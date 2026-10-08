<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The assistant's `summary` answer as the one line that goes into the
 * `summary` field: its first non-empty line, with a list or heading marker,
 * bold, a "Summary:" label and quotes taken off, at most MAX characters
 * (cut at a word, "…"). The same rule as assets/js/ai-summary.js's tidy().
 */
final class SummaryLine
{
    public const MAX = 160;

    public static function tidy(string $answer): string
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $answer) ?: []),
            static fn (string $l): bool => $l !== '' && !str_starts_with($l, '```'),
        ));
        $line = $lines[0] ?? '';
        $line = (string) preg_replace('/^(#{1,6}|[-*•]|\d+[.)])\s+/u', '', $line);
        $line = str_replace('**', '', $line);
        $line = (string) preg_replace('/^(rezumat|summary)\s*:\s*/iu', '', $line);
        $line = trim((string) preg_replace('/^["\'„“”«»`]+|["\'„“”«»`]+$/u', '', $line));
        if (mb_strlen($line) > self::MAX) {
            $line = rtrim((string) preg_replace('/\s+\S*$/u', '', mb_substr($line, 0, self::MAX - 1))) . '…';
        }

        return $line;
    }
}
