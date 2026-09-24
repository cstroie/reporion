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
        ];
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
