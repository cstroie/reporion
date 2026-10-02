<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Render;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Checklist;
use Reporion\Support\Exams;
use RuntimeException;

/**
 * The editor's checklist (assets/js/editor-checklist.js, roadmap phase 26),
 * run in node through tools/run-editor-checklist.js: the browser folds,
 * matches and cuts exams by the same rule as Support\Checklist and
 * Support\Exams.
 */
final class EditorChecklistTest extends TestCase
{
    protected function setUp(): void
    {
        exec('node --version 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            self::fail('node is required to run the editor checklist test.');
        }
    }

    public function testTheBrowserFoldsAndMatchesLikeTheServer(): void
    {
        $texts = ['Meniscul MEDIAL  intact', 'Fără revărsat; ţesut şi țesut', 'Ligamentul încrucișat anterior', "Tab\tand\nnewline", ''];
        $keywords = [['menisc medial'], ['revarsat'], ['tesut'], ['incrucisat', 'lia'], [''], []];
        $cases = [];
        $expected = [];
        foreach ($texts as $text) {
            $cases[] = ['fn' => 'fold', 'args' => [$text]];
            $expected[] = Checklist::fold($text);
            foreach ($keywords as $words) {
                $cases[] = ['fn' => 'mentioned', 'args' => [$words, $text]];
                $expected[] = Checklist::mentioned($words, $text);
            }
        }

        self::assertSame($expected, $this->node($cases));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodies(): iterable
    {
        yield 'two exams' => ["# N\n\n**Gonalgie**\n\n## IRM genunchi drept\n\nA.\n\n### Concluzii\n\nB.\n\n## IRM genunchi stâng\n\nC.\n"];
        yield 'code' => ["# N\n\n## A\n\n```\n## not an exam\n```\n\n~~~~\n## x\n~~~\n## still code\n~~~~\n## B ##\n"];
        yield 'no exams' => ["# N\n\nText.\n"];
    }

    /**
     * @dataProvider bodies
     */
    public function testTheBrowserCutsExamsWhereTheServerDoes(string $body): void
    {
        $parts = array_column(Exams::split($body)['parts'], 'text');

        self::assertSame([$parts === [] ? [$body] : $parts], $this->node([['fn' => 'examTexts', 'args' => [$body]]]));
    }

    /**
     * @param list<array{fn: string, args: list<mixed>}> $cases
     *
     * @return list<mixed>
     */
    private function node(array $cases): array
    {
        $process = proc_open(
            ['node', \dirname(__DIR__, 2) . '/tools/run-editor-checklist.js'],
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
            throw new RuntimeException('editor-checklist harness failed: ' . $err);
        }

        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }
}
