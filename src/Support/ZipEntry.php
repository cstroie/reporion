<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use DateTimeInterface;
use RuntimeException;

/**
 * One entry of a Support\Zip archive, as it sits in the file: its data
 * stored or raw-DEFLATE compressed, with the CRC and size of the plain
 * bytes. An entry read from one archive and written into another keeps
 * its compressed bytes exactly (sameAs()): the attic copies history
 * forward, it never re-encodes it (D54, invariant 3).
 */
final class ZipEntry
{
    public const STORED = 0;
    public const DEFLATED = 8;

    public function __construct(
        public readonly string $name,
        public readonly int $method,
        public readonly int $crc,
        public readonly int $size,
        public readonly string $data,
        public readonly int $dosTime,
        public readonly int $dosDate,
    ) {
    }

    public static function stored(string $name, string $bytes, DateTimeInterface $at): self
    {
        [$time, $date] = Zip::dosTime($at);

        return new self($name, self::STORED, crc32($bytes), \strlen($bytes), $bytes, $time, $date);
    }

    public static function deflated(string $name, string $bytes, DateTimeInterface $at): self
    {
        [$time, $date] = Zip::dosTime($at);
        $data = gzdeflate($bytes, 9);
        if ($data === false) {
            throw new RuntimeException('Deflate failed');
        }

        return new self($name, self::DEFLATED, crc32($bytes), \strlen($bytes), $data, $time, $date);
    }

    /** The plain bytes, checked against the entry's size and CRC */
    public function bytes(): string
    {
        if ($this->method === self::STORED) {
            $bytes = $this->data;
        } else {
            // The declared size bounds the output: a lying entry fails here instead of filling memory
            $bytes = @gzinflate($this->data, max(1, $this->size));
            if ($bytes === false) {
                throw new RuntimeException('Zip entry does not inflate');
            }
        }
        if (\strlen($bytes) !== $this->size || crc32($bytes) !== $this->crc) {
            throw new RuntimeException('Zip entry does not match its size or CRC');
        }

        return $bytes;
    }

    /** Byte for byte the same entry: name, method, CRC, size and compressed data */
    public function sameAs(self $other): bool
    {
        return $this->name === $other->name
            && $this->method === $other->method
            && $this->crc === $other->crc
            && $this->size === $other->size
            && $this->data === $other->data;
    }
}
