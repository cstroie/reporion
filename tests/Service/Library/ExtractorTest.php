<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Library;

use Dompdf\Dompdf;
use PHPUnit\Framework\TestCase;
use Reporion\Exception\LibraryException;
use Reporion\Service\Library\Extractor;

/** The documents are made in the test (docs/library.md: never a real paper in the repository) */
final class ExtractorTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/reporion-extractor-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tmp);
    }

    public function testAPdfGivesItsTextPagesAndPageOnesLayout(): void
    {
        if ((new Extractor($this->tmp))->check()['pdftotext'] !== true) {
            self::markTestSkipped('poppler-utils is not installed');
        }
        $pdf = new Dompdf();
        $pdf->loadHtml('<h1 style="font-size:24pt">A made-up study of test lesions</h1><p>Ann Nobody, Bob Nobody</p>'
            . '<p>doi:10.1234/jti.2024.0001</p><div style="page-break-before:always">Second page text.</div>');
        $pdf->render();

        $x = (new Extractor($this->tmp))->extract((string) $pdf->output(), 'pdf');

        self::assertSame(2, $x->pages);
        self::assertTrue($x->hasText);
        self::assertStringContainsString('<a id="p2"></a>', $x->body);
        self::assertStringContainsString('Second page text.', $x->body);
        self::assertStringContainsString('10.1234/jti.2024.0001', $x->head);
        self::assertSame('A made-up study of test lesions', $x->lines[0]['text']);
        self::assertGreaterThan($x->lines[1]['size'], $x->lines[0]['size']);
        self::assertSame([], glob($this->tmp . '/*'), 'the private temporary directory is removed');
    }

    public function testATextFileInWindows1250IsReadAsUtf8(): void
    {
        $x = (new Extractor($this->tmp))->extract((string) iconv('UTF-8', 'Windows-1250', "Leziuni în săptămâna\nîntâi.\n"), 'txt');

        self::assertStringContainsString('Leziuni în săptămâna întâi.', $x->body);
        self::assertSame(1, $x->pages);
    }

    public function testMarkdownIsTakenAsItIs(): void
    {
        self::assertSame("# Title\n\nText.\n", (new Extractor($this->tmp))->extract("# Title\n\nText.\n", 'md')->body);
    }

    public function testAnUnknownTypeIsRefused(): void
    {
        $this->expectExceptionObject(new LibraryException(LibraryException::UNSUPPORTED));
        (new Extractor($this->tmp))->extract('MZ', 'exe');
    }

    public function testAToolThatCannotRunIsToolMissingAndTheTemporaryDirectoryGoes(): void
    {
        $extractor = new Extractor($this->tmp, runner: static fn (array $argv, int $timeout, array $env): array => [-1, '']);
        try {
            $extractor->extract('%PDF-1.4', 'pdf');
            self::fail('no exception');
        } catch (LibraryException $e) {
            self::assertSame(LibraryException::TOOL_MISSING, $e->reason);
        }
        self::assertSame([], glob($this->tmp . '/*'));
    }

    public function testATimeoutIsSaidSo(): void
    {
        $extractor = new Extractor($this->tmp, runner: static fn (array $argv, int $timeout, array $env): array => [-2, '']);
        $this->expectExceptionObject(new LibraryException(LibraryException::TIMEOUT));
        $extractor->extract('PK', 'docx');
    }

    public function testExtensionsAreKnownCaseFolded(): void
    {
        self::assertSame('pdf', Extractor::extensionOf('Paper.PDF'));
        self::assertNull(Extractor::extensionOf('virus.exe'));
        self::assertNull(Extractor::extensionOf('noextension'));
    }
}
