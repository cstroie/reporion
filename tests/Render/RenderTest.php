<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Render;

use PHPUnit\Framework\TestCase;
use Reporion\Service\Render;

final class RenderTest extends TestCase
{
    public function testProducesHeadingsParagraphsAndTables(): void
    {
        $result = (new Render())->toHtml("# Titlu\n\nText.\n\n| a | b |\n|---|---|\n| 1 | 2 |\n");

        self::assertStringContainsString('<h1 id="titlu">Titlu</h1>', $result->html, 'with the anchor the table of contents links to');
        self::assertStringContainsString('<table>', $result->html);
        self::assertSame([], $result->warnings);
    }

    public function testDefinitionListsRenderAsDlDtDd(): void
    {
        $result = (new Render())->toHtml("Term\n: First meaning\n: Second meaning\n\nOther\n: Another\n");

        self::assertStringContainsString('<dl>', $result->html);
        self::assertStringContainsString('<dt>Term</dt>', $result->html);
        self::assertStringContainsString('<dd>First meaning</dd>', $result->html);
        self::assertStringContainsString('<dd>Second meaning</dd>', $result->html);
        self::assertStringContainsString('<dt>Other</dt>', $result->html);
    }

    public function testLinesAreMarkedOnlyWhenAsked(): void
    {
        $markdown = "# T\n\nPara\ncontinues.\n\n- a\n- b\n\nTerm\n: def\n\n| x |\n|---|\n| 1 |\n\n## Exam\n";

        $plain = (new Render())->toHtml($markdown)->html;
        $lined = (new Render())->toHtml($markdown, '', false, null, true, true)->html;

        self::assertStringNotContainsString('data-line', $plain);
        self::assertStringContainsString('<h1 data-line="1" id="t">', $lined);
        self::assertStringContainsString('<p data-line="3">', $lined);
        self::assertStringContainsString('<ul data-line="6">', $lined);
        self::assertStringContainsString('<li data-line="7">b</li>', $lined, 'a list item has its own line');
        self::assertStringContainsString('<dl data-line="9">', $lined);
        self::assertStringContainsString('<table data-line="12">', $lined);
        self::assertStringContainsString('<h2 data-line="16" id="exam-1">', $lined);
    }

    public function testTocListsHeadingsInDocumentOrderWithSlugs(): void
    {
        $result = (new Render())->toHtml("# Indicatie\n\ntext\n\n## Concluzie\n\ntext\n");

        self::assertSame(
            [
                ['level' => 1, 'text' => 'Indicatie', 'slug' => 'indicatie'],
                ['level' => 2, 'text' => 'Concluzie', 'slug' => 'concluzie'],
            ],
            $result->toc
        );
    }

    public function testDuplicateHeadingTextGetsDistinctSlugs(): void
    {
        $result = (new Render())->toHtml("# Concluzie\n\ntext\n\n# Concluzie\n\ntext\n");

        self::assertSame(['concluzie', 'concluzie-2'], array_column($result->toc, 'slug'));
    }

    /**
     * D17: no HTML passthrough: the dialect does not include raw HTML, so
     * it must render as inert text, never as a live tag, and the caller
     * must be told (POST /render's "warnings").
     */
    public function testRawHtmlIsEscapedNotInterpretedAndWarns(): void
    {
        $result = (new Render())->toHtml("Text cu <script>alert(1)</script> in el.\n");

        self::assertStringNotContainsString('<script>alert(1)</script>', $result->html);
        self::assertStringContainsString('&lt;script&gt;', $result->html);
        self::assertNotSame([], $result->warnings);
    }

    public function testUnsafeLinkSchemeIsNotAllowed(): void
    {
        $result = (new Render())->toHtml('[click](javascript:alert(1))');

        self::assertStringNotContainsString('javascript:', $result->html);
    }

    public function testPageLinksResolveAgainstTheBasePath(): void
    {
        $html = (new Render())->toHtml('[prior](reports:mri:a#c) [old](reports/mri/b) [web](https://example.org)', '/app')->html;

        self::assertStringContainsString('<a href="/app/reports:mri:a#c">prior</a>', $html);
        self::assertStringContainsString('<a href="/app/reports:mri:b">old</a>', $html);
        self::assertStringContainsString('<a href="https://example.org">web</a>', $html);
    }

    /** Print and export: the address of another page would carry its (patient) path: invariant 8 */
    public function testUnlinkingKeepsTheTextOfPageLinksOnly(): void
    {
        $html = (new Render())->toHtml('See [the **prior** study](reports:mri:260101-x) and [web](https://example.org).', unlinkPages: true)->html;

        self::assertSame('<p>See the <strong>prior</strong> study and <a href="https://example.org">web</a>.</p>' . "\n", $html);
        self::assertStringNotContainsString('260101', $html);
    }

    /** The bug this guards against: the table of contents linked to #slug anchors no heading carried */
    public function testEveryTocEntryHasItsAnchorOnTheHeading(): void
    {
        $result = (new Render())->toHtml("## Tehnică\n\n## Concluzie\n\n### Concluzie\n\n## !!!\n");

        self::assertSame(['tehnica', 'concluzie', 'concluzie-2', 'section'], array_column($result->toc, 'slug'));
        foreach ($result->toc as $entry) {
            self::assertStringContainsString(' id="' . $entry['slug'] . '"', $result->html);
        }
    }
}
