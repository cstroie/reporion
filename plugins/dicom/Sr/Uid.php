<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Dicom\Sr;

/**
 * DICOM UIDs under the 2.25 root (a UID from a UUID-sized number, PS3.5
 * B.2): `2.25.` + a 128-bit number in decimal, taken from a hash of the
 * seed so the same report revision always gets the same UIDs and a
 * second export is byte for byte the first.
 */
final class Uid
{
    /** Identifies this software in the file meta (implementation class UID) */
    public const IMPLEMENTATION = '2.25.279316058263859170347213904837266571533';

    public static function derive(string ...$seed): string
    {
        $hex = substr(hash('sha256', implode('|', $seed)), 0, 32);
        // Hex to decimal without bcmath/gmp: repeated division of the digit list by 10
        $digits = array_map('hexdec', str_split($hex));
        $decimal = '';
        while ($digits !== []) {
            $rest = 0;
            $next = [];
            foreach ($digits as $digit) {
                $value = $rest * 16 + $digit;
                $quotient = intdiv($value, 10);
                $rest = $value % 10;
                if ($next !== [] || $quotient > 0) {
                    $next[] = $quotient;
                }
            }
            $decimal = $rest . $decimal;
            $digits = $next;
        }

        return '2.25.' . ($decimal === '' ? '0' : $decimal);
    }

    /** A UID as DICOM allows it: digits and dots, no empty or zero-led component, ≤ 64 */
    public static function isValid(string $uid): bool
    {
        return \strlen($uid) <= 64 && preg_match('/^(0|[1-9][0-9]*)(\.(0|[1-9][0-9]*))*$/', $uid) === 1;
    }
}
