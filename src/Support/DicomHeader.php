<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use InvalidArgumentException;

/**
 * The header of a DICOM Part 10 file read for what starts a report: the
 * patient, study and scanner elements, keyword → text. Pure — bytes in, an
 * array out — and it never reads pixel data: parsing stops at (7FE0,0010).
 * Sequences are walked past, not read. Implicit and explicit little endian
 * and deflated transfer syntaxes are understood (a compressed image changes
 * only the pixel data, not the header); explicit big endian, retired in
 * 2006, is refused. Text is decoded per SpecificCharacterSet (ASCII, UTF-8,
 * Latin-1 and Latin-2 — what Romanian scanners write) and a value with
 * several parts keeps them joined by a backslash, as DICOM writes them.
 *
 * Nothing here logs or stores a value: they are patient data (invariant 8).
 */
final class DicomHeader
{
    /** The tags kept: group<<16 | element → keyword */
    public const TAGS = [
        0x00080005 => 'SpecificCharacterSet',
        0x00080020 => 'StudyDate',
        0x00080030 => 'StudyTime',
        0x00080050 => 'AccessionNumber',
        0x00080060 => 'Modality',
        0x00080070 => 'Manufacturer',
        0x00080080 => 'InstitutionName',
        0x00080090 => 'ReferringPhysicianName',
        0x00081010 => 'StationName',
        0x00081030 => 'StudyDescription',
        0x0008103E => 'SeriesDescription',
        0x00081090 => 'ManufacturerModelName',
        0x00100010 => 'PatientName',
        0x00100020 => 'PatientID',
        0x00100030 => 'PatientBirthDate',
        0x00100040 => 'PatientSex',
        0x00101010 => 'PatientAge',
        0x00180010 => 'ContrastBolusAgent',
        0x00180015 => 'BodyPartExamined',
        0x00180020 => 'ScanningSequence',
        0x00180087 => 'MagneticFieldStrength',
        0x00181030 => 'ProtocolName',
        0x0020000D => 'StudyInstanceUID',
        0x00321030 => 'ReasonForStudy',
        0x00321060 => 'RequestedProcedureDescription',
        0x00401002 => 'ReasonForTheRequestedProcedure',
    ];

    private const TS_IMPLICIT_LE = '1.2.840.10008.1.2';
    private const TS_DEFLATED = '1.2.840.10008.1.2.1.99';
    private const TS_EXPLICIT_BE = '1.2.840.10008.1.2.2';

    /** VRs whose explicit header is tag, VR, 2 reserved bytes, then a 4-byte length */
    private const LONG_VR = ['OB' => 1, 'OD' => 1, 'OF' => 1, 'OL' => 1, 'OV' => 1, 'OW' => 1, 'SQ' => 1, 'SV' => 1, 'UC' => 1, 'UN' => 1, 'UR' => 1, 'UT' => 1, 'UV' => 1];

    private const CHARSETS = ['ISO_IR 192' => 'UTF-8', 'ISO_IR 100' => 'ISO-8859-1', 'ISO_IR 101' => 'ISO-8859-2', 'ISO_IR 6' => 'ASCII'];

    private const UNDEFINED = 0xFFFFFFFF;

    /**
     * @return array{transfer_syntax: string, elements: array<string, string>, other: int}
     *     `elements` holds a keyword for every TAGS element present (possibly ''); `other` counts the elements read and ignored
     *
     * @throws InvalidArgumentException when it is not a readable DICOM file
     */
    public static function parse(string $bytes): array
    {
        if (\strlen($bytes) < 140 || substr($bytes, 128, 4) !== 'DICM') {
            throw new InvalidArgumentException('Not a DICOM Part 10 file (no DICM marker)');
        }
        $pos = 132;
        $raw = [];
        $other = 0;

        // The file meta group (0002) is always explicit little endian
        $syntax = self::TS_IMPLICIT_LE;
        while ($pos + 8 <= \strlen($bytes) && ord($bytes[$pos]) === 0x02 && ord($bytes[$pos + 1]) === 0x00) {
            [$tag, , $length, $valueAt] = self::element($bytes, $pos, true);
            if ($tag === 0x00020010) {
                $syntax = rtrim(substr($bytes, $valueAt, $length), " \0");
            }
            $pos = $valueAt + $length;
        }
        if ($syntax === self::TS_EXPLICIT_BE) {
            throw new InvalidArgumentException('Explicit big endian transfer syntax is not supported');
        }
        $data = substr($bytes, $pos);
        if ($syntax === self::TS_DEFLATED) {
            $inflated = @gzinflate($data);
            if ($inflated === false) {
                throw new InvalidArgumentException('The deflated data set cannot be read');
            }
            $data = $inflated;
        }

        $explicit = $syntax !== self::TS_IMPLICIT_LE;
        $pos = 0;
        $end = \strlen($data);
        while ($pos + 8 <= $end) {
            [$tag, $vr, $length, $valueAt] = self::element($data, $pos, $explicit);
            if ($tag === 0x7FE00010) {
                break;
            }
            if ($length === self::UNDEFINED) {
                // A sequence (or an encapsulated value) of unknown length: walked past
                $pos = self::skipUndefined($data, $valueAt, $explicit);
                ++$other;
                continue;
            }
            if ($valueAt + $length > $end) {
                break;
            }
            if (isset(self::TAGS[$tag])) {
                $raw[$tag] = substr($data, $valueAt, $length);
            } else {
                ++$other;
            }
            $pos = $valueAt + $length;
        }

        $charset = self::CHARSETS[trim(explode('\\', rtrim($raw[0x00080005] ?? '', " \0"))[0])] ?? 'ISO-8859-1';
        $elements = [];
        foreach (self::TAGS as $tag => $keyword) {
            if (isset($raw[$tag])) {
                $elements[$keyword] = self::text($raw[$tag], $charset);
            }
        }

        return ['transfer_syntax' => $syntax, 'elements' => $elements, 'other' => $other];
    }

    /**
     * One element header at $pos.
     *
     * @return array{0: int, 1: string, 2: int, 3: int} tag, VR ('' when implicit), value length, where the value starts
     */
    private static function element(string $d, int $pos, bool $explicit): array
    {
        $group = (int) unpack('v', $d, $pos)[1];
        $elem = (int) unpack('v', $d, $pos + 2)[1];
        $tag = ($group << 16) | $elem;
        // Item and delimiter tags (FFFE,xxxx) never carry a VR
        if (!$explicit || $group === 0xFFFE) {
            return [$tag, '', (int) unpack('V', $d, $pos + 4)[1], $pos + 8];
        }
        $vr = substr($d, $pos + 4, 2);
        if (isset(self::LONG_VR[$vr])) {
            return [$tag, $vr, (int) unpack('V', $d, $pos + 8)[1], $pos + 12];
        }

        return [$tag, $vr, (int) unpack('v', $d, $pos + 6)[1], $pos + 8];
    }

    /** The position after a value of undefined length that starts at $pos: items up to the sequence delimiter */
    private static function skipUndefined(string $d, int $pos, bool $explicit): int
    {
        $end = \strlen($d);
        while ($pos + 8 <= $end) {
            [$tag, , $length, $valueAt] = self::element($d, $pos, $explicit);
            if ($tag === 0xFFFEE0DD) {
                return $valueAt;
            }
            if ($tag !== 0xFFFEE000) {
                return $end;
            }
            if ($length !== self::UNDEFINED) {
                $pos = $valueAt + $length;
                continue;
            }
            // An item of undefined length: its elements up to the item delimiter
            $pos = $valueAt;
            while ($pos + 8 <= $end) {
                [$inner, , $innerLength, $innerAt] = self::element($d, $pos, $explicit);
                if ($inner === 0xFFFEE00D) {
                    $pos = $innerAt;
                    break;
                }
                $pos = $innerLength === self::UNDEFINED ? self::skipUndefined($d, $innerAt, $explicit) : $innerAt + $innerLength;
            }
        }

        return $end;
    }

    private static function text(string $value, string $charset): string
    {
        $value = rtrim($value, " \0");
        if ($charset !== 'UTF-8' && $charset !== 'ASCII') {
            $value = mb_convert_encoding($value, 'UTF-8', $charset);
        }

        return trim($value);
    }
}
