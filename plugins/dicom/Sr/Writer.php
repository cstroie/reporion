<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Dicom\Sr;

use InvalidArgumentException;

/**
 * DICOM Part 10 file writer, Explicit VR Little Endian, for the few
 * attributes an SR document uses. An element is `[tag, vr, value]`: `tag`
 * an int (0x00100010), `value` a string, an int (US/UL) or, for SQ, a list
 * of items, each a list of elements. Elements are written in tag order;
 * sequences and items carry explicit lengths.
 */
final class Writer
{
    public const EXPLICIT_LITTLE_ENDIAN = '1.2.840.10008.1.2.1';

    /** VRs with a 2-byte reserved field and a 4-byte length */
    private const LONG = ['OB', 'OD', 'OF', 'OL', 'OW', 'SQ', 'UC', 'UN', 'UR', 'UT'];

    /** Character limits (PS3.5 table 6.2-1) — text is cut, never rejected */
    private const MAX = ['SH' => 16, 'LO' => 64, 'PN' => 64, 'CS' => 16, 'LT' => 10240];

    /** @param list<array{0: int, 1: string, 2: mixed}> $dataset */
    public static function file(string $sopClass, string $sopInstance, array $dataset): string
    {
        $meta = self::dataset([
            [0x00020001, 'OB', "\x00\x01"],
            [0x00020002, 'UI', $sopClass],
            [0x00020003, 'UI', $sopInstance],
            [0x00020010, 'UI', self::EXPLICIT_LITTLE_ENDIAN],
            [0x00020012, 'UI', Uid::IMPLEMENTATION],
            [0x00020013, 'SH', 'REPORION'],
        ]);
        $group = self::element(0x00020000, 'UL', \strlen($meta));

        return str_repeat("\0", 128) . 'DICM' . $group . $meta . self::dataset($dataset);
    }

    /** @param list<array{0: int, 1: string, 2: mixed}> $elements */
    public static function dataset(array $elements): string
    {
        usort($elements, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $out = '';
        foreach ($elements as [$tag, $vr, $value]) {
            $out .= self::element($tag, $vr, $value);
        }

        return $out;
    }

    private static function element(int $tag, string $vr, mixed $value): string
    {
        $head = pack('vv', $tag >> 16, $tag & 0xFFFF);
        if ($vr === 'SQ') {
            $bytes = '';
            foreach ((array) $value as $item) {
                $content = self::dataset($item);
                $bytes .= pack('vvV', 0xFFFE, 0xE000, \strlen($content)) . $content;
            }
        } else {
            $bytes = self::value($vr, $value);
        }
        if (\in_array($vr, self::LONG, true)) {
            return $head . $vr . "\0\0" . pack('V', \strlen($bytes)) . $bytes;
        }
        if (\strlen($bytes) > 0xFFFE) {
            throw new InvalidArgumentException('Value too long for VR ' . $vr);
        }

        return $head . $vr . pack('v', \strlen($bytes)) . $bytes;
    }

    private static function value(string $vr, mixed $value): string
    {
        switch ($vr) {
            case 'US':
                return pack('v', (int) $value);
            case 'UL':
                return pack('V', (int) $value);
            case 'OB':
                $raw = (string) $value;

                return \strlen($raw) % 2 === 1 ? $raw . "\0" : $raw;
        }
        $text = (string) $value;
        if (isset(self::MAX[$vr])) {
            $text = mb_substr($text, 0, self::MAX[$vr]);
        }
        // CS/UI/DA/TM/DT/IS/SH/LO/PN/LT/UT are text; UI pads with NUL, the rest with a space
        $pad = $vr === 'UI' ? "\0" : ' ';

        return \strlen($text) % 2 === 1 ? $text . $pad : $text;
    }
}
