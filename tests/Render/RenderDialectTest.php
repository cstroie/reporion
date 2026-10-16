<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Render;

use PHPUnit\Framework\TestCase;
use Reporion\Service\Render;

/** The dialect additions of Service\Markdown and the library extensions enabled with them */
final class RenderDialectTest extends TestCase
{
    private static function html(string $markdown): string
    {
        return (new Render())->toHtml($markdown)->html;
    }

    public function testSuperscriptAndSubscript(): void
    {
        $html = self::html('12 cm^2^ and H~2~O');

        self::assertStringContainsString('cm<sup>2</sup>', $html);
        self::assertStringContainsString('H<sub>2</sub>O', $html);
    }

    public function testTildeAndCaretStayAsTypedWhenTheyAreNotAScript(): void
    {
        foreach (['approx ~5 mm and ~6 mm', '~~not struck~~', 'a ^b c^ d', 'x^2 and y^3', 'lone ^ caret'] as $text) {
            $html = self::html($text);
            self::assertDoesNotMatchRegularExpression('/<su[bp]>|<del>/', $html, $text);
            self::assertStringContainsString(str_replace('&', '&amp;', $text), $html);
        }
    }

    public function testHighlightAutolinkAndTaskList(): void
    {
        $html = self::html("==finding== see https://example.org/x\n\n- [ ] open\n- [x] done\n");

        self::assertStringContainsString('<mark>finding</mark>', $html);
        self::assertStringContainsString('<a href="https://example.org/x">https://example.org/x</a>', $html);
        self::assertStringContainsString('<span class="wk-task">☐</span> open', $html);
        self::assertStringContainsString('<span class="wk-task wk-task-done">☑</span> done', $html);
        self::assertStringNotContainsString('<input', $html);
    }

    public function testAutolinkNeverMakesAnUnsafeLink(): void
    {
        self::assertDoesNotMatchRegularExpression('/href="\s*javascript:/i', self::html('javascript:alert(1) and <javascript:alert(1)>'));
    }

    public function testAbbreviationsAreWholeWordsOutsideLinksAndCode(): void
    {
        $html = self::html("MRI and MRIs, `MRI`, [MRI](https://example.org)\n\n*[MRI]: Magnetic resonance imaging\n");

        self::assertSame(1, substr_count($html, '<abbr title="Magnetic resonance imaging">MRI</abbr>'));
        self::assertStringContainsString('MRIs', $html);
        self::assertStringNotContainsString('*[MRI]', $html);
    }

    public function testAbbreviationTitleIsEscaped(): void
    {
        $html = self::html("RM\n\n*[RM]: a \"b\" <i>c</i>\n");

        self::assertStringContainsString('title="a &quot;b&quot; &lt;i&gt;c&lt;/i&gt;"', $html);
    }

    public function testAdmonitionHoldsBlocksAndTakesATitle(): void
    {
        $html = self::html("::: warning Allergy\nPrior **reaction**.\n\n- one\n:::\n\nafter\n");

        self::assertStringContainsString('<div class="wk-admonition wk-admonition-warning">', $html);
        self::assertStringContainsString('<p class="wk-admonition-title">Allergy</p>', $html);
        self::assertStringContainsString('<strong>reaction</strong>', $html);
        self::assertStringContainsString('<li>one</li>', $html);
        self::assertMatchesRegularExpression('#</div>\s*<p>after</p>#', $html);
    }

    public function testAnUnknownAdmonitionKindIsPlainText(): void
    {
        self::assertStringNotContainsString('wk-admonition', self::html("::: nonsense\ntext\n:::\n"));
    }

    public function testTableCaptionAndColumnSpan(): void
    {
        $html = self::html("| a | b | c |\n|---|---|---|\n| 1 | << | 3 |\n| x |  | z |\n\nTable: Sizes\n");

        self::assertStringContainsString('<caption>Sizes</caption>', $html);
        self::assertStringContainsString('<td colspan="2">1</td>', $html);
        // An empty cell stays a cell
        self::assertStringContainsString('<td></td>', $html);
        self::assertStringNotContainsString('Table: Sizes</p>', $html);
    }

    public function testASpanMarkerInTheFirstCellStaysAsTyped(): void
    {
        self::assertStringContainsString('&lt;&lt;', self::html("| a | b |\n|---|---|\n| << | 2 |\n"));
    }

    public function testEditorPreviewKeepsLineNumbersAcrossTheNewBlocks(): void
    {
        $html = (new Render())->toHtml("*[MRI]: Magnetic resonance imaging\n\n::: note\nx\n:::\n\npara MRI\n", '', false, null, false, true)->html;

        self::assertStringContainsString('<div class="wk-admonition wk-admonition-note" data-line="3">', $html);
        self::assertStringContainsString('<p data-line="7">', $html);
    }
}
