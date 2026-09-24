<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Import;

use PHPUnit\Framework\TestCase;
use Reporion\Import\SyntaxConverter;

final class SyntaxConverterTest extends TestCase
{
    /**
     * @dataProvider dokuwikiSyntaxProvider
     */
    public function testSyntaxConversion(string $dokuwiki, string $expectedMarkdown): void
    {
        $result = SyntaxConverter::convert($dokuwiki);
        $this->assertEquals($expectedMarkdown, $result['markdown']);
    }

    public static function dokuwikiSyntaxProvider(): array
    {
        return [
            'h1-six-equals' => [
                '====== Patient Name ======',
                '## Patient Name',
            ],
            'h2-five-equals' => [
                '===== Exam Type =====',
                '### Exam Type',
            ],
            'h3-four-equals' => [
                '==== Section ====',
                '#### Section',
            ],
            'bold' => [
                'This is **bold** text',
                'This is **bold** text',
            ],
            'italic' => [
                'This is //italic// text',
                'This is *italic* text',
            ],
            'underline-to-bold' => [
                'This is __underlined__ text',
                'This is **underlined** text',
            ],
            'list-two-space' => [
                "  * Item 1\n  * Item 2",
                "- Item 1\n- Item 2",
            ],
            'list-nested' => [
                "  * Item 1\n    * Nested\n  * Item 2",
                "- Item 1\n    * Nested\n- Item 2",
            ],
            'link-dokuwiki' => [
                '[[reports:ct:scuc:260702-patient-name|View Report]]',
                '[View Report](reports/ct/scuc/260702-patient-name)',
            ],
            'link-bare-url' => [
                'https://example.com',
                'https://example.com',
            ],
            'line-break' => [
                "Line 1\\\\\nLine 2",
                "Line 1  \nLine 2",
            ],
            'code-inline' => [
                "Text with ''code'' inline",
                "Text with ''code'' inline",
            ],
            'code-block' => [
                "<code>\ncode block\n</code>",
                "```\n\ncode block\n\n```",
            ],
            'mixed-markup' => [
                '====== Patient ======\n\nThis is //important// text with [[link|Link]]',
                '====== Patient ======\n\nThis is *important* text with [Link](link)',
            ],
            'blockquote-single-line-with-cite' => [
                '<blockquote>What the Nagus wants, we acquire.<cite>-- Star Trek</cite></blockquote>',
                "> What the Nagus wants, we acquire.\n> — -- Star Trek",
            ],
            'blockquote-multi-line-with-cite' => [
                "<blockquote>Line one.\nLine two.<cite>-- Someone</cite></blockquote>",
                "> Line one.\n> Line two.\n> — -- Someone",
            ],
            'blockquote-no-cite' => [
                '<blockquote>Just a quote.</blockquote>',
                '> Just a quote.',
            ],
            'poem-single-line' => [
                '<poem>A single line poem.</poem>',
                'A single line poem.',
            ],
            'poem-multi-line' => [
                "<poem>\nRoses are red.\nViolets are blue.\n</poem>",
                "\nRoses are red.\nViolets are blue.\n",
            ],
            'definition-list' => [
                "; Term\n: Definition text",
                "**Term**\nDefinition text",
            ],
            'table-well-formed' => [
                "^ Head A ^ Head B ^\n| Row1A | Row1B |\n| Row2A | Row2B |",
                "| Head A | Head B |\n|---|---|\n| Row1A | Row1B |\n| Row2A | Row2B |",
            ],
        ];
    }

    public function testMalformedTableIsPassedThroughVerbatimAndFlagged(): void
    {
        $dokuwiki = "^ Head A ^ Head B ^\n| Row1A | Row1B | Row1C |";
        $result = SyntaxConverter::convert($dokuwiki);

        $this->assertStringContainsString('^ Head A ^ Head B ^', $result['markdown']);
        $this->assertStringContainsString('| Row1A | Row1B | Row1C |', $result['markdown']);
        $this->assertContains('table', $result['unknown']);
    }

    public function testWellFormedTableIsFlaggedForReview(): void
    {
        $dokuwiki = "^ Head A ^ Head B ^\n| Row1A | Row1B |";
        $result = SyntaxConverter::convert($dokuwiki);

        $this->assertContains('table', $result['unknown']);
    }

    public function testTableWithBlankCellsConvertsWithoutDroppingColumns(): void
    {
        $dokuwiki = "^ A ^ B ^ C ^\n| 1 |  | 3 |";
        $result = SyntaxConverter::convert($dokuwiki);

        $this->assertStringContainsString(
            "| A | B | C |\n|---|---|---|\n| 1 |  | 3 |",
            $result['markdown']
        );
        $this->assertContains('table', $result['unknown']);
    }

    public function testPoemIsFlaggedForReview(): void
    {
        $result = SyntaxConverter::convert('<poem>A line.</poem>');
        $this->assertContains('poem-block', $result['unknown']);
    }

    public function testUnknownConstructsTracked(): void
    {
        $dokuwiki = "Normal text with ~~UNKNOWN:value~~ macro";
        $result = SyntaxConverter::convert($dokuwiki);

        // Unknown macros should be preserved
        $this->assertStringContainsString('UNKNOWN:value', $result['markdown']);
    }

    public function testMultipleSectionsPreserved(): void
    {
        $dokuwiki = <<<'EOF'
====== Patient Name ======
===== CT Cerebral =====
Some findings
===== CT Cervical =====
More findings
EOF;

        $result = SyntaxConverter::convert($dokuwiki);

        $this->assertStringContainsString('## Patient Name', $result['markdown']);
        $this->assertStringContainsString('### CT Cerebral', $result['markdown']);
        $this->assertStringContainsString('### CT Cervical', $result['markdown']);
        $this->assertStringContainsString('Some findings', $result['markdown']);
        $this->assertStringContainsString('More findings', $result['markdown']);
    }

    public function testEmptyInput(): void
    {
        $result = SyntaxConverter::convert('');
        $this->assertEquals('', $result['markdown']);
        $this->assertIsArray($result['unknown']);
    }

    public function testHeadingsArePreserved(): void
    {
        $dokuwiki = <<<'EOF'
====== H1 Title ======

Some paragraph text.

===== H2 Section =====

More text here.

==== H3 Subsection ====

Final text.
EOF;

        $result = SyntaxConverter::convert($dokuwiki);
        $markdown = $result['markdown'];

        // Verify heading hierarchy is correct
        $this->assertStringContainsString('## H1 Title', $markdown);
        $this->assertStringContainsString('### H2 Section', $markdown);
        $this->assertStringContainsString('#### H3 Subsection', $markdown);

        // Verify paragraph content is preserved
        $this->assertStringContainsString('Some paragraph text', $markdown);
        $this->assertStringContainsString('More text here', $markdown);
        $this->assertStringContainsString('Final text', $markdown);
    }

    public function testListsConvertedCorrectly(): void
    {
        $dokuwiki = <<<'EOF'
Items:
  * First item
  * Second item
  * Third item
EOF;

        $result = SyntaxConverter::convert($dokuwiki);
        $markdown = $result['markdown'];

        $this->assertStringContainsString('- First item', $markdown);
        $this->assertStringContainsString('- Second item', $markdown);
        $this->assertStringContainsString('- Third item', $markdown);
    }

    public function testFormattingCombined(): void
    {
        $dokuwiki = '**Bold** and //italic// and __underline__';
        $result = SyntaxConverter::convert($dokuwiki);
        $markdown = $result['markdown'];

        $this->assertStringContainsString('**Bold**', $markdown);
        $this->assertStringContainsString('*italic*', $markdown);
        $this->assertStringContainsString('**underline**', $markdown);
    }
}
