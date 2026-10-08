<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Reporion\Cli\DicomHeaderCommand;
use Reporion\Cli\Output;
use Reporion\Support\Cnp;
use Reporion\Tests\Support\DicomHeaderTest;

/** dicom:header: states per field, the CNP cross-checks in words, values only on request. Made-up data. */
final class DicomHeaderCommandTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $first12 = '501020340123';
        $cnp = $first12 . Cnp::checksum($first12);
        $this->file = sys_get_temp_dir() . '/reporion-dcm-' . bin2hex(random_bytes(5)) . '.dcm';
        file_put_contents($this->file, DicomHeaderTest::file('1.2.840.10008.1.2.1',
            DicomHeaderTest::ex(0x0008, 0x0060, 'CS', 'MR')
            . DicomHeaderTest::ex(0x0008, 0x1030, 'LO', 'IRM CEREBRAL NATIV')
            . DicomHeaderTest::ex(0x0010, 0x0010, 'PN', 'PACIENT^TEST')
            . DicomHeaderTest::ex(0x0010, 0x0020, 'LO', $cnp)
            . DicomHeaderTest::ex(0x0010, 0x0030, 'DA', '20010203')
            . DicomHeaderTest::ex(0x0010, 0x0040, 'CS', 'F')
            . DicomHeaderTest::ex(0x0018, 0x0015, 'CS', '')));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** @param list<string> $args */
    private function exec(array $args, ?int &$code = null): string
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new DicomHeaderCommand())->run($args, new Output($out, $err));
        rewind($out);
        rewind($err);

        return (string) stream_get_contents($out) . (string) stream_get_contents($err);
    }

    public function testStatesAndChecksWithoutValues(): void
    {
        $text = $this->exec([$this->file], $code);

        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/exam_title\s+StudyDescription\s+filled/', $text);
        self::assertMatchesRegularExpression('/region\s+BodyPartExamined\s+empty/', $text);
        self::assertMatchesRegularExpression('/indication\s+ReasonForStudy\s+absent/', $text);
        self::assertStringContainsString('PatientID: a valid CNP', $text);
        self::assertStringContainsString('PatientBirthDate: agrees with the CNP', $text);
        self::assertStringContainsString('PatientSex: DISAGREES with the CNP', $text, 'the CNP says male, the header says F');
        foreach (['PACIENT', 'IRM CEREBRAL', '5010203', $this->file] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }

    public function testValuesOnlyOnRequest(): void
    {
        self::assertStringContainsString('IRM CEREBRAL NATIV', $this->exec([$this->file, '--values']));
        self::assertStringContainsString('"state": "filled"', $this->exec([$this->file, '--json']));
    }

    public function testNoFileOrNotDicomExitsOne(): void
    {
        $this->exec([], $code);
        self::assertSame(1, $code);

        file_put_contents($this->file, str_repeat('x', 300));
        self::assertStringContainsString('DICM', $this->exec([$this->file], $code));
        self::assertSame(1, $code);
    }
}
