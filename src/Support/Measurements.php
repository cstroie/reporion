<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The sizes a sentence states (D51): `12 mm`, `1,2 cm`, `12x8 mm`,
 * `12 x 8 x 5 mm`, `12/8 mm`, each with its largest dimension in mm, so a
 * size in cm and one in mm compare. Pure; the editor's twin is
 * assets/js/editor-aids.js `measurements()`, tested against the same cases.
 */
final class Measurements
{
    private const PATTERN = '/(?<![\p{L}\p{N}.,])(\d+(?:[.,]\d+)?(?:\s*[x×*\/]\s*\d+(?:[.,]\d+)?){0,2})\s*(mm|cm)(?![\p{L}\p{N}])/iu';

    /**
     * @return list<array{text: string, mm: float, unit: string}> in the order written; `mm` the largest dimension
     */
    public static function of(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $match) {
            $unit = strtolower($match[2]);
            $dims = array_map(static fn (string $d): float => (float) str_replace(',', '.', trim($d)), preg_split('/[x×*\/]/u', $match[1]) ?: []);
            $largest = $dims === [] ? 0.0 : max($dims);
            $out[] = ['text' => trim($match[0]), 'mm' => round($unit === 'cm' ? $largest * 10 : $largest, 2), 'unit' => $unit];
        }

        return $out;
    }
}
