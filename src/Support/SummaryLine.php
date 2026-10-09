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

    /**
     * One summary per exam as one text, each led by what was examined
     * (2026-10-09): "IRM Genunchi Drept: Aspect normal. IRM Genunchi Stâng:
     * Minim edem…". A text that already starts with its label keeps it once;
     * each part ends with a full stop. An empty label leaves the text bare.
     *
     * @param list<array{label: string, text: string}> $parts
     */
    public static function byExam(array $parts): string
    {
        $out = [];
        foreach ($parts as $part) {
            $text = trim($part['text']);
            if ($text === '') {
                continue;
            }
            $label = trim($part['label']);
            if ($label !== '' && str_starts_with(self::fold($text), self::fold($label))) {
                $text = ltrim(mb_substr($text, mb_strlen($label)), " \t:—-");
            }
            if (preg_match('/[.!?…]$/u', $text) !== 1) {
                $text .= '.';
            }
            $out[] = ($label !== '' ? $label . ': ' : '') . (mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1));
        }

        return implode(' ', $out);
    }

    private static function fold(string $text): string
    {
        return strtr(mb_strtolower($text), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    }
}
