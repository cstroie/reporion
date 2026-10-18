<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Render;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Measurements;
use Reporion\Tests\Support\MeasurementsTest;
use RuntimeException;

/**
 * The editor's writing aids (assets/js/editor-aids.js, D51), run in node
 * through tools/run-editor-aids.js: the section the cursor is in, which
 * prior section pairs with it, the sizes a line states (the same as
 * Support\Measurements), the prior's passage for a size, and the edit
 * Insert comparison makes.
 */
final class EditorAidsTest extends TestCase
{
    protected function setUp(): void
    {
        exec('node --version 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            self::fail('node is required to run the editor aids test.');
        }
    }

    public function testTheBrowserReadsSizesLikeTheServer(): void
    {
        $cases = [];
        $expected = [];
        foreach (MeasurementsTest::cases() as [$text]) {
            $cases[] = ['fn' => 'measurements', 'args' => [$text]];
            $expected[] = Measurements::of($text);
        }

        self::assertEquals($expected, $this->node($cases));
    }

    public function testTheSectionTheCursorIsIn(): void
    {
        $text = "## IRM\n\n### Descriere\n\nText.\n\n### Concluzii\n\nC.";

        self::assertSame(['', 'Descriere', 'Concluzii'], $this->node([
            ['fn' => 'sectionAt', 'args' => [$text, 3]],
            ['fn' => 'sectionAt', 'args' => [$text, 25]],
            ['fn' => 'sectionAt', 'args' => [$text, \strlen($text)]],
        ]));
    }

    public function testAHeadingPairsByNameThenByItsFirstWord(): void
    {
        $sections = [['heading' => 'Descriere'], ['heading' => 'Concluzie:']];

        self::assertSame([0, 1, -1, -1], $this->node([
            ['fn' => 'pairHeading', 'args' => ['descriere', $sections]],
            ['fn' => 'pairHeading', 'args' => ['Concluzii', $sections]],
            ['fn' => 'pairHeading', 'args' => ['Tehnică', $sections]],
            ['fn' => 'pairHeading', 'args' => ['', $sections]],
        ]));
    }

    public function testThePriorsExamByTitleThenPlace(): void
    {
        $exams = [['title' => 'IRM genunchi drept'], ['title' => 'IRM genunchi stâng']];

        self::assertSame([1, 1, 0, -1], $this->node([
            ['fn' => 'pickExam', 'args' => [$exams, 'IRM Genunchi Stang', 0]],
            ['fn' => 'pickExam', 'args' => [$exams, 'Altceva', 1]],
            ['fn' => 'pickExam', 'args' => [$exams, '', 5]],
            ['fn' => 'pickExam', 'args' => [[], 'x', 0]],
        ]));
    }

    public function testAPriorPassageForASizeSharingWords(): void
    {
        $sections = [
            ['heading' => 'Descriere', 'passages' => [
                ['text' => 'Ventricule normale, 10 mm.', 'sizes' => [['text' => '10 mm']]],
                ['text' => 'Leziune frontală de 8 mm.', 'sizes' => [['text' => '8 mm']]],
                ['text' => 'Leziune fără dimensiuni.', 'sizes' => []],
            ]],
        ];

        [$found, $noSize, $noMatch] = $this->node([
            ['fn' => 'priorFor', 'args' => ['Leziunea frontală are 10 mm.', $sections]],
            ['fn' => 'priorFor', 'args' => ['Leziunea frontală.', $sections]],
            ['fn' => 'priorFor', 'args' => ['Chist renal de 4 mm.', $sections]],
        ]);

        self::assertSame('Leziune frontală de 8 mm.', $found['text']);
        self::assertSame('8 mm', $found['size']);
        self::assertNull($noSize, 'no size on the line');
        self::assertNull($noMatch, 'no shared word');
    }

    public function testTheComparisonGoesBeforeTheFullStop(): void
    {
        [$stop, $none] = $this->node([
            ['fn' => 'compareEdit', 'args' => ["a\nLeziune de 10 mm.\nb", 5, 'comparativ cu 8 mm']],
            ['fn' => 'compareEdit', 'args' => ['Leziune de 10 mm', 3, 'comparativ cu 8 mm']],
        ]);

        self::assertSame(['from' => 18, 'to' => 18, 'insert' => ', comparativ cu 8 mm', 'selStart' => 39, 'selEnd' => 39], $stop);
        self::assertSame(16, $none['from']);
    }

    /**
     * @param list<array{fn: string, args: list<mixed>}> $cases
     *
     * @return list<mixed>
     */
    private function node(array $cases): array
    {
        $process = proc_open(
            ['node', \dirname(__DIR__, 2) . '/tools/run-editor-aids.js'],
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
            throw new RuntimeException('editor-aids harness failed: ' . $err);
        }

        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }
}
