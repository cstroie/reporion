<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Groff\Ms\HtmlToMs;
use Reporion\Plugin\Loader;
use Reporion\Service\Markdown\CitationSource;
use Reporion\Service\OdtExport;
use Reporion\Service\PdfExport;
use Reporion\Service\Render;

/**
 * A cited page numbers its sources alike in every export (docs/library.md): the HTML the export
 * converters get carries the numbers and the References section, and each keeps both. The works
 * are made up.
 */
final class CitationExportsTest extends TestCase
{
    private string $html;

    protected function setUp(): void
    {
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'epub');
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'groff');
        $this->html = (new Render(new class implements CitationSource {
            public function record(string $key): ?array
            {
                return ['type' => 'article-journal', 'title' => 'Study ' . $key, 'author' => [['family' => 'Nobody', 'given' => 'A']], 'issued' => ['date-parts' => [[2020]]]];
            }

            public function path(string $key): string
            {
                return 'library:' . $key;
            }
        }))->toHtml('Text [@first2020] and [@second2020, p. 4].', unlinkPages: true, citeLinks: true, language: 'en')->html;
    }

    public function testThePrintedHtmlHasNoLinkIntoTheLibrary(): void
    {
        self::assertStringNotContainsString('library:', $this->html);
        self::assertStringContainsString('<li id="ref-first2020">Nobody A. Study first2020. 2020.</li>', $this->html);
    }

    public function testTheEpubKeepsTheNumbersTheListAndTheAnchors(): void
    {
        $xhtml = \Reporion\Plugin\Epub\Xhtml::fragment($this->html);

        self::assertStringContainsString('href="#ref-first2020">1</a>', $xhtml);
        self::assertStringContainsString('<li id="ref-second2020">', $xhtml);
        self::assertStringContainsString('>References</h2>', $xhtml);
    }

    public function testGroffKeepsTheNumbersAndTheList(): void
    {
        $ms = implode("\n", HtmlToMs::convert($this->html));

        self::assertStringContainsString('Text [1] and [2, p. 4].', $ms);
        self::assertStringContainsString('References', $ms);
        self::assertStringContainsString('Nobody A. Study second2020. 2020.', $ms);
    }

    public function testTheOdtAndThePdfKeepTheList(): void
    {
        $odt = (new OdtExport(sys_get_temp_dir()))->render('<html><body>' . $this->html . '</body></html>');
        self::assertStringStartsWith('PK', $odt);
        $pdf = (new PdfExport(sys_get_temp_dir()))->render('<html><body>' . $this->html . '</body></html>');
        self::assertStringStartsWith('%PDF', $pdf);

        if (trim((string) shell_exec('command -v pdftotext')) !== '') {
            $file = tempnam(sys_get_temp_dir(), 'cite') ?: '';
            file_put_contents($file, $pdf);
            $text = (string) shell_exec('pdftotext ' . escapeshellarg($file) . ' -');
            unlink($file);
            self::assertStringContainsString('Text [1] and [2, p. 4].', $text);
            self::assertStringContainsString('2. Nobody A. Study second2020. 2020.', $text);
        }
    }
}
