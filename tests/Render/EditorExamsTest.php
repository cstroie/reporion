<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Render;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Exams;
use RuntimeException;

/**
 * The editor's exam tabs (assets/js/editor-exams.js, phase 12; normal
 * edit's since 2026-10-07), run in node through tools/run-editor-exams.js:
 * the JS cuts a body exactly where Support\Exams does, and putting the
 * panes back together gives the same body the server reads.
 */
final class EditorExamsTest extends TestCase
{
    private const FM = [
        'title' => 'TEST Patient Unu', 'exam_title' => 'IRM genunchi drept + IRM genunchi stâng', 'visibility' => 'private',
        'modality' => ['MR'], 'region' => ['msk'],
        'exams' => [
            ['title' => 'IRM genunchi drept', 'region' => ['msk'], 'accession' => 'MV-MR-26-0001', 'study_uid' => '1.2.826.1.1', 'pacs_accession' => '1088159', 'template' => 'templates:mri:genunchi'],
            ['title' => "IRM genunchi stâng 'bis'", 'region' => ['msk', 'spine'], 'accession' => 'MV-MR-26-0002'],
        ],
        'patient' => ['name' => 'TEST Patient Unu'],
    ];
    private const BODY = "# TEST Patient Unu\n\n**Gonalgie**\n\n## IRM genunchi drept\n\nA.\n\n### Concluzii\n\nB.\n\n## IRM genunchi stâng 'bis'\n\nC.\n\n### Concluzii\n\nD.\n";

    protected function setUp(): void
    {
        exec('node --version 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            self::fail('node is required to run the editor exams test.');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodies(): iterable
    {
        yield 'a report' => [self::BODY];
        yield 'code and indented code' => ["# N\n\n## A\n\n```\n## not an exam\n```\n\n    ## indented code\n\n~~~~\n## x\n~~~\n## still code\n~~~~\n## B ##\n"];
        yield 'an empty heading and ###' => ["## \n### Sub\n##\n####### no\n##x\n"];
        yield 'no exams' => ["# N\n\nText.\n"];
    }

    /**
     * @dataProvider bodies
     */
    public function testTheBrowserCutsABodyWhereTheServerDoes(string $body): void
    {
        [$js] = $this->node([['fn' => 'splitBody', 'args' => [$body]]]);

        self::assertSame(Exams::split($body), $js);
    }

    public function testThePanesPutBackGiveTheSameBody(): void
    {
        [$state] = $this->node([['fn' => 'openBody', 'args' => [self::BODY, 2]]]);

        self::assertSame("# TEST Patient Unu\n\n**Gonalgie**\n\n", $state['head'], 'the shared text above the first exam');
        self::assertCount(2, $state['parts']);
        self::assertStringStartsWith("## IRM genunchi stâng 'bis'\n", $state['parts'][1]);

        [$joined] = $this->node([['fn' => 'joinBody', 'args' => [$state]]]);
        self::assertSame(self::BODY, $joined);
        self::assertSame([], Exams::problems(self::FM, $joined));
    }

    public function testCardsAndSectionsThatDisagreeStayOneTextarea(): void
    {
        [$tooMany, $tooFew, $single] = $this->node([
            ['fn' => 'openBody', 'args' => [self::BODY, 3]],
            ['fn' => 'openBody', 'args' => [self::BODY, 1]],
            ['fn' => 'openBody', 'args' => ["# N\n\n## IRM cerebral\n\nA.\n", 1]],
        ]);

        self::assertNull($tooMany, 'a third card with two ## would have no tab for its text');
        self::assertNull($tooFew, 'one card is a single-exam report: one textarea, whatever its headings');
        self::assertNull($single);
    }

    public function testEveryHeadingGetsABlankLineBeforeIt(): void
    {
        [$joined, $spaced] = $this->node([
            ['fn' => 'joinBody', 'args' => [['head' => "# TEST Patient Unu\n\n**Gonalgie**\n\n", 'parts' => [
                "## IRM genunchi drept\nA.\n### Concluzii\nB.",
                "## IRM genunchi stâng 'bis'\n\nC.\n```\nx\n### not a heading\n```\n### Concluzii\n\nD.\n",
            ]]]],
            ['fn' => 'spaceHeadings', 'args' => ["# N\n## A\n\n### B\nText ## not a heading\n#hashtag\n    ## indented code\n"]],
        ]);

        self::assertSame("# TEST Patient Unu\n\n**Gonalgie**\n\n## IRM genunchi drept\nA.\n\n### Concluzii\nB.\n\n## IRM genunchi stâng 'bis'\n\nC.\n```\nx\n### not a heading\n```\n\n### Concluzii\n\nD.\n", $joined, 'an exam without a final newline, a ### under text; code left alone');
        self::assertSame("# N\n\n## A\n\n### B\nText ## not a heading\n#hashtag\n    ## indented code\n", $spaced, 'already spaced, not a heading: unchanged');
    }

    public function testATemplateInsertedIntoAnExamGoesOneLevelDown(): void
    {
        [$demoted, $before, $after] = $this->node([
            ['fn' => 'demote', 'args' => ["## Segment cervical\n\n```\n## code\n```\n\n###### deepest\n# top\n"]],
            ['fn' => 'examBefore', 'args' => ["---\nx: 1\n---\n\n# N\n\n## IRM\n\nhere", 30]],
            ['fn' => 'examBefore', 'args' => ["---\nx: 1\n---\n\n# N\n\n## IRM\n\nhere", 18]],
        ]);

        self::assertSame("### Segment cervical\n\n```\n## code\n```\n\n###### deepest\n## top\n", $demoted);
        self::assertTrue($before);
        self::assertFalse($after);
    }

    /**
     * @param list<array{fn: string, args: list<mixed>}> $cases
     *
     * @return list<mixed>
     */
    private function node(array $cases): array
    {
        $process = proc_open(
            ['node', \dirname(__DIR__, 2) . '/tools/run-editor-exams.js'],
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
            throw new RuntimeException('editor-exams harness failed: ' . $err);
        }

        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }
}
