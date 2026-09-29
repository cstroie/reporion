<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * A minimal ZIP writer — entries *stored*, not deflated. The server has no
 * ext-zip (ODT export goes through PhpWord's bundled PCLZip), and what goes
 * in here is already-compressed PDF, so storing costs nothing and needs
 * only crc32(). Built in memory: a bulk export is a few MB at most.
 * No ZIP64 — a single entry or the whole archive under 4 GiB.
 */
final class Zip
{
    /**
     * @param array<string, string> $files entry name (UTF-8, `/`-separated) => bytes
     */
    public static function build(array $files, DateTimeInterface $at): string
    {
        [$time, $date] = self::dosTime($at);
        $out = '';
        $central = '';
        foreach ($files as $name => $bytes) {
            $name = (string) $name;
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '..')) {
                throw new InvalidArgumentException('Bad zip entry name');
            }
            $crc = crc32($bytes);
            $size = \strlen($bytes);
            // Bit 11: the name is UTF-8
            $common = pack('vvvvvVVVv', 20, 0x0800, 0, $time, $date, $crc, $size, $size, \strlen($name));
            $offset = \strlen($out);
            $out .= pack('V', 0x04034b50) . $common . pack('v', 0) . $name . $bytes;
            $central .= pack('Vv', 0x02014b50, 20) . $common . pack('vvvvVV', 0, 0, 0, 0, 0, $offset) . $name;
        }

        return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, \count($files), \count($files), \strlen($central), \strlen($out), 0);
    }

    /**
     * @return array{0: int, 1: int} MS-DOS time and date
     */
    private static function dosTime(DateTimeInterface $at): array
    {
        $year = max(1980, (int) $at->format('Y'));

        return [
            ((int) $at->format('G') << 11) | ((int) $at->format('i') << 5) | intdiv((int) $at->format('s'), 2),
            (($year - 1980) << 9) | ((int) $at->format('n') << 5) | (int) $at->format('j'),
        ];
    }
}
