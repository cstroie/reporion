<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Render;

use PHPUnit\Framework\TestCase;
use Reporion\Support\DocumentFormat;
use RuntimeException;

/**
 * The editor toolbar's text transforms (assets/js/editor-format.js, phase
 * 10), run in node through tools/run-editor-format.js. What they write is
 * held to the dialect by the conformance corpus
 * (tests/fixtures/render/editor-toolbar.md); here, that each does what its
 * button says, toggles back, and never writes into the frontmatter — except
 * addPrior(), whose result must still parse as the expected YAML.
 */
final class EditorFormatTest extends TestCase
{
    private const DOC = "---\ntitle: Popescu Ana\nmodality:\n  - MR\n---\n\n";

    protected function setUp(): void
    {
        exec('node --version 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            self::fail('node is required to run the editor format test.');
        }
    }

    public function testBoldWrapsTheSelectionAndUnwrapsIt(): void
    {
        $text = self::DOC . 'a word here';
        $at = \strlen(self::DOC) + 2;
        [$wrapped] = $this->run1('toggleWrap', [$text, $at, $at + 4, '**']);
        self::assertSame(self::DOC . 'a **word** here', $wrapped['text']);
        self::assertSame([$at + 2, $at + 6], [$wrapped['result']['selStart'], $wrapped['result']['selEnd']]);

        // The selection is still "word", inside the markers: pressing again unwraps
        [$back] = $this->run1('toggleWrap', [$wrapped['text'], $at + 2, $at + 6, '**']);
        self::assertSame($text, $back['text']);
    }

    public function testEmphasisKeepsEdgeSpacesOutsideAndAnEmptySelectionGetsThePair(): void
    {
        $text = self::DOC . 'a word here';
        $at = \strlen(self::DOC) + 1;
        [$spaced] = $this->run1('toggleWrap', [$text, $at, $at + 6, '*']);
        self::assertSame(self::DOC . 'a *word* here', $spaced['text']);

        [$empty] = $this->run1('toggleWrap', [self::DOC . 'x ', \strlen(self::DOC) + 2, \strlen(self::DOC) + 2, '**']);
        self::assertSame(self::DOC . 'x ****', $empty['text']);
        self::assertSame(\strlen(self::DOC) + 4, $empty['result']['selStart']);
    }

    public function testItalicDoesNotMistakeBoldForItsOwnMarkers(): void
    {
        $text = self::DOC . '**word**';
        [$result] = $this->run1('toggleWrap', [$text, \strlen(self::DOC), \strlen($text), '*']);
        self::assertSame(self::DOC . '***word***', $result['text']);
    }

    public function testTextButtonsNeverWriteIntoTheFrontmatter(): void
    {
        [$bold] = $this->run1('toggleWrap', [self::DOC . 'Text', 6, 6, '**']);
        self::assertStringStartsWith(self::DOC, $bold['text']);

        [$heading] = $this->run1('heading', [self::DOC . 'Text', 6, 6]);
        self::assertSame(self::DOC . '## Text', $heading['text']);
    }

    public function testHeadingCyclesTwoThreeAndPlainButNeverOne(): void
    {
        $at = \strlen(self::DOC) + 1;
        $cases = [
            ['Tehnica', '## Tehnica'],
            ['## Tehnica', '### Tehnica'],
            ['### Tehnica', 'Tehnica'],
            ['# Popescu Ana', '## Popescu Ana'],
        ];
        $results = $this->runCases(array_map(static fn (array $c): array => ['fn' => 'heading', 'args' => [self::DOC . $c[0], $at, $at]], $cases));
        foreach ($cases as $i => $case) {
            self::assertSame(self::DOC . $case[1], $results[$i]['text']);
        }
    }

    public function testListsAddRemoveAndSwitchOnEverySelectedLine(): void
    {
        $body = "Una\nDoua\n\nTrei";
        $text = self::DOC . $body;
        $from = \strlen(self::DOC);
        $to = \strlen($text);

        [$bullets] = $this->run1('list', [$text, $from, $to, 'bullet']);
        self::assertSame(self::DOC . "- Una\n- Doua\n\n- Trei", $bullets['text']);

        [$numbers] = $this->run1('list', [$bullets['text'], $from, \strlen($bullets['text']), 'number']);
        self::assertSame(self::DOC . "1. Una\n2. Doua\n\n3. Trei", $numbers['text']);

        [$off] = $this->run1('list', [$numbers['text'], $from, \strlen($numbers['text']), 'number']);
        self::assertSame($text, $off['text']);
    }

    public function testTableFromTabSeparatedLinesOrASkeleton(): void
    {
        $body = "Segment\tValoare\nL4-L5\t3 mm";
        $text = self::DOC . "Text\n\n" . $body;
        $from = \strlen(self::DOC) + 6;
        [$pasted] = $this->run1('table', [$text, $from, \strlen($text)]);
        self::assertSame(self::DOC . "Text\n\n| Segment | Valoare |\n| --- | --- |\n| L4-L5 | 3 mm |\n", $pasted['text']);

        [$skeleton] = $this->run1('table', [self::DOC . 'Text', \strlen(self::DOC) + 4, \strlen(self::DOC) + 4, ['A', 'B']]);
        self::assertSame(self::DOC . "Text\n\n| A | B |\n| --- | --- |\n|  |  |\n", $skeleton['text']);
    }

    public function testCodeIsInlineOnOneLineAndFencedAcrossSeveral(): void
    {
        $text = self::DOC . "a b\nc d";
        $at = \strlen(self::DOC);
        [$inline] = $this->run1('toggleWrap', [$text, $at, $at + 3, '`']);
        self::assertSame(self::DOC . "`a b`\nc d", $inline['text']);

        [$fenced] = $this->run1('toggleWrap', [$text, $at, \strlen($text), '`']);
        self::assertSame(self::DOC . "```\na b\nc d\n```\n", $fenced['text']);
    }

    public function testLinksUseTheSelectionAsTextAndEscapeBrackets(): void
    {
        $text = self::DOC . 'vezi ghidul';
        $at = \strlen(self::DOC) + 5;
        [$selected] = $this->run1('link', [$text, $at, $at + 6, 'guides:lombar', 'Ghid [lombar]']);
        self::assertSame(self::DOC . 'vezi [ghidul](guides:lombar)', $selected['text']);

        [$bare] = $this->run1('link', [$text, $at, $at, 'guides:lombar', 'Ghid [lombar]']);
        self::assertSame(self::DOC . 'vezi [Ghid \[lombar\]](guides:lombar)ghidul', $bare['text']);
    }

    public function testATemplateBodyGoesInAsItsOwnParagraphs(): void
    {
        $text = self::DOC . "Indicatie.\nMai departe.";
        $at = \strlen(self::DOC) + 10;
        [$result] = $this->run1('insertBlock', [$text, $at, $at, "\n## Tehnica\n\nText.\n\n"]);
        self::assertSame(self::DOC . "Indicatie.\n\n## Tehnica\n\nText.\n\nMai departe.", $result['text']);
    }

    public function testATemplateLosesALeadingFirstLevelHeadingOnly(): void
    {
        $results = $this->runCases([
            ['fn' => 'templateBody', 'args' => ["# IRM Cerebral\n\n## Tehnica\n\nText."]],
            ['fn' => 'templateBody', 'args' => ["Ficatul.\n\n# Nu e primul"]],
        ]);
        self::assertSame("## Tehnica\n\nText.", $results[0]['result']);
        self::assertSame("Ficatul.\n\n# Nu e primul", $results[1]['result']);
    }

    public function testCopyTakesTheBodyWithoutTheFrontmatter(): void
    {
        [$result] = $this->run1('bodyOf', [self::DOC . "# Popescu Ana\n\nText."]);
        self::assertSame("# Popescu Ana\n\nText.", $result['result']);
    }

    public function testAPriorIsAddedToTheFrontmatterAndStillParses(): void
    {
        $prior = 'reports:mri:mioveni:250312-popescu-ana';
        $other = 'reports:ct:mioveni:240101-popescu-ana';
        $body = "\n\nText.";
        $cases = [
            'no priors' => [DocumentFormat::encode(['title' => 'X', 'modality' => ['MR']], 'Text.'), [$prior]],
            'a block list' => [DocumentFormat::encode(['title' => 'X', 'priors' => [$other], 'tags' => ['a']], 'Text.'), [$other, $prior]],
            'a flow list' => ["---\ntitle: X\npriors: [" . $other . "]\n---" . $body, [$other, $prior]],
            'empty' => ["---\ntitle: X\npriors: []\nsite: m\n---" . $body, [$prior]],
        ];
        $results = $this->runCases(array_map(static fn (array $c): array => ['fn' => 'addPrior', 'args' => [$c[0], $prior]], array_values($cases)));

        foreach (array_keys($cases) as $i => $name) {
            self::assertNotNull($results[$i]['text'], $name);
            [$frontmatter, $parsedBody] = DocumentFormat::parse($results[$i]['text']);
            self::assertSame($cases[$name][1], $frontmatter['priors'], $name);
            self::assertSame('X', $frontmatter['title'], $name);
            self::assertSame('Text.', trim($parsedBody), $name);
        }
    }

    public function testAPriorAlreadyThereOrAnUnknownShapeChangesNothing(): void
    {
        $prior = 'reports:mri:mioveni:250312-popescu-ana';
        $results = $this->runCases([
            ['fn' => 'addPrior', 'args' => [DocumentFormat::encode(['priors' => [$prior]], 'T'), $prior]],
            ['fn' => 'addPrior', 'args' => ["---\npriors: {a: b}\n---\n\nT", $prior]],
            ['fn' => 'addPrior', 'args' => ['No frontmatter at all', $prior]],
        ]);

        self::assertNull($results[0]['result']);
        self::assertSame(['unknown' => true], $results[1]['result']);
        self::assertSame(['unknown' => true], $results[2]['result']);
    }

    /**
     * @param list<mixed> $args
     *
     * @return list<array{result: mixed, text: ?string}>
     */
    private function run1(string $fn, array $args): array
    {
        return $this->runCases([['fn' => $fn, 'args' => $args]]);
    }

    /**
     * @param list<array{fn: string, args: list<mixed>}> $cases
     *
     * @return list<array{result: mixed, text: ?string}>
     */
    private function runCases(array $cases): array
    {
        $process = proc_open(
            ['node', \dirname(__DIR__, 2) . '/tools/run-editor-format.js'],
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
            throw new RuntimeException('editor-format harness failed: ' . $err);
        }

        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }
}
