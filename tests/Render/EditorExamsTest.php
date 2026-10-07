<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Render;

use PHPUnit\Framework\TestCase;
use Reporion\Support\DocumentFormat;
use Reporion\Support\Exams;
use RuntimeException;

/**
 * The editor's exam tabs (assets/js/editor-exams.js, phase 12), run in node
 * through tools/run-editor-exams.js: the JS cuts a body exactly where
 * Support\Exams does, and putting the panes back together gives the same
 * frontmatter and body the server reads.
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

    public function testAListAndHeadingsThatDisagreeAreEditedAsTextNotHidden(): void
    {
        $extra = self::FM;
        $extra['exams'][] = ['title' => 'IRM genunchi stâng', 'region' => ['msk'], 'accession' => 'MV-MR-26-0003'];
        $fewer = self::FM;
        array_pop($fewer['exams']);
        [$tooMany, $tooFew, $agree] = $this->node([
            ['fn' => 'open', 'args' => [DocumentFormat::encode($extra, self::BODY)]],
            ['fn' => 'open', 'args' => [DocumentFormat::encode($fewer, self::BODY)]],
            ['fn' => 'open', 'args' => [DocumentFormat::encode(self::FM, self::BODY)]],
        ]);

        self::assertFalse($tooMany, 'a third entry with two ## would have no tab to remove it from');
        self::assertNull($tooFew, 'one entry is a single-exam report (phase 27): one textarea, whatever its headings');
        self::assertIsArray($agree);
    }

    public function testThePanesPutBackGiveTheSameReport(): void
    {
        $doc = DocumentFormat::encode(self::FM, self::BODY);
        [$state] = $this->node([['fn' => 'open', 'args' => [$doc]]]);

        self::assertStringNotContainsString('exams:', $state['head'], 'the list is the tabs, not text in the head');
        self::assertStringEndsWith("**Gonalgie**\n\n", $state['head']);
        self::assertCount(2, $state['parts']);
        self::assertSame(['msk', 'spine'], $state['exams'][1]['region']);
        self::assertSame("IRM genunchi stâng 'bis'", $state['exams'][1]['title']);
        self::assertSame(['1.2.826.1.1', '1088159'], [$state['exams'][0]['study_uid'], $state['exams'][0]['pacs_accession']], 'the PACS study of an exam (dicom plugin) is read, not rejected as an unknown key');
        self::assertSame('templates:mri:genunchi', $state['exams'][0]['template'], 'and its own template');

        [$joined] = $this->node([['fn' => 'join', 'args' => [$state]]]);
        self::assertSame([self::FM, self::BODY], self::parsed($joined));
    }

    public function testEveryHeadingGetsABlankLineBeforeIt(): void
    {
        [$state] = $this->node([['fn' => 'open', 'args' => [DocumentFormat::encode(self::FM, self::BODY)]]]);
        $state['parts'][0] = "## IRM genunchi drept\nA.\n### Concluzii\nB.";
        $state['parts'][1] = "## IRM genunchi stâng 'bis'\n\nC.\n```\nx\n### not a heading\n```\n### Concluzii\n\nD.\n";

        [$joined, $spaced] = $this->node([
            ['fn' => 'join', 'args' => [$state]],
            ['fn' => 'spaceHeadings', 'args' => ["# N\n## A\n\n### B\nText ## not a heading\n#hashtag\n    ## indented code\n"]],
        ]);

        self::assertSame("# TEST Patient Unu\n\n**Gonalgie**\n\n## IRM genunchi drept\nA.\n\n### Concluzii\nB.\n\n## IRM genunchi stâng 'bis'\n\nC.\n```\nx\n### not a heading\n```\n\n### Concluzii\n\nD.\n", self::parsed($joined)[1], 'an exam without a final newline, a ### under text; code left alone');
        self::assertSame("# N\n\n## A\n\n### B\nText ## not a heading\n#hashtag\n    ## indented code\n", $spaced, 'already spaced, not a heading: unchanged');
    }

    public function testAnExamTitleFollowsItsHeading(): void
    {
        [$state] = $this->node([['fn' => 'open', 'args' => [DocumentFormat::encode(self::FM, self::BODY)]]]);
        $state['parts'][0] = str_replace('## IRM genunchi drept', '## IRM genunchi drept (nativ)', $state['parts'][0]);

        [$joined] = $this->node([['fn' => 'join', 'args' => [$state]]]);

        self::assertSame('IRM genunchi drept (nativ)', self::parsed($joined)[0]['exams'][0]['title']);
    }

    public function testAddedRemovedAndMovedExamsKeepTheListAndTheBodyInStep(): void
    {
        [$state] = $this->node([['fn' => 'open', 'args' => [DocumentFormat::encode(self::FM, self::BODY)]]]);
        [$added] = $this->node([['fn' => 'addExam', 'args' => [$state, 'IRM gleznă']]]);
        [$joined] = $this->node([['fn' => 'join', 'args' => [$added]]]);
        [$fm, $body] = self::parsed($joined);
        self::assertSame(['title' => 'IRM gleznă'], $fm['exams'][2], 'no number yet: the server allocates it on save');
        self::assertStringEndsWith("D.\n\n## IRM gleznă\n\n### Descriere\n\n### Concluzii\n", $body);
        self::assertSame([], Exams::problems($fm, $body));

        [$moved] = $this->node([['fn' => 'moveExam', 'args' => [$added, 2, -2]]]);
        [$joined] = $this->node([['fn' => 'join', 'args' => [$moved]]]);
        [$fm, $body] = self::parsed($joined);
        self::assertSame(['IRM gleznă', 'IRM genunchi drept', "IRM genunchi stâng 'bis'"], array_column($fm['exams'], 'title'));
        self::assertSame(array_column($fm['exams'], 'title'), array_column(Exams::split($body)['parts'], 'title'));
        self::assertSame([], Exams::problems($fm, $body));

        [$removed] = $this->node([['fn' => 'removeExam', 'args' => [$moved, 1]]]);
        [$joined] = $this->node([['fn' => 'join', 'args' => [$removed]]]);
        [$fm, $body] = self::parsed($joined);
        self::assertSame(['MV-MR-26-0002'], array_values(array_filter(array_column($fm['exams'], 'accession'))), 'the removed exam\'s number goes with it');
        self::assertSame([], Exams::problems($fm, $body));
    }

    public function testASingleReportBecomesMultiOnlyInTheOneHeadingShape(): void
    {
        $single = ['title' => 'TEST Patient Unu', 'accession' => 'MV-MR-26-0009', 'patient' => ['name' => 'TEST Patient Unu']];
        $doc = DocumentFormat::encode($single, "# TEST Patient Unu\n\n## IRM cerebral\n\nA.\n\n### Concluzii\n\nB.\n");
        [$state] = $this->node([['fn' => 'convert', 'args' => [$doc, 'IRM coloană cervicală']]]);
        [$joined] = $this->node([['fn' => 'join', 'args' => [$state]]]);
        [$fm, $body] = self::parsed($joined);

        self::assertSame([['title' => 'IRM cerebral'], ['title' => 'IRM coloană cervicală']], $fm['exams']);
        self::assertSame('MV-MR-26-0009', $fm['accession'], 'left for the server to move to exam 1');
        self::assertSame("# TEST Patient Unu\n\n## IRM cerebral\n\nA.\n\n### Concluzii\n\nB.\n\n## IRM coloană cervicală\n\n### Descriere\n\n### Concluzii\n", $body);

        [$shared] = $this->node([['fn' => 'convert', 'args' => [DocumentFormat::encode($single, "# N\n\n## CT Cerebral\n\nA.\n\n## CT Torace\n\nB.\n\n## Concluzii\n\nC.\n"), 'X']]]);
        self::assertSame(['error' => 'shape'], $shared, 'several ## already: set up by hand');
        [$none] = $this->node([['fn' => 'convert', 'args' => [DocumentFormat::encode($single, "# N\n\nText.\n"), 'X']]]);
        self::assertSame(['error' => 'shape'], $none);
    }

    public function testAnExamsListInAnotherShapeIsLeftToTheSingleTextarea(): void
    {
        $results = $this->node([
            ['fn' => 'parseExams', 'args' => ["title: x\nexams: [{ title: A }]\n"]],
            ['fn' => 'parseExams', 'args' => ["exams:\n  -\n    title: A\n    note: B\n"]],
            ['fn' => 'parseExams', 'args' => ["title: x\n"]],
            ['fn' => 'parseExams', 'args' => ["exams:\n  - title: 'A'\n    region: [msk, spine]\n  - title: B\nsite: m\n"]],
            ['fn' => 'parseExams', 'args' => ["exams:\n  -\n    title: A\n    contrast: { iv: yes }\n"]],
        ]);

        self::assertFalse($results[0], 'flow style');
        self::assertSame([['title' => 'A', 'note' => 'B']], $results[1]['exams'], 'any key of an exam is read (phase 27), never dropped');
        self::assertNull($results[2], 'no exams');
        self::assertSame([['title' => 'A', 'region' => ['msk', 'spine']], ['title' => 'B']], $results[3]['exams']);
        self::assertSame("site: m\n", $results[3]['rest']);
        self::assertFalse($results[4], 'a nested map is not read: the single textarea keeps it');
    }

    public function testEveryExamKeyRoundTripsThroughTheTabs(): void
    {
        $fm = ['title' => 'TEST Patient Unu', 'exam_title' => 'CT torace + IRM cerebral', 'modality' => ['CT', 'MR'], 'exams' => [
            ['title' => 'CT torace', 'modality' => 'CT', 'region' => ['chest'], 'study_date' => '2026-10-02T09:10:00+03:00', 'device' => 'mv-ct1', 'accession' => 'MV-CT-26-0001', 'dlp' => 345, 'phases' => ['nativ', 'arterial'], 'tomosynthesis' => false],
            ['title' => 'IRM cerebral', 'modality' => 'MR', 'region' => ['neuro'], 'study_date' => '2026-10-02', 'protocol' => 'neuro-std', 'field_strength' => '1.5T'],
        ]];
        $body = "# TEST Patient Unu\n\n## CT torace\n\nA.\n\n### Concluzii\n\nB.\n\n## IRM cerebral\n\nC.\n\n### Concluzii\n\nD.\n";
        [$state] = $this->node([['fn' => 'open', 'args' => [DocumentFormat::encode($fm, $body)]]]);
        self::assertIsArray($state);
        [$joined] = $this->node([['fn' => 'join', 'args' => [$state]]]);

        self::assertSame([$fm, $body], self::parsed($joined), 'numbers stay numbers, booleans booleans, lists lists');

        $one = Exams::normalize(['title' => 'TEST Patient Unu', 'exam_title' => 'IRM cerebral', 'modality' => ['MR'], 'accession' => 'MV-MR-26-0009']);
        [$single, $converted] = $this->node([
            ['fn' => 'open', 'args' => [DocumentFormat::encode($one, "# TEST Patient Unu\n\n## IRM cerebral\n\nA.\n")]],
            ['fn' => 'convert', 'args' => [DocumentFormat::encode($one, "# TEST Patient Unu\n\n## IRM cerebral\n\nA.\n"), 'IRM coloană cervicală']],
        ]);
        self::assertNull($single, 'one exam: no tabs');
        [$joined] = $this->node([['fn' => 'join', 'args' => [$converted]]]);
        self::assertSame([['title' => 'IRM cerebral', 'modality' => 'MR', 'accession' => 'MV-MR-26-0009'], ['title' => 'IRM coloană cervicală']], self::parsed($joined)[0]['exams'], 'the first exam kept whole');
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

    /** @return array{0: array<string, mixed>, 1: string} */
    private static function parsed(string $doc): array
    {
        return DocumentFormat::parse($doc);
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
