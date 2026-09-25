<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Render;

use PHPUnit\Framework\TestCase;
use Reporion\Service\Render;
use RuntimeException;

/**
 * D17 / CLAUDE.md invariant 4: two parsers, one dialect. This renders every
 * fixture through Reporion\Service\Render (PHP, canonical) and through
 * marked.js (the editor preview, tools/render-with-marked.js) and asserts
 * the normalised HTML matches. Adding a syntax extension means adding a
 * fixture here and making it pass in both, or not adding it.
 *
 * The dialect excludes raw HTML (no passthrough) and unsafe URLs. Both
 * parsers must still agree on how they neutralise them — PHP escapes raw
 * HTML and drops javascript:/data: URLs, and assets/js/markdown-preview.js
 * configures marked to do the same — because a page another editor wrote
 * is previewed in this browser. raw-html-and-unsafe-urls.md pins that down.
 * tools/render-with-marked.js loads that same preview configuration.
 */
final class RenderConformanceTest extends TestCase
{
    protected function setUp(): void
    {
        // A skipped test is a green test. CLAUDE.md invariant 4 requires
        // this suite to "stay green: both parsers, same normalised HTML" —
        // a run where the marked.js half never executed must not report OK,
        // it must fail loudly, since node is a declared devDependency
        // (package.json), not an optional extra.
        exec('node --version 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            self::fail('node is required to run the render conformance test. Install it, then `npm install`.');
        }
        if (!is_dir(\dirname(__DIR__, 2) . '/node_modules/marked')) {
            self::fail('The marked.js devDependency is not installed. Run `npm install`.');
        }
    }

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
    public function testPhpAndMarkedAgreeOnNormalisedHtml(string $fixtureFile): void
    {
        $markdown = (string) file_get_contents($fixtureFile);

        $phpHtml = (new Render())->toHtml($markdown)->html;
        $markedHtml = $this->renderWithMarked($markdown);

        self::assertSame(
            HtmlNormalizer::normalize($markedHtml),
            HtmlNormalizer::normalize($phpHtml),
            'PHP and marked.js disagree on ' . basename($fixtureFile)
        );
    }

    private function renderWithMarked(string $markdown): string
    {
        $script = \dirname(__DIR__, 2) . '/tools/render-with-marked.js';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open(['node', $script], $descriptors, $pipes, \dirname(__DIR__, 2));
        if (!\is_resource($process)) {
            throw new RuntimeException('Cannot start the marked.js render harness');
        }

        fwrite($pipes[0], $markdown);
        fclose($pipes[0]);
        $html = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException('marked.js render harness failed: ' . $stderr);
        }

        return $html;
    }
}
