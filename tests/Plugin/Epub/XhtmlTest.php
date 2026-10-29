<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin\Epub;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Loader;
use Reporion\Plugin\Epub\Xhtml;

final class XhtmlTest extends TestCase
{
    protected function setUp(): void
    {
        Loader::registerAutoload(\dirname(__DIR__, 3) . '/plugins', 'epub');
    }

    public function testHtmlBecomesWellFormedXml(): void
    {
        $out = Xhtml::fragment('<p>a&nbsp;b<br>ă ș ț &amp; <b>x</p><dl><dt>t<dd>d</dl><table><tr><td></td></tr></table>');

        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML('<div>' . $out . '</div>'));
        self::assertStringContainsString('ă ș ț &amp;', $out);
    }

    public function testMediaScriptsHandlersAndPageLinksAreGone(): void
    {
        $out = Xhtml::fragment('<p onclick="x()" style="color:red">t <img src="media:a.png"><script>alert(1)</script><a href="docs:page">page</a> <a href="https://example.org/">web</a> <a href="#n">n</a></p>');

        self::assertSame('<p>t page <a href="https://example.org/">web</a> <a href="#n">n</a></p>', $out);
    }
}
