<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\SourceText;

/**
 * The article here is written for the test (docs/library.md: never a real paper in the repository).
 */
final class SourceTextTest extends TestCase
{
    /** @return list<string> four pages as pdftotext gives them */
    private static function pages(): array
    {
        $head = "Journal of Synthetic Imaging 2024; 3: 11-14\n\n";

        return [
            $head . "A made-up study of\ntest lesions\n\nAbstract\nWe describe a de\u{FB01}ned set of lesions seen on\nsynthetic scans.\n\n11",
            $head . "1. Introduction\nThe lesions were classi-\nfied in four grades by an\nimaginary panel.\n\n- 12 -",
            $head . "Results\nGrade 3 was the most common.\n\nDiscussion\nNothing here is real.\n\n13",
            $head . "References\n1. Nobody A. A paper that does not exist.\nJ Synth Imaging. 2020;1:1-2.\n2. Nobody B. Another one. 2021.\n\n14",
        ];
    }

    public function testRunningHeadsAndPageNumbersGoAndEachPageGetsItsAnchor(): void
    {
        $body = SourceText::fromPages(self::pages())['body'];

        self::assertStringNotContainsString('Journal of Synthetic Imaging', $body);
        self::assertDoesNotMatchRegularExpression('/^(?:- )?1[1-4](?: -)?$/m', $body);
        foreach ([1, 2, 3, 4] as $page) {
            self::assertStringContainsString("\n*— p. {$page} —*\n", "\n" . $body);
        }
    }

    public function testLinesBecomeParagraphsAndAWordBrokenOverTheLineEndIsWhole(): void
    {
        $body = SourceText::fromPages(self::pages())['body'];

        self::assertStringContainsString("A made-up study of test lesions\n", $body);
        self::assertStringContainsString('The lesions were classified in four grades by an imaginary panel.', $body);
    }

    public function testLigaturesAreLetters(): void
    {
        self::assertStringContainsString('a defined set', SourceText::fromPages(self::pages())['body']);
    }

    public function testSectionNamesOnTheirOwnLineBecomeHeadings(): void
    {
        $body = SourceText::fromPages(self::pages())['body'];

        self::assertStringContainsString("## Abstract\n", $body);
        self::assertStringContainsString("## Introduction\n", $body);
        self::assertStringContainsString("## Results\n", $body);
        self::assertStringContainsString("## Discussion\n", $body);
    }

    public function testTheReferencesAreCutOffAndKeptApart(): void
    {
        $text = SourceText::fromPages(self::pages());

        self::assertStringNotContainsString('References', $text['body']);
        self::assertStringNotContainsString('does not exist', $text['body']);
        self::assertStringStartsWith('1. Nobody A. A paper that does not exist.', $text['references']);
        self::assertStringContainsString('2. Nobody B. Another one. 2021.', $text['references']);
    }

    public function testAReferencesWordEarlyInTheTextIsNotTheReferencesSection(): void
    {
        $text = SourceText::fromPages(["References\nare discussed below.\n", "Body text one.\n", "Body text two.\n"]);

        self::assertSame('', $text['references']);
        self::assertStringContainsString('Body text two.', $text['body']);
    }

    public function testExtractedProseNeverBecomesMarkdownItWasNot(): void
    {
        $body = SourceText::fromPages(["2. grade lesions\n\n# not a heading\n\n- not a list\n\nsee [1] and <b>\n"])['body'];

        self::assertStringContainsString('2\. grade lesions', $body);
        self::assertStringContainsString('\# not a heading', $body);
        self::assertStringContainsString('\- not a list', $body);
        self::assertStringContainsString('see \[1\] and &lt;b>', $body);
    }

    public function testALineEndingASentenceWellShortOfTheOthersEndsTheParagraph(): void
    {
        $long = 'The made-up lesions were graded by an imaginary panel of readers who';
        $page = "{$long}\nlooked at synthetic scans.\n{$long}\nagreed on four grades.\n{$long}\nsaw nothing real at all.\n";

        $body = SourceText::fromPages([$page])['body'];

        self::assertStringContainsString("{$long} looked at synthetic scans.\n\n{$long} agreed on four grades.\n\n{$long} saw nothing real at all.\n", $body);
    }

    public function testMarkdownFromPandocIsOnlyTidied(): void
    {
        $text = SourceText::fromMarkdown("# Title\r\n\r\n\r\n\r\nSome \u{FB02}uid text.   \n\n| a | b |\n|---|---|\n| 1 | 2 |\n");

        self::assertSame("# Title\n\nSome fluid text.\n\n| a | b |\n|---|---|\n| 1 | 2 |\n", $text['body']);
        self::assertSame('', $text['references']);
    }
}
