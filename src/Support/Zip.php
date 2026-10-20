<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * A minimal ZIP reader and writer. The server has no ext-zip (ODT export
 * goes through PhpWord's bundled PCLZip), so this is plain PHP over zlib:
 * entries stored or raw-DEFLATE compressed (ZipEntry), no encryption, no
 * ZIP64 (a single entry or the whole archive under 4 GiB), no multi-disk.
 * Built and read in memory: a bulk export is a few MB at most, a page's
 * attic zip (D54) tens of kB.
 */
final class Zip
{
    private const LOCAL = 0x04034b50;
    private const CENTRAL = 0x02014b50;
    private const END = 0x06054b50;
    /** Bit 11: the name is UTF-8 */
    private const UTF8 = 0x0800;

    /**
     * Entries *stored*, not deflated: what goes in here is already-compressed PDF.
     *
     * @param array<string, string> $files entry name (UTF-8, `/`-separated) => bytes
     */
    public static function build(array $files, DateTimeInterface $at): string
    {
        $entries = [];
        foreach ($files as $name => $bytes) {
            $entries[] = ZipEntry::stored((string) $name, $bytes, $at);
        }

        return self::assemble($entries);
    }

    /** @param list<ZipEntry> $entries in archive order */
    public static function assemble(array $entries): string
    {
        $out = '';
        $central = '';
        $seen = [];
        foreach ($entries as $entry) {
            self::assertName($entry->name);
            if (isset($seen[$entry->name])) {
                throw new InvalidArgumentException('Duplicate zip entry name');
            }
            $seen[$entry->name] = true;
            $common = pack('vvvvvVVVv', 20, self::UTF8, $entry->method, $entry->dosTime, $entry->dosDate, $entry->crc, \strlen($entry->data), $entry->size, \strlen($entry->name));
            $offset = \strlen($out);
            $out .= pack('V', self::LOCAL) . $common . pack('v', 0) . $entry->name . $entry->data;
            $central .= pack('Vv', self::CENTRAL, 20) . $common . pack('vvvvVV', 0, 0, 0, 0, 0, $offset) . $entry->name;
        }

        return $out . $central . pack('VvvvvVVv', self::END, 0, 0, \count($entries), \count($entries), \strlen($central), \strlen($out), 0);
    }

    /**
     * The entries of $archive in central-directory order, keyed by name.
     * Every offset and length is bounds-checked; an encrypted, ZIP64,
     * multi-disk or unknown-method entry, a name that could escape a
     * directory, a duplicate name or a local header that disagrees with
     * the central directory is refused. The data is not inflated here:
     * ZipEntry::bytes() does that and checks the CRC.
     *
     * @return array<string, ZipEntry>
     *
     * @throws RuntimeException not a zip this class reads
     */
    public static function read(string $archive): array
    {
        $length = \strlen($archive);
        $end = self::findEnd($archive);
        $eocd = unpack('Vsig/vdisk/vcdDisk/vonDisk/vtotal/VcdSize/VcdOffset/vcomment', $archive, $end);
        if ($eocd === false || $eocd['disk'] !== 0 || $eocd['cdDisk'] !== 0 || $eocd['onDisk'] !== $eocd['total']
            || $eocd['cdOffset'] + $eocd['cdSize'] > $end) {
            throw new RuntimeException('Zip central directory out of bounds');
        }

        $entries = [];
        $pos = $eocd['cdOffset'];
        for ($i = 0; $i < $eocd['total']; ++$i) {
            if ($pos + 46 > $end) {
                throw new RuntimeException('Zip central directory truncated');
            }
            $cd = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen/vcommentLen/vdisk/vinternal/Vexternal/Voffset', $archive, $pos);
            if ($cd === false || $cd['sig'] !== self::CENTRAL) {
                throw new RuntimeException('Zip central directory entry malformed');
            }
            $name = substr($archive, $pos + 46, $cd['nameLen']);
            $pos += 46 + $cd['nameLen'] + $cd['extraLen'] + $cd['commentLen'];
            if ($pos > $end || \strlen($name) !== $cd['nameLen']) {
                throw new RuntimeException('Zip central directory truncated');
            }
            if (($cd['flags'] & 0x0001) !== 0 || $cd['disk'] !== 0 || $cd['csize'] === 0xFFFFFFFF || $cd['usize'] === 0xFFFFFFFF || $cd['offset'] === 0xFFFFFFFF) {
                throw new RuntimeException('Zip entry encrypted, multi-disk or ZIP64');
            }
            if ($cd['method'] !== ZipEntry::STORED && $cd['method'] !== ZipEntry::DEFLATED) {
                throw new RuntimeException('Zip entry method not supported');
            }
            try {
                self::assertName($name);
            } catch (InvalidArgumentException) {
                throw new RuntimeException('Zip entry name refused');
            }
            if (isset($entries[$name])) {
                throw new RuntimeException('Duplicate zip entry name');
            }

            $local = $cd['offset'];
            if ($local + 30 > $length) {
                throw new RuntimeException('Zip local header out of bounds');
            }
            $lh = unpack('Vsig/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen', $archive, $local);
            if ($lh === false || $lh['sig'] !== self::LOCAL || $lh['method'] !== $cd['method']
                || substr($archive, $local + 30, $lh['nameLen']) !== $name) {
                throw new RuntimeException('Zip local header disagrees with the central directory');
            }
            $start = $local + 30 + $lh['nameLen'] + $lh['extraLen'];
            if ($start + $cd['csize'] > $eocd['cdOffset']) {
                throw new RuntimeException('Zip entry data out of bounds');
            }

            $entries[$name] = new ZipEntry($name, $cd['method'], $cd['crc'], $cd['usize'], substr($archive, $start, $cd['csize']), $cd['time'], $cd['date']);
        }

        return $entries;
    }

    /**
     * @return array{0: int, 1: int} MS-DOS time and date
     */
    public static function dosTime(DateTimeInterface $at): array
    {
        $year = max(1980, (int) $at->format('Y'));

        return [
            ((int) $at->format('G') << 11) | ((int) $at->format('i') << 5) | intdiv((int) $at->format('s'), 2),
            (($year - 1980) << 9) | ((int) $at->format('n') << 5) | (int) $at->format('j'),
        ];
    }

    private static function assertName(string $name): void
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, '..') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new InvalidArgumentException('Bad zip entry name');
        }
    }

    /** The end-of-central-directory record, searched back over a comment of up to 64 kB */
    private static function findEnd(string $archive): int
    {
        $length = \strlen($archive);
        for ($pos = $length - 22; $pos >= max(0, $length - 22 - 0xFFFF); --$pos) {
            if (substr($archive, $pos, 4) === "PK\x05\x06") {
                $comment = unpack('v', $archive, $pos + 20);
                if ($comment !== false && $pos + 22 + $comment[1] === $length) {
                    return $pos;
                }
            }
        }

        throw new RuntimeException('Not a zip archive');
    }
}
