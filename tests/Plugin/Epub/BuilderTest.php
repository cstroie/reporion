<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin\Epub;

use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Loader;
use Reporion\Plugin\Epub\Builder;
use Reporion\Support\Zip;

final class BuilderTest extends TestCase
{
    protected function setUp(): void
    {
        Loader::registerAutoload(\dirname(__DIR__, 3) . '/plugins', 'epub');
    }

    public function testThePackageListsEveryDocumentInTheSpineAndTheNav(): void
    {
        $epub = Builder::build(['title' => 'Book <1>', 'html' => '<p>f</p>'], [['title' => 'One', 'html' => '<p>1</p>'], ['title' => 'Two', 'html' => '<p>2</p>']], 'ro', Builder::identifier('01ABC'), new DateTimeImmutable('2026-10-29T10:00:00Z'), 'Contents');
        $entries = Zip::read($epub);

        self::assertSame('mimetype', array_key_first($entries));
        $opf = new DOMDocument();
        self::assertTrue($opf->loadXML($entries['OEBPS/content.opf']->bytes()));
        self::assertSame(3, $opf->getElementsByTagName('itemref')->length - 1);
        self::assertStringContainsString('2026-10-29T10:00:00Z', $entries['OEBPS/content.opf']->bytes());
        self::assertStringContainsString('Book &lt;1&gt;', $entries['OEBPS/nav.xhtml']->bytes());
        self::assertSame(Builder::identifier('01ABC'), Builder::identifier('01ABC'));
        self::assertMatchesRegularExpression('/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-a[0-9a-f]{3}-[0-9a-f]{12}$/', Builder::identifier('01ABC'));
    }
}
