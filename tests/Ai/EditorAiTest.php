<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The rail's text transforms (assets/js/editor-ai.js, phase 15d), in node:
 * what each result mode does to the text in front, never to the frontmatter.
 */
final class EditorAiTest extends TestCase
{
    private const DOC = "---\ntitle: T\n---\n\n";

    protected function setUp(): void
    {
        exec('node --version 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            self::fail('node is required to run the editor AI test.');
        }
    }

    public function testAppendReplacesTheSameSectionElseAddsAtTheEnd(): void
    {
        $exam = "## IRM genunchi\n\nMenisc fisurat.\n\n### Concluzii\n\nVeche.\n\n### Recomandări\n\nR.\n";
        [$merged, $added] = $this->node([
            ['fn' => 'apply', 'args' => ['append', $exam, 0, 0, "### CONCLUZII\n\nFisură meniscală medială."]],
            ['fn' => 'apply', 'args' => ['append', "## IRM genunchi\n\nMenisc fisurat.", 0, 0, "```\n### Concluzii\nNouă.\n```"]],
        ]);

        self::assertSame("## IRM genunchi\n\nMenisc fisurat.\n\n### CONCLUZII\n\nFisură meniscală medială.\n\n### Recomandări\n\nR.\n", $merged['text'], 'the old conclusion replaced, the next section kept');
        self::assertSame("## IRM genunchi\n\nMenisc fisurat.\n\n### Concluzii\nNouă.\n", $added['text'], 'added at the end, the code fence taken off');
    }

    public function testReplaceTakesTheSelectionElseTheTextBelowTheHeadings(): void
    {
        $doc = self::DOC . "# N\n\n## IRM cerebral\n\nText vechi.\n";
        [$selection, $body, $whole] = $this->node([
            ['fn' => 'apply', 'args' => ['replace', 'a b c', 2, 3, 'B']],
            ['fn' => 'apply', 'args' => ['replace', $doc, 0, 0, 'Text nou.']],
            ['fn' => 'apply', 'args' => ['replace', "## IRM\n\nVechi.\n\n### Concluzii\n\nV.\n", 0, 0, "## IRM\n\nNou.\n"]],
        ]);
        self::assertSame("## IRM\n\nNou.\n", $whole['text'], 'an answer with its own heading replaces all of the pane');

        self::assertSame('a B c', $selection['text']);
        self::assertSame(self::DOC . "# N\n\n## IRM cerebral\n\nText nou.\n", $body['text'], 'the frontmatter and the headings stay');
    }

    public function testInsertIsAParagraphAtTheStartAndShowWritesNothing(): void
    {
        [$insert, $show, $framed] = $this->node([
            ['fn' => 'apply', 'args' => ['insert', "Unu.\nDoi.", 4, 4, 'Nou.']],
            ['fn' => 'apply', 'args' => ['show', 'x', 0, 0, 'y']],
            ['fn' => 'apply', 'args' => ['insert', self::DOC . "Unu.\n", 20, 20, 'Nou.']],
        ]);

        self::assertSame("Nou.\n\nUnu.\nDoi.", $insert['text'], 'at the start, wherever the cursor was');
        self::assertSame(self::DOC . "Nou.\n\nUnu.\n", $framed['text'], 'below the frontmatter');
        [$pane] = $this->node([['fn' => 'apply', 'args' => ['insert', "## IRM\n\nUnu.\n", 0, 0, 'Nou.']]]);
        self::assertSame("## IRM\n\nNou.\n\nUnu.\n", $pane['text'], 'an exam pane keeps its heading first');
        self::assertNull($show['result']);
    }

    public function testServerSentEventsAreParsedAsTheyArrive(): void
    {
        [$parsed] = $this->node([['fn' => 'parseEvents', 'args' => ["event: delta\ndata: {\"text\":\"Con\"}\n\nevent: delta\ndata: {\"text\":\"cluzie\"}\n\nevent: do"]]]);

        self::assertSame([['event' => 'delta', 'data' => ['text' => 'Con']], ['event' => 'delta', 'data' => ['text' => 'cluzie']]], $parsed['result']['events']);
        self::assertSame('event: do', $parsed['result']['rest'], 'an incomplete event waits for the rest');
    }

    /**
     * @param list<array{fn: string, args: list<mixed>}> $cases
     *
     * @return list<array{result: mixed, text: ?string}>
     */
    private function node(array $cases): array
    {
        $process = proc_open(['node', \dirname(__DIR__, 2) . '/tools/run-editor-ai.js'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
            throw new RuntimeException('editor-ai harness failed: ' . $err);
        }

        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }
}
