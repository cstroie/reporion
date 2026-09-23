<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * ULID: 48-bit millisecond timestamp + 80 bits of randomness, Crockford
 * base32, 26 characters, lexicographically sortable by creation time.
 */
final class Ulid
{
    private const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(?int $timeMs = null): string
    {
        $timeMs ??= (int) round(microtime(true) * 1000);

        return self::encodeTime($timeMs) . self::encodeRandom(random_bytes(10));
    }

    private static function encodeTime(int $timeMs): string
    {
        $chars = '';
        for ($i = 0; $i < 10; $i++) {
            $chars = self::ENCODING[$timeMs % 32] . $chars;
            $timeMs = intdiv($timeMs, 32);
        }

        return $chars;
    }

    private static function encodeRandom(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $chars = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chars .= self::ENCODING[bindec($chunk)];
        }

        return $chars;
    }
}
