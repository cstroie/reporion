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

        self::assertStringContainsString('<h1>Titlu</h1>', $result->html);
        self::assertStringContainsString('<table>', $result->html);
        self::assertSame([], $result->warnings);
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
     * D17: no HTML passthrough — the dialect does not include raw HTML, so
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
}
