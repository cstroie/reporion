<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use InvalidArgumentException;

/**
 * WAV files joined into one (docs/library.md, phase 8: the audio overview's turns): each read as
 * RIFF (its `fmt ` and `data` chunks, whatever other chunks it has), all of one format (PCM, the
 * same rate, channels and bits) or refused, a short silence between them, one header written for
 * the whole. Never byte-appended: each file's header would say only its own length. Pure.
 */
final class Wav
{
    /**
     * @param list<string> $files
     *
     * @throws InvalidArgumentException a file that is not PCM WAV, or formats that differ
     */
    public static function join(array $files, float $gapSeconds = 0.35): string
    {
        $format = null;
        $data = [];
        foreach ($files as $file) {
            [$fmt, $pcm] = self::read($file);
            if ($format !== null && $fmt !== $format) {
                throw new InvalidArgumentException('The turns are not all of one audio format');
            }
            $format ??= $fmt;
            $data[] = $pcm;
        }
        if ($format === null) {
            throw new InvalidArgumentException('No audio');
        }
        ['channels' => $channels, 'rate' => $rate, 'bits' => $bits] = $format;
        $block = $channels * intdiv($bits, 8);
        $gap = str_repeat("\0", (int) round($gapSeconds * $rate) * $block);
        $pcm = implode($gap, $data);

        return 'RIFF' . pack('V', 36 + \strlen($pcm)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, $channels, $rate, $rate * $block, $block, $bits)
            . 'data' . pack('V', \strlen($pcm)) . $pcm;
    }

    /** The length of a PCM WAV, in seconds */
    public static function seconds(string $file): float
    {
        [$fmt, $pcm] = self::read($file);

        return \strlen($pcm) / ($fmt['rate'] * $fmt['channels'] * intdiv($fmt['bits'], 8));
    }

    /**
     * @return array{0: array{channels: int, rate: int, bits: int}, 1: string}
     *
     * @throws InvalidArgumentException
     */
    private static function read(string $file): array
    {
        if (\strlen($file) < 12 || substr($file, 0, 4) !== 'RIFF' || substr($file, 8, 4) !== 'WAVE') {
            throw new InvalidArgumentException('Not a WAV file');
        }
        $fmt = null;
        $pcm = null;
        $at = 12;
        while ($at + 8 <= \strlen($file)) {
            $id = substr($file, $at, 4);
            $size = unpack('V', substr($file, $at + 4, 4))[1];
            // A streamed WAV may say 0 or 0xFFFFFFFF for its data: the rest of the file then
            if ($id === 'data' && ($size === 0 || $size === 0xFFFFFFFF || $at + 8 + $size > \strlen($file))) {
                $size = \strlen($file) - $at - 8;
            }
            $body = substr($file, $at + 8, $size);
            if ($id === 'fmt ' && \strlen($body) >= 16) {
                $f = unpack('vformat/vchannels/Vrate/Vbyterate/vblock/vbits', $body);
                if ($f['format'] !== 1 && $f['format'] !== 0xFFFE) {
                    throw new InvalidArgumentException('Not PCM audio');
                }
                $fmt = ['channels' => (int) $f['channels'], 'rate' => (int) $f['rate'], 'bits' => (int) $f['bits']];
            } elseif ($id === 'data') {
                $pcm = $body;
            }
            $at += 8 + $size + ($size % 2);
        }
        if ($fmt === null || $pcm === null || $fmt['channels'] < 1 || $fmt['rate'] < 1 || !\in_array($fmt['bits'], [8, 16, 24, 32], true)) {
            throw new InvalidArgumentException('Not a complete WAV file');
        }

        return [$fmt, $pcm];
    }
}
