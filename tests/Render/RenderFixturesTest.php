<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Render;

use PHPUnit\Framework\TestCase;
use Reporion\Service\Render;

/**
 * The renderer on the spec-style fixtures and the real-report corpus
 * (tests/fixtures/render). There is one parser since 2026-10-15 (D17
 * amended: marked.js is gone), so nothing is compared with a second one: the
 * fixtures must render, never leak raw HTML or an unsafe URL, and the inputs
 * to the dialect that are not the markdown itself (the mount path, the
 * exam anchors) must hold.
 */
final class RenderFixturesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtureProvider(): iterable
    {
        $dir = \dirname(__DIR__, 2) . '/tests/fixtures/render';
        foreach (glob($dir . '/*.md') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function testEveryFixtureRendersWithoutRawHtmlOrUnsafeUrls(string $fixtureFile): void
    {
        $html = (new Render())->toHtml((string) file_get_contents($fixtureFile))->html;

        self::assertNotSame('', trim($html), basename($fixtureFile) . ' rendered nothing');
        self::assertDoesNotMatchRegularExpression('/<(script|iframe|object)\b/i', $html);
        self::assertDoesNotMatchRegularExpression('/(href|src)="\s*(javascript|vbscript|data):/i', $html);
    }

    public function testDefinitionListFixture(): void
    {
        $html = (new Render())->toHtml((string) file_get_contents(\dirname(__DIR__, 2) . '/tests/fixtures/render/definition-lists.md'))->html;

        self::assertSame(2, substr_count($html, '<dt>'));
        self::assertSame(3, substr_count($html, '<dd>'));
        self::assertStringContainsString('<strong>bold</strong>', $html);
    }

    /**
     * Links between pages resolve against where the app is mounted: the
     * one input to the dialect that is not the markdown itself.
     */
    public function testPageLinksResolveUnderAMountedBasePath(): void
    {
        $markdown = (string) file_get_contents(\dirname(__DIR__, 2) . '/tests/fixtures/render/internal-links.md');

        $html = (new Render())->toHtml($markdown, '/reporion')->html;

        self::assertStringContainsString('href="/reporion/reports:mri:mioveni:250101-test-subject#concluzie"', $html);
    }

    /**
     * A multi-exam report (phase 12): its top-level `##` are anchored
     * exam-1, exam-2…, never a ## in a quote or a list nor a setext `---`
     * heading, and a repeated exam title keeps its own number.
     */
    public function testExamAnchors(): void
    {
        $markdown = (string) file_get_contents(\dirname(__DIR__, 2) . '/tests/fixtures/render/multi-exam.md');

        $php = (new Render())->toHtml($markdown, '', false, null, true);

        self::assertStringContainsString('<h2 id="exam-3">IRM genunchi drept</h2>', $php->html);
        self::assertSame(['exam-1', 'exam-2', 'exam-3'], array_values(array_filter(array_column($php->toc, 'slug'), static fn (string $s): bool => str_starts_with($s, 'exam-'))));
        self::assertStringContainsString('<h2 id="un-paragraf-subliniat">', $php->html, 'a setext heading is no exam');
    }

    public function testASharedConclusionIsNoExamAnchor(): void
    {
        $php = (new Render())->toHtml("## A\n\nx\n\n## B\n\ny\n\n## Concluzii\n\nz\n", '', false, null, true);

        self::assertStringContainsString('<h2 id="exam-2">B</h2>', $php->html);
        self::assertStringContainsString('<h2 id="concluzii">Concluzii</h2>', $php->html);
        self::assertStringNotContainsString('exam-3', $php->html);
    }
}
