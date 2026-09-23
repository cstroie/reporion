<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Render;

use PHPUnit\Framework\TestCase;

final class HtmlNormalizerTest extends TestCase
{
    /**
     * The bug this guards against: an earlier version of the normaliser
     * deleted every newline-containing whitespace run outright, which also
     * deleted a CommonMark *soft line break* inside a paragraph's inline
     * content — silently joining two words into one. A normaliser that
     * lossy would make the conformance test pass even if both renderers
     * mishandled a soft break identically wrong.
     */
    public function testSoftLineBreakInsideAParagraphBecomesASpaceNotNothing(): void
    {
        $normalized = HtmlNormalizer::normalize("<p>primul\ncuvant</p>");

        self::assertSame('<p>primul cuvant</p>', $normalized);
    }

    public function testWhitespaceBetweenTwoTagsIsRemoved(): void
    {
        self::assertSame('<ul><li>a</li></ul>', HtmlNormalizer::normalize("<ul>\n<li>a</li>\n</ul>"));
    }

    public function testWhitespaceBeforeANestedBlockTagIsRemovedEvenAfterText(): void
    {
        $html = "<li>text\n<ul><li>nested</li></ul></li>";

        self::assertSame('<li>text<ul><li>nested</li></ul></li>', HtmlNormalizer::normalize($html));
    }

    public function testContentInsidePreIsNeverTouched(): void
    {
        $html = "<pre><code>line one\n\n  indented\nline two</code></pre>";

        self::assertSame($html, HtmlNormalizer::normalize($html));
    }

    public function testXhtmlAndHtml5VoidElementStylesAreEquivalent(): void
    {
        self::assertSame(
            HtmlNormalizer::normalize('<img src="a.png" alt="x">'),
            HtmlNormalizer::normalize('<img src="a.png" alt="x" />')
        );
    }
}
