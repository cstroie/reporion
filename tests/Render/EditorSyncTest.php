<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Render;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The line/pixel arithmetic of the split preview's two-way sync
 * (assets/js/editor-sync.js), run in node through tools/run-editor-sync.js:
 * source line to the preview's pixel offset and back, and the same in the
 * text. The DOM glue is checked in a browser.
 */
final class EditorSyncTest extends TestCase
{
    /** a heading (1), a paragraph (3), a list (6) with its two items (6, 7), then the end (line 12 would be next) */
    private const BLOCKS = [
        ['line' => 1, 'top' => 0], ['line' => 3, 'top' => 40], ['line' => 6, 'top' => 100],
        ['line' => 6, 'top' => 100], ['line' => 7, 'top' => 130], ['line' => 12, 'top' => 260],
    ];
    private const TOPS = [0, 20, 40, 80, 100];

    protected function setUp(): void
    {
        exec('node --version 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            self::fail('node is required to run the editor sync test.');
        }
    }

    public function testASourceLineMapsToAPositionInThePreview(): void
    {
        $cases = array_map(static fn (float $line): array => ['fn' => 'yOfLine', 'args' => [self::BLOCKS, $line]], [0, 1, 2, 3, 4.5, 6, 6.5, 7, 9.5, 12, 99]);

        self::assertEquals([0, 0, 20, 40, 70, 100, 115, 130, 195, 260, 260], $this->node($cases));
    }

    public function testAPositionInThePreviewMapsBackToASourceLine(): void
    {
        $cases = array_map(static fn (float $y): array => ['fn' => 'lineAtY', 'args' => [self::BLOCKS, $y]], [0, 20, 40, 70, 100, 115, 195, 260, 500]);

        self::assertEquals([1, 2, 3, 4.5, 6, 6.5, 9.5, 12, 12], $this->node($cases));
    }

    public function testTheBlockAtALine(): void
    {
        $cases = array_map(static fn (float $line): array => ['fn' => 'blockAtLine', 'args' => [self::BLOCKS, $line]], [0.5, 1, 4, 6.5, 11, 12]);

        // the later of two blocks that start on the same line, the list item; the sentinel is never one
        self::assertEquals([-1, 0, 1, 3, 4, 4], $this->node($cases));
    }

    public function testTheTextMapsLinesAndPixelsBothWays(): void
    {
        $cases = [
            ['fn' => 'textLineAtY', 'args' => [self::TOPS, 0]],
            ['fn' => 'textLineAtY', 'args' => [self::TOPS, 10]],
            ['fn' => 'textLineAtY', 'args' => [self::TOPS, 60]],
            ['fn' => 'textYOfLine', 'args' => [self::TOPS, 0]],
            ['fn' => 'textYOfLine', 'args' => [self::TOPS, 2.5]],
            ['fn' => 'textYOfLine', 'args' => [self::TOPS, 9]],
        ];

        self::assertEquals([0, 0.5, 2.5, 0, 60, 100], $this->node($cases));
    }

    public function testNothingToMapIsTheTopOfTheDocument(): void
    {
        $cases = [
            ['fn' => 'yOfLine', 'args' => [[['line' => 1, 'top' => 0]], 5]],
            ['fn' => 'lineAtY', 'args' => [[['line' => 1, 'top' => 0]], 50]],
            ['fn' => 'textLineAtY', 'args' => [[0], 10]],
        ];

        self::assertEquals([0, 1, 0], $this->node($cases));
    }

    /**
     * @param list<array{fn: string, args: list<mixed>}> $cases
     *
     * @return list<mixed>
     */
    private function node(array $cases): array
    {
        $process = proc_open(
            ['node', \dirname(__DIR__, 2) . '/tools/run-editor-sync.js'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!\is_resource($process)) {
            throw new RuntimeException('Could not start node');
        }
        fwrite($pipes[0], (string) json_encode($cases));
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('editor-sync harness failed: ' . $err);
        }

        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }
}
