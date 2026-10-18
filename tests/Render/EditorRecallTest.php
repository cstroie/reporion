<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Render;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The editor's Recall panel (assets/js/editor-recall.js, D50), run in node
 * through tools/run-editor-recall.js: which line is asked about, which
 * lines are worth asking, and the edits Insert and Replace line make.
 */
final class EditorRecallTest extends TestCase
{
    protected function setUp(): void
    {
        exec('node --version 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            self::fail('node is required to run the editor recall test.');
        }
    }

    public function testTheLineAroundTheCursor(): void
    {
        $text = "### Descriere\n\nleziuni albe\nalt rând";

        self::assertSame([
            ['text' => 'leziuni albe', 'from' => 15, 'to' => 27],
            ['text' => 'leziuni albe', 'from' => 15, 'to' => 27],
            ['text' => 'alt rând', 'from' => 28, 'to' => 36],
            ['text' => '### Descriere', 'from' => 0, 'to' => 13],
        ], $this->node([
            ['fn' => 'lineAt', 'args' => [$text, 15]],
            ['fn' => 'lineAt', 'args' => [$text, 27]],
            ['fn' => 'lineAt', 'args' => [$text, 36]],
            ['fn' => 'lineAt', 'args' => [$text, 0]],
        ]));
    }

    public function testOnlyALineThatSaysSomethingIsAsked(): void
    {
        $lines = [
            'Multiple leziuni de substanță albă' => true,
            '- hiperintens T2' => true,
            'meningiom' => true,
            'chist' => false,
            'de la el' => false,
            '### Descriere de bază' => false,
            ';norm' => false,
            'modality: MR' => false,
            '' => false,
            '| col | col |' => false,
        ];
        $cases = array_map(static fn (string $line): array => ['fn' => 'askable', 'args' => [$line]], array_map('strval', array_keys($lines)));

        self::assertSame(array_values($lines), $this->node($cases));
    }

    public function testInsertGoesAfterTheLineAndKeepsAListMarker(): void
    {
        [$plain, $item] = $this->node([
            ['fn' => 'insertAfter', 'args' => ["unu\ndoi", 1, ' Pasaj. ']],
            ['fn' => 'insertAfter', 'args' => ["- leziuni\n- alta", 3, 'Fără efect de masă.']],
        ]);

        self::assertSame(['from' => 3, 'to' => 3, 'insert' => "\nPasaj.", 'selStart' => 10, 'selEnd' => 10], $plain);
        self::assertSame("\n- Fără efect de masă.", $item['insert']);
        self::assertSame(9, $item['from']);
    }

    public function testReplaceLineKeepsTheMarkerOnce(): void
    {
        [$edit] = $this->node([['fn' => 'replaceLine', 'args' => ["a\n- leziuni albe\nb", 5, "- Leziuni [număr] de substanță albă.\n"]]]);

        self::assertSame(['from' => 2, 'to' => 16, 'insert' => '- Leziuni [număr] de substanță albă.', 'selStart' => 38, 'selEnd' => 38], $edit);
    }

    /**
     * @param list<array{fn: string, args: list<mixed>}> $cases
     *
     * @return list<mixed>
     */
    private function node(array $cases): array
    {
        $process = proc_open(
            ['node', \dirname(__DIR__, 2) . '/tools/run-editor-recall.js'],
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
            throw new RuntimeException('editor-recall harness failed: ' . $err);
        }

        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }
}
