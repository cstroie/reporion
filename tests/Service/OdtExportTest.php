<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use Reporion\Service\OdtExport;

/**
 * The ODT file is well-formed XML whatever the report says: "<5 mm" and
 * "&" are written escaped, not as they are (PHPWord's default).
 */
final class OdtExportTest extends TestCase
{
    public function testLessThanAndAmpersandAreEscapedInContentXml(): void
    {
        $content = self::contentXml((new OdtExport())->render('<html><body><div class="body"><p>leziune &lt;5 mm &amp; altele</p></div></body></html>'));

        self::assertStringContainsString('leziune &lt;5 mm &amp; altele', $content);
        $previous = libxml_use_internal_errors(true);
        self::assertTrue((new DOMDocument())->loadXML($content), 'content.xml is well-formed');
        libxml_use_internal_errors($previous);
    }

    /** content.xml, read with the PclZip PHPWord bundles (PHP may have no zip extension) */
    private static function contentXml(string $odt): string
    {
        require_once \dirname(__DIR__, 2) . '/vendor/phpoffice/phpword/src/PhpWord/Shared/PCLZip/pclzip.lib.php';
        $file = (string) tempnam(sys_get_temp_dir(), 'odt-test-');
        file_put_contents($file, $odt);
        try {
            $entries = (new \PclZip($file))->extract(PCLZIP_OPT_BY_NAME, 'content.xml', PCLZIP_OPT_EXTRACT_AS_STRING);
        } finally {
            unlink($file);
        }

        return \is_array($entries) ? (string) ($entries[0]['content'] ?? '') : '';
    }
}
