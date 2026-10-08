<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Dicom\Header;
use Reporion\Plugin\Loader;

/**
 * plugins/dicom's Header on files built here, byte by byte — no real study
 * (invariant 10). Implicit and explicit little endian, a sequence of
 * undefined length ahead of the patient, a Latin-2 name, pixel data cut off.
 */
final class DicomHeaderTest extends TestCase
{
    protected function setUp(): void
    {
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'dicom');
    }

    /** An explicit little endian element */
    public static function ex(int $group, int $elem, string $vr, string $value): string
    {
        if ($value !== '' && \strlen($value) % 2 === 1) {
            $value .= \in_array($vr, ['UI'], true) ? "\0" : ' ';
        }
        $long = \in_array($vr, ['OB', 'OW', 'SQ', 'UN', 'UT'], true);

        return pack('vv', $group, $elem) . $vr . ($long ? "\0\0" . pack('V', \strlen($value)) : pack('v', \strlen($value))) . $value;
    }

    /** An implicit little endian element */
    public static function im(int $group, int $elem, string $value): string
    {
        if ($value !== '' && \strlen($value) % 2 === 1) {
            $value .= ' ';
        }

        return pack('vvV', $group, $elem, \strlen($value)) . $value;
    }

    public static function file(string $syntax, string $dataSet): string
    {
        $meta = self::ex(0x0002, 0x0010, 'UI', $syntax);

        return str_repeat("\0", 128) . 'DICM' . self::ex(0x0002, 0x0000, 'UL', pack('V', \strlen($meta))) . $meta . $dataSet;
    }

    public function testExplicitLittleEndianWithASequenceAndPixelData(): void
    {
        $item = pack('vvV', 0xFFFE, 0xE000, 0xFFFFFFFF) . self::ex(0x0008, 0x0100, 'SH', 'CODE') . pack('vvV', 0xFFFE, 0xE00D, 0);
        $sequence = pack('vv', 0x0008, 0x1111) . 'SQ' . "\0\0" . pack('V', 0xFFFFFFFF) . $item . pack('vvV', 0xFFFE, 0xE0DD, 0);
        $data = self::ex(0x0008, 0x0005, 'CS', 'ISO_IR 100')
            . self::ex(0x0008, 0x0020, 'DA', '20221215')
            . self::ex(0x0008, 0x0060, 'CS', 'MR')
            . $sequence
            . self::ex(0x0010, 0x0010, 'PN', 'PACIENT^TEST^UNU')
            . self::ex(0x0010, 0x0020, 'LO', '5010203401239')
            . self::ex(0x0010, 0x0030, 'DA', '')
            . self::ex(0x0018, 0x0087, 'DS', '1.5')
            . self::ex(0x7FE0, 0x0010, 'OW', 'pixels!!');

        $h = Header::parse(self::file('1.2.840.10008.1.2.1', $data));

        self::assertSame('PACIENT^TEST^UNU', $h['elements']['PatientName']);
        self::assertSame('5010203401239', $h['elements']['PatientID']);
        self::assertSame('', $h['elements']['PatientBirthDate'], 'an empty element is present and empty');
        self::assertArrayNotHasKey('PatientSex', $h['elements']);
        self::assertSame('1.5', $h['elements']['MagneticFieldStrength']);
        self::assertSame(1, $h['other'], 'the sequence was walked past, the pixel data not counted');
    }

    public function testImplicitLittleEndian(): void
    {
        $data = self::im(0x0008, 0x0060, 'MR') . self::im(0x0010, 0x0010, 'PACIENT^TEST') . self::im(0x0008, 0x1030, 'IRM CEREBRAL NATIV');

        $h = Header::parse(self::file('1.2.840.10008.1.2', $data));

        self::assertSame('IRM CEREBRAL NATIV', $h['elements']['StudyDescription']);
        self::assertSame('PACIENT^TEST', $h['elements']['PatientName']);
    }

    public function testTextIsDecodedByTheDeclaredCharacterSet(): void
    {
        $latin2 = self::ex(0x0008, 0x0005, 'CS', 'ISO_IR 101') . self::ex(0x0010, 0x0010, 'PN', "\xAATEFAN");
        $utf8 = self::ex(0x0008, 0x0005, 'CS', 'ISO_IR 192') . self::ex(0x0010, 0x0010, 'PN', 'ȘTEFAN');

        self::assertSame('ŞTEFAN', Header::parse(self::file('1.2.840.10008.1.2.1', $latin2))['elements']['PatientName']);
        self::assertSame('ȘTEFAN', Header::parse(self::file('1.2.840.10008.1.2.1', $utf8))['elements']['PatientName']);
    }

    public function testADeflatedDataSetIsInflated(): void
    {
        $data = gzdeflate(self::ex(0x0008, 0x0060, 'CS', 'MR'));

        self::assertSame('MR', Header::parse(self::file('1.2.840.10008.1.2.1.99', $data))['elements']['Modality']);
    }

    public function testNotDicomAndBigEndianAreRefused(): void
    {
        try {
            Header::parse(str_repeat('x', 200));
            self::fail('no DICM marker');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('DICM', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        Header::parse(self::file('1.2.840.10008.1.2.2', ''));
    }

    public function testATruncatedFileDoesNotRunPastItsEnd(): void
    {
        $data = self::ex(0x0008, 0x0060, 'CS', 'MR') . substr(self::ex(0x0010, 0x0010, 'PN', 'PACIENT^TEST'), 0, 12);

        $h = Header::parse(self::file('1.2.840.10008.1.2.1', $data));

        self::assertSame('MR', $h['elements']['Modality']);
        self::assertArrayNotHasKey('PatientName', $h['elements']);
    }
}
