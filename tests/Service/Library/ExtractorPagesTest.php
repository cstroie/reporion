<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Library;

use PHPUnit\Framework\TestCase;
use Reporion\Exception\LibraryException;
use Reporion\Service\Ai\Redactor;
use Reporion\Service\Dossier\Dossier;
use Reporion\Service\Library\Extractor;

/** Extractor::readPages() (the Dossier, D64): a page's text layer, else OCR; and the OCR-tolerant redaction */
final class ExtractorPagesTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/reporion-pages-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tmp);
    }

    public function testAPageWithoutTextIsReadByOcrAndProgressIsSaid(): void
    {
        $ran = [];
        $runner = static function (array $argv, int $timeout, array $env) use (&$ran): array {
            $tool = basename($argv[0]);
            $ran[] = $tool;

            return match ($tool) {
                'pdfinfo' => [0, "Title: x\nPages: 2\n"],
                'pdftotext' => [0, $argv[\array_search('-f', $argv, true) + 1] === '1' ? 'Bilet de externare, pagina întâi, text tipărit.' : "  \n"],
                'pdftoppm' => [0, ''],
                'tesseract' => [0, 'Buletin de analize citit prin OCR'],
                default => [1, ''],
            };
        };
        $seen = [];
        $pages = (new Extractor($this->tmp, runner: $runner))->readPages('%PDF', 'pdf', 10, static function (int $n, int $count, bool $ocr) use (&$seen): void {
            $seen[] = [$n, $count, $ocr];
        });
        self::assertSame(['Bilet de externare, pagina întâi, text tipărit.', 'Buletin de analize citit prin OCR'], $pages);
        self::assertSame([[1, 2, false], [2, 2, true]], $seen);
        self::assertSame(['pdfinfo', 'pdftotext', 'pdftotext', 'pdftoppm', 'tesseract'], $ran);
        self::assertSame([], glob($this->tmp . '/*') ?: [], 'the temporary directory goes');
    }

    public function testAnImageIsReadByOcrAndOtherTypesAndLongFoldersAreRefused(): void
    {
        $runner = static fn (array $argv, int $timeout, array $env): array => match (basename($argv[0])) {
            'tesseract' => [0, 'scris'],
            'pdfinfo' => [0, "Pages: 99\n"],
            default => [0, ''],
        };
        $extractor = new Extractor($this->tmp, runner: $runner);
        self::assertSame(['scris'], $extractor->readPages('JPG', 'JPG', 10));
        try {
            $extractor->readPages('%PDF', 'pdf', 10);
            self::fail('too long');
        } catch (LibraryException $e) {
            self::assertSame(LibraryException::SCAN_TOO_LONG, $e->reason);
        }
        $this->expectException(LibraryException::class);
        $extractor->readPages('PK', 'docx', 10);
    }

    public function testNoTesseractIsNoOcr(): void
    {
        $runner = static fn (array $argv, int $timeout, array $env): array => match (basename($argv[0])) {
            'pdfinfo' => [0, "Pages: 1\n"],
            'tesseract' => [127, ''],
            default => [0, ''],
        };
        try {
            (new Extractor($this->tmp, runner: $runner))->readPages('%PDF', 'pdf', 10);
            self::fail('no OCR');
        } catch (LibraryException $e) {
            self::assertSame(LibraryException::NO_OCR, $e->reason);
        }
    }

    public function testOcrSlipsOfTheNameAreRedactedAndCaught(): void
    {
        $redactor = new Redactor();
        $redactor->learn(['patient' => ['name' => 'POPESCU Andreea', 'cnp' => '2600101000006']], 'reports:mri:x:261001-popescu-andreea');
        $text = $redactor->redactNear($redactor->redact('Pacienta P0PESCU Andreia, mama Popesou. Diagnostic: meduloblastom, popular.'));
        self::assertStringNotContainsStringIgnoringCase('pescu', $text);
        self::assertStringNotContainsString('Popesou', $text);
        self::assertStringNotContainsString('Andreia', $text);
        self::assertStringContainsString('meduloblastom', $text);
        self::assertStringContainsString('popular', $text, 'two edits away: a word');
        self::assertTrue($redactor->leaksNear('mama Popesou'));
        self::assertFalse($redactor->leaksNear('meduloblastom operat'));
    }

    public function testTheFolderIsKeptWithinItsBudget(): void
    {
        [$pages, $cut] = Dossier::within(['abc', str_repeat('x', 500), 'tail'], 400);
        self::assertTrue($cut);
        self::assertCount(2, $pages);
        self::assertSame(398, mb_strlen($pages[1]));
        self::assertSame([['a', 'b'], false], Dossier::within(['a', 'b'], 400));
    }
}
