<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Library;

use PHPUnit\Framework\TestCase;
use Reporion\Exception\LibraryException;
use Reporion\Service\Library\Extractor;
use Reporion\Service\Library\Library;
use Reporion\Tests\Support\CnpTest;

/**
 * OCR of a scan (docs/library.md, phase 8), the tools stubbed: pdftotext finds no text, tesseract
 * reads a made-up text per page. A scan's text is held to the stricter patient guard.
 */
final class OcrTest extends TestCase
{
    private string $tmp;

    /** @var list<list<string>> */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/reporion-ocr-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tmp);
    }

    /** @param list<string> $pages what tesseract reads on each page */
    private function extractor(int $pageCount, array $pages, int $tesseractExit = 0): Extractor
    {
        return new Extractor($this->tmp, runner: function (array $argv, int $timeout, array $env) use ($pageCount, $pages, $tesseractExit): array {
            $this->ran[] = $argv;
            $tool = basename($argv[0]);
            if ($tool === 'pdftotext') {
                file_put_contents((string) end($argv), "\f\f");

                return [0, ''];
            }
            if ($tool === 'pdfinfo') {
                return \in_array('-meta', $argv, true) ? [0, ''] : [0, "Pages:          {$pageCount}\n"];
            }
            if ($tool === 'pdftoppm') {
                file_put_contents((string) end($argv) . '.png', 'PNG');

                return [0, ''];
            }
            if ($tool === 'tesseract') {
                preg_match('/page-(\d+)\.png$/', $argv[1], $m);

                return [$tesseractExit, $pages[(int) $m[1] - 1] ?? ''];
            }

            return [1, ''];
        });
    }

    public function testAScanIsReadPageByPageWithItsMarkers(): void
    {
        $x = $this->extractor(2, ["A made-up scanned study of test lesions.\nGrade 3 was common.", "Second page of the scan, with 128 patients."])->extract('%PDF', 'pdf');

        self::assertTrue($x->ocr);
        self::assertTrue($x->hasText);
        self::assertSame(2, $x->pages);
        self::assertStringContainsString('Grade 3 was common.', $x->body);
        self::assertMatchesRegularExpression('/p\. 2.*Second page of the scan/s', $x->body, 'each page keeps its marker');
        $tesseract = array_values(array_filter($this->ran, static fn (array $a): bool => basename($a[0]) === 'tesseract'));
        self::assertCount(2, $tesseract);
        self::assertSame(['-l', 'ron+eng'], \array_slice($tesseract[0], 3, 2));
        self::assertSame([], glob($this->tmp . '/*') ?: [], 'the page images are gone');
    }

    public function testAScanTooLongIsRefusedWholeBeforeAnyPageIsRead(): void
    {
        try {
            $this->extractor(Extractor::OCR_MAX_PAGES + 1, [])->extract('%PDF', 'pdf');
            self::fail('refused');
        } catch (LibraryException $e) {
            self::assertSame(LibraryException::SCAN_TOO_LONG, $e->reason);
        }
        self::assertSame([], array_filter($this->ran, static fn (array $a): bool => basename($a[0]) === 'tesseract'));
    }

    public function testNoTesseractRefusesTheScanSayingWhatToInstall(): void
    {
        $this->expectExceptionObject(new LibraryException(LibraryException::NO_OCR));

        $this->extractor(1, ['x'], 127)->extract('%PDF', 'pdf');
    }

    public function testTheOcrGuardRefusesACnpEvenMisread(): void
    {
        $cnp = CnpTest::make(1, '850723');
        // Its 0 read as O, its 1 as l (the made-up CNP has a 0 at 4th place and a 1 at 10th)
        $misread = substr($cnp, 0, 3) . 'O' . substr($cnp, 4, 5) . 'l' . substr($cnp, 10);

        self::assertFalse(Library::holdsPatientData('Patient ' . $misread, false), 'outside OCR the checksum decides');
        self::assertTrue(Library::holdsPatientData('Patient ' . $misread, true));
        self::assertTrue(Library::holdsPatientData('CNP ' . substr($cnp, 0, 7) . ' ' . substr($cnp, 7), true), 'a space inside');
        self::assertTrue(Library::holdsPatientData('Patient ' . substr($cnp, 0, 12) . ((int) $cnp[12] + 1) % 10, true), 'a checksum broken by one digit');
        self::assertFalse(Library::holdsPatientData('Sample size 1234567890123 is no date, nor ISBN 9780306406157.', true));
        self::assertFalse(Library::holdsPatientData('Grade 3 in 128 of 2019 patients, p < 0.001.', true));
    }
}
