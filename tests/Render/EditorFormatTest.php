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
 * button says, toggles back, and never writes into the frontmatter: except
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
        self::assertSame(self::DOC . '# Text', $heading['text']);
    }

    public function testHeadingCyclesOneTwoThreeAndPlain(): void
    {
        $at = \strlen(self::DOC) + 1;
        $cases = [
            ['Tehnica', '# Tehnica'],
            ['# Tehnica', '## Tehnica'],
            ['## Tehnica', '### Tehnica'],
            ['### Tehnica', 'Tehnica'],
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

    /**
     * TODO 13: clicking the list button on an empty line inserted nothing
     * (the "blank line stays blank" rule for a multi-line selection's
     * separator lines was also eating the one line the caller meant to
     * start a list on).
     */
    public function testListOnAnEmptyLineInsertsTheFirstMarker(): void
    {
        $text = self::DOC . "Una\n\nTrei";
        $at = \strlen(self::DOC . "Una\n");

        [$bullet] = $this->run1('list', [$text, $at, $at, 'bullet']);
        self::assertSame(self::DOC . "Una\n- \nTrei", $bullet['text']);

        [$number] = $this->run1('list', [$text, $at, $at, 'number']);
        self::assertSame(self::DOC . "Una\n1. \nTrei", $number['text']);
    }

    /**
     * TODO 13: Enter at the end of a list line continues it; Enter on an
     * already-empty item breaks out instead of adding another one.
     */
    public function testContinueListOnEnter(): void
    {
        $text = self::DOC . '- Una';
        $at = \strlen($text);
        [$next] = $this->run1('continueList', [$text, $at]);
        self::assertSame(self::DOC . "- Una\n- ", $next['text']);

        $numbered = self::DOC . "1. Una\n2. Doua";
        $at2 = \strlen($numbered);
        [$next2] = $this->run1('continueList', [$numbered, $at2]);
        self::assertSame($numbered . "\n3. ", $next2['text']);

        $empty = self::DOC . "- Una\n- ";
        $at3 = \strlen($empty);
        [$exit] = $this->run1('continueList', [$empty, $at3]);
        self::assertSame(self::DOC . '- Una' . "\n", $exit['text']);

        // A task item opens an unchecked one, ticked or not; ordered too
        foreach (['- [ ] Una' => "\n- [ ] ", '* [x] Una' => "\n* [ ] ", '+ [X] Una' => "\n+ [ ] ", '1. [x] Una' => "\n2. [ ] "] as $line => $added) {
            $task = self::DOC . $line;
            [$t] = $this->run1('continueList', [$task, \strlen($task)]);
            self::assertSame($task . $added, $t['text'], $line);
        }
        // An empty task item breaks out, ticked or not
        foreach (['- [ ] ', '- [x] '] as $box) {
            $emptyTask = self::DOC . "- [ ] Una\n" . $box;
            [$out] = $this->run1('continueList', [$emptyTask, \strlen($emptyTask)]);
            self::assertSame(self::DOC . "- [ ] Una\n", $out['text'], $box);
        }
        // `[ ]` without a space after it is no task (the renderer says so): a plain bullet
        $loose = self::DOC . '- [ ]';
        [$l] = $this->run1('continueList', [$loose, \strlen($loose)]);
        self::assertSame($loose . "\n- ", $l['text']);
        // A blockquote keeps its prefix, alone or around a list; empty exits
        $quote = self::DOC . '> Una';
        [$q] = $this->run1('continueList', [$quote, \strlen($quote)]);
        self::assertSame($quote . "\n> ", $q['text']);
        $quoted = self::DOC . '> - [ ] Una';
        [$qt] = $this->run1('continueList', [$quoted, \strlen($quoted)]);
        self::assertSame($quoted . "\n> - [ ] ", $qt['text']);
        $quoteOut = self::DOC . "> Una\n> ";
        [$qo] = $this->run1('continueList', [$quoteOut, \strlen($quoteOut)]);
        self::assertSame(self::DOC . "> Una\n", $qo['text']);

        // Not at the end of the line, or not on a list line: no edit
        [$mid] = $this->run1('continueList', [$text, $at - 1]);
        self::assertNull($mid['result']);
        [$plain] = $this->run1('continueList', [self::DOC . 'Una', \strlen(self::DOC . 'Una')]);
        self::assertNull($plain['result']);
    }

    public function testTableRowOnEnterAndTaskHelpers(): void
    {
        $t = self::DOC . "| A | B |\n| --- | --- |\n| 1 | 2 |";
        [$row] = $this->run1('continueList', [$t, \strlen($t)]);
        self::assertSame($t . "\n|  |  |", $row['text']);
        self::assertSame(\strlen($t) + 3, $row['result']['selStart']);

        // On the header row the new one goes under the delimiter
        $head = self::DOC . "| A | B |\n| --- | --- |\n| 1 | 2 |";
        $at = \strlen(self::DOC . '| A | B |');
        [$h] = $this->run1('continueList', [$head, $at]);
        self::assertSame(self::DOC . "| A | B |\n| --- | --- |\n|  |  |\n| 1 | 2 |", $h['text']);

        // An empty row is removed
        $empty = $t . "\n|  |  |";
        [$gone] = $this->run1('continueList', [$empty, \strlen($empty)]);
        self::assertSame($t . "\n", $gone['text']);

        // Ctrl+Enter cycle: plain -> task -> ticked -> plain
        $cycle = ['Una' => '- [ ] Una', '- Una' => '- [ ] Una', '- [ ] Una' => '- [x] Una', '- [x] Una' => '- Una', '2. Una' => '- [ ] Una'];
        foreach ($cycle as $from => $to) {
            $doc = self::DOC . $from;
            [$r] = $this->run1('toggleTask', [$doc, \strlen($doc), \strlen($doc)]);
            self::assertSame(self::DOC . $to, $r['text'], $from);
        }

        // The checklist button: all lines tasks, again removes them
        $lines = self::DOC . "Una\nDoua";
        [$l] = $this->run1('list', [$lines, \strlen(self::DOC), \strlen($lines), 'task']);
        self::assertSame(self::DOC . "- [ ] Una\n- [ ] Doua", $l['text']);

        // Backspace after an empty marker drops it; elsewhere nothing
        foreach (['- ' => '', '3. ' => '', '- [ ] ' => '', '> ' => ''] as $from => $to) {
            $doc = self::DOC . $from;
            [$d] = $this->run1('dropMarker', [$doc, \strlen($doc)]);
            self::assertSame(self::DOC . $to, $d['text'], $from);
        }
        $none = self::DOC . '- Una';
        [$n] = $this->run1('dropMarker', [$none, \strlen($none)]);
        self::assertNull($n['result']);
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

    public function testCleanTextTidiesDictationAndKeepsCodeAndHardBreaks(): void
    {
        $text = self::DOC . "Fără  leziuni ,  la nivelul   ţesutului .\n\n\n\nLinie   \nurmatoare  \n\n    code  here  \nŞi `a  b` http://x.com/ş ok\n";
        [$run] = $this->run1('cleanText', [$text, 0, 0]);

        self::assertSame(
            self::DOC . "Fără leziuni, la nivelul țesutului.\n\nLinie  \nurmatoare\n\n    code  here  \nȘi `a  b` http://x.com/ş ok\n",
            $run['text']
        );
        self::assertGreaterThan(0, $run['result']['converted']);
    }

    public function testCleanTextOnTidyTextIsNoEdit(): void
    {
        [$run] = $this->run1('cleanText', [self::DOC . "Fără leziuni, la nivelul țesutului.\n", 0, 0]);

        self::assertSame(0, $run['result']['converted']);
    }

    public function testUnitsAndDegrees(): void
    {
        $text = self::DOC . 'Arie 12 cm2 si 3 mm3, 5 m2, 4cm², (cm2) rotatie 45 grade, 20 de grade, 37 grade Celsius, 3 grade de severitate, T2 grade, `cm2`, cm^2^ și m2 var';
        [$run] = $this->run1('unitsDegrees', [$text, 0, 0]);

        self::assertSame(
            self::DOC . 'Arie 12 cm^2^ si 3 mm^3^, 5 m^2^, 4cm^2^, (cm^2^) rotatie 45°, 20°, 37 °C, 3 grade de severitate, T2 grade, `cm2`, cm^2^ și m2 var',
            $run['text']
        );
        self::assertSame(8, $run['result']['converted']);
    }

    public function testTextToolsActOnTheSelectionOnly(): void
    {
        $text = self::DOC . '12 cm2 si 3 mm3';
        $from = \strlen(self::DOC);
        [$run] = $this->run1('unitsDegrees', [$text, $from, $from + 6]);

        self::assertSame(self::DOC . '12 cm^2^ si 3 mm3', $run['text']);
    }

    public function testAlignTablesPadsEveryColumnToItsWidestCell(): void
    {
        $text = self::DOC . "| Region | Size | Note |\n|:--|--:|:-:|\n| Liver | 3 cm | ok |\n| Spleen with long name | 12 | ă |\n\nafter\n";
        [$run] = $this->run1('alignTables', [$text, 0, 0]);

        self::assertSame(
            self::DOC . "| Region                | Size | Note |\n| :-------------------- | ---: | :--: |\n| Liver                 | 3 cm |  ok  |\n| Spleen with long name |   12 |  ă   |\n\nafter\n",
            $run['text']
        );
        self::assertSame(1, $run['result']['converted']);
    }

    public function testAlignTablesCountsAnInlineCodeSpanWholeAndLeavesCodeFencesAndTidyTablesAlone(): void
    {
        $tidy = self::DOC . "| a   | b   |\n| --- | --- |\n| 1   | 2   |\n";
        [$run] = $this->run1('alignTables', [$tidy, 0, 0]);
        self::assertSame($tidy, $run['text']);
        self::assertSame(0, $run['result']['converted']);

        $fenced = self::DOC . "```\n| a | b |\n|-|-|\n| 1 | 2222 |\n```\n";
        [$run] = $this->run1('alignTables', [$fenced, 0, 0]);
        self::assertSame($fenced, $run['text']);

        [$run] = $this->run1('alignTables', [self::DOC . "| a | b |\n|---|---|\n| `xxxxx` | 2 |\n", 0, 0]);
        self::assertSame(self::DOC . "| a       | b   |\n| ------- | --- |\n| `xxxxx` | 2   |\n", $run['text']);
    }

    public function testAlignTablesLeavesATableWithAnOverlongRowAndActsOnTheSelection(): void
    {
        $jagged = self::DOC . "| a | b |\n|---|---|\n| 1 | 2 | 3 |\n";
        [$run] = $this->run1('alignTables', [$jagged, 0, 0]);
        self::assertSame($jagged, $run['text']);
        self::assertSame(1, $run['result']['skipped']);

        $two = self::DOC . "| a | b |\n|---|---|\n| 1 | 2 |\n\n| c | d |\n|---|---|\n| 3 | 4 |\n";
        $from = \strlen(self::DOC);
        [$run] = $this->run1('alignTables', [$two, $from, $from + 30]);
        self::assertSame(self::DOC . "| a   | b   |\n| --- | --- |\n| 1   | 2   |\n\n| c | d |\n|---|---|\n| 3 | 4 |\n", $run['text']);
    }

    public function testUrlLinkWrapsTheSelectionOrWritesAnAutolink(): void
    {
        $text = self::DOC . 'see here now';
        $at = \strlen(self::DOC) + 4;
        [$run] = $this->run1('urlLink', [$text, $at, $at + 4, 'www.example.com/a b', 'The [site]']);
        self::assertSame(self::DOC . 'see [The \[site\]](https://www.example.com/a%20b) now', $run['text']);

        [$run] = $this->run1('urlLink', [$text, $at, $at + 4, 'a@b.ro', '']);
        self::assertSame(self::DOC . 'see <a@b.ro> now', $run['text']);
    }

    public function testUrlLinkRefusesWhatAPageMayNotLinkTo(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,x', 'http://', 'foo', '  ', 'a b@c'] as $address) {
            [$run] = $this->run1('urlLink', [self::DOC . 'x', 0, 0, $address, 'x']);
            self::assertNull($run['result'], $address);
        }
        foreach ([['https://a.org/f(1)', 'https://a.org/f%281%29'], ['tel:+4072', 'tel:+4072'], ['ftp://h/f', 'ftp://h/f']] as [$in, $out]) {
            [$run] = $this->run1('normalizeUrl', [$in]);
            self::assertSame($out, $run['result'], $in);
        }
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
            ['fn' => 'templateBody', 'args' => ["# IRM Cerebral\n\n### Tehnica\n\nText."]],
            ['fn' => 'templateBody', 'args' => ["Ficatul.\n\n# Nu e primul"]],
        ]);
        self::assertSame("### Tehnica\n\nText.", $results[0]['result']);
        self::assertSame("Ficatul.\n\n# Nu e primul", $results[1]['result']);
    }

    public function testATemplateInTheShapeAlsoLosesItsExamHeadingButAnOldOneKeepsItsFirstSection(): void
    {
        $results = $this->runCases([
            ['fn' => 'templateBody', 'args' => ["# Art: Genunchi\n\n## IRM Genunchi\n\n### Tehnica\n\nText."]],
            ['fn' => 'templateBody', 'args' => ["## Descriere\n\nText."]],
        ]);
        self::assertSame("### Tehnica\n\nText.", $results[0]['result']);
        self::assertSame("## Descriere\n\nText.", $results[1]['result']);
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

    public function testASnippetExpandsAfterASpaceOrLineBreakKeepingIt(): void
    {
        $snippets = ['norm' => 'Fara modificari.', 'rec' => 'Recomand $0 control.'];
        $space = self::DOC . 'Concluzii: ;norm ';
        $enter = self::DOC . ";norm\n";
        $results = $this->runCases([
            ['fn' => 'expandAt', 'args' => [$space, \strlen($space), $snippets, true]],
            ['fn' => 'expandAt', 'args' => [$enter, \strlen($enter), $snippets, true]],
        ]);

        self::assertSame(self::DOC . 'Concluzii: Fara modificari. ', $results[0]['text']);
        self::assertSame(\strlen($results[0]['text']), $results[0]['result']['selStart'], 'the cursor after the kept space');
        self::assertSame(self::DOC . "Fara modificari.\n", $results[1]['text']);
    }

    public function testTheCursorLandsOnTheMarkAndTabExpandsWithoutADelimiter(): void
    {
        $snippets = ['rec' => 'Recomand $0 control.'];
        $text = self::DOC . 'Text (;REC';
        [$result] = $this->run1('expandAt', [$text, \strlen($text), $snippets, false]);

        self::assertSame(self::DOC . 'Text (Recomand  control.', $result['text']);
        self::assertSame(\strlen(self::DOC . 'Text (Recomand '), $result['result']['selStart']);
    }

    public function testNothingExpandsInsideAWordForAnUnknownNameOrInTheFrontmatter(): void
    {
        $snippets = ['norm' => 'X'];
        $inWord = self::DOC . 'a;norm ';
        $unknown = self::DOC . ';nimic ';
        $frontmatter = "---\ntitle: ;norm \n---\n\nText";
        $results = $this->runCases([
            ['fn' => 'expandAt', 'args' => [$inWord, \strlen($inWord), $snippets, true]],
            ['fn' => 'expandAt', 'args' => [$unknown, \strlen($unknown), $snippets, true]],
            ['fn' => 'expandAt', 'args' => [$frontmatter, 17, $snippets, true]],
            ['fn' => 'expandAt', 'args' => [self::DOC . ';norm', \strlen(self::DOC) + 5, $snippets, true]],
        ]);

        foreach ($results as $i => $r) {
            self::assertNull($r['result'], (string) $i);
        }
    }

    public function testAPickedSnippetGoesInAtTheCursorOutsideTheFrontmatter(): void
    {
        [$result] = $this->run1('insertSnippet', [self::DOC . 'Text', 3, 3, 'Normal $0.']);
        self::assertSame(self::DOC . 'Normal .Text', $result['text']);
        self::assertSame(\strlen(self::DOC) + 7, $result['result']['selStart']);
    }

    public function testTheSlashMenuOpensOnlyOnAnEmptyLine(): void
    {
        $results = array_column($this->runCases([
            ['fn' => 'slashAt', 'args' => ["Text.\n", 6]],
            ['fn' => 'slashAt', 'args' => ["Text.\n- ", 8]],
            ['fn' => 'slashAt', 'args' => ['12', 2]],
            ['fn' => 'slashAt', 'args' => ["a\nb", 2]],
            ['fn' => 'slashAt', 'args' => ['', 0]],
        ]), 'result');

        self::assertSame([true, true, false, false, true], $results, 'an empty line, after a list marker; not after text, not before it');
    }

    public function testTheCitationPickerOpensOnlyAtTheStartOfAWord(): void
    {
        $results = array_column($this->runCases([
            ['fn' => 'citeAt', 'args' => ['', 0]],
            ['fn' => 'citeAt', 'args' => ['See ', 4]],
            ['fn' => 'citeAt', 'args' => ["See\n", 4]],
            ['fn' => 'citeAt', 'args' => ['(', 1]],
            ['fn' => 'citeAt', 'args' => ['[@a; ', 5]],
            ['fn' => 'citeAt', 'args' => ['name', 4]],
            ['fn' => 'citeAt', 'args' => ['[', 1]],
        ]), 'result');
        self::assertSame([true, true, true, true, true, false, true], $results, 'after a space, a line break, a bracket or a semicolon; not inside a word (an e-mail address)');

        $texts = array_column($this->runCases([
            ['fn' => 'citeEdit', 'args' => ['See ', 4, 'abel2020']],
            ['fn' => 'citeEdit', 'args' => ['See [', 5, 'abel2020']],
            ['fn' => 'citeEdit', 'args' => ['See [@a; ', 9, 'abel2020']],
            ['fn' => 'citeEdit', 'args' => ['See [@a; ] and', 9, 'abel2020']],
            ['fn' => 'citeEdit', 'args' => ['See []', 5, 'abel2020']],
            ['fn' => 'citeEdit', 'args' => ["See [@a]\nThen ", 15, 'abel2020']],
        ]), 'text');
        self::assertSame(['See [@abel2020]', 'See [@abel2020]', 'See [@a; @abel2020]', 'See [@a; @abel2020] and', 'See [@abel2020]', "See [@a]\nThen [@abel2020]"], $texts, 'a bracket already typed is never doubled');
    }

    public function testASectionHeadingGoesOnItsOwnLine(): void
    {
        [$after, $first] = array_column($this->runCases([
            ['fn' => 'sectionAt', 'args' => ["Text.\n", 6, 'Concluzii']],
            ['fn' => 'sectionAt', 'args' => ['', 0, 'Descriere']],
        ]), 'result');

        self::assertSame("\n### Concluzii\n\n", $after['insert'], 'a blank line before it after text');
        self::assertSame(22, $after['selStart']);
        self::assertSame("### Descriere\n\n", $first['insert']);
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
