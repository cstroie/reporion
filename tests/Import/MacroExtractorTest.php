<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Import;

use PHPUnit\Framework\TestCase;
use Reporion\Import\MacroExtractor;

final class MacroExtractorTest extends TestCase
{
    public function testTemplateExtraction(): void
    {
        $source = <<<'EOF'
====== Patient ======

Some text ~~LLM_TEMPLATE:reports:ct:templates:standard~~ more text.
EOF;

        $result = MacroExtractor::extract($source);

        $this->assertEquals('reports:ct:templates:standard', $result['template']);
        $this->assertStringNotContainsString('LLM_TEMPLATE', $result['body']);
    }

    public function testPreviousExtraction(): void
    {
        $source = <<<'EOF'
====== Patient ======

Previous exam: ~~LLM_PREVIOUS:reports:ct:scuc:210101-patient~~

Body text continues.
EOF;

        $result = MacroExtractor::extract($source);

        $this->assertContains('reports:ct:scuc:210101-patient', $result['priors'] ?? []);
        $this->assertStringNotContainsString('LLM_PREVIOUS', $result['body']);
    }

    public function testPreviousReportExtraction(): void
    {
        $source = '~~LLM_PREVIOUS_REPORT:reports:mri:scuc:200101-prior~~';

        $result = MacroExtractor::extract($source);

        $this->assertContains('reports:mri:scuc:200101-prior', $result['priors'] ?? []);
    }

    public function testMultiplePriors(): void
    {
        $source = <<<'EOF'
Prior 1: ~~LLM_PREVIOUS:reports:ct:scuc:210101-p1~~
Prior 2: ~~LLM_PREVIOUS_REPORT:reports:mri:scuc:200101-p2~~
EOF;

        $result = MacroExtractor::extract($source);
        $priors = $result['priors'] ?? [];

        $this->assertCount(2, $priors);
        $this->assertContains('reports:ct:scuc:210101-p1', $priors);
        $this->assertContains('reports:mri:scuc:200101-p2', $priors);
    }

    public function testMidLineTemplate(): void
    {
        $source = 'Text before ~~LLM_TEMPLATE:reports:ct:templates:full~~ text after';

        $result = MacroExtractor::extract($source);

        $this->assertEquals('reports:ct:templates:full', $result['template']);
        $this->assertStringContainsString('Text before', $result['body']);
        $this->assertStringContainsString('text after', $result['body']);
    }

    public function testMacroOnOwnLine(): void
    {
        $source = <<<'EOF'
====== Patient ======

~~LLM_TEMPLATE:reports:ct:templates:standard~~

Body starts here.
EOF;

        $result = MacroExtractor::extract($source);

        $this->assertEquals('reports:ct:templates:standard', $result['template']);
        // Macro line should be removed when it's alone
        $this->assertStringNotContainsString('~~LLM_TEMPLATE', $result['body']);
    }

    public function testNoMacros(): void
    {
        $source = '====== Patient ======\n\nJust normal text.';

        $result = MacroExtractor::extract($source);

        $this->assertNull($result['template'] ?? null);
        $this->assertEmpty($result['priors'] ?? []);
        $this->assertEquals($source, $result['body']);
    }

    public function testUnknownMacroPreserved(): void
    {
        $source = '~~UNKNOWN_MACRO:value~~ Text continues.';

        $result = MacroExtractor::extract($source);

        // Unknown macros should be preserved in body
        $this->assertStringContainsString('UNKNOWN_MACRO', $result['body']);
    }

    public function testTemplateAndPriorsCombined(): void
    {
        $source = <<<'EOF'
====== Patient ======

Template: ~~LLM_TEMPLATE:reports:ct:templates:full~~
Prior: ~~LLM_PREVIOUS:reports:ct:scuc:210101-p~~

Body text here.
EOF;

        $result = MacroExtractor::extract($source);

        $this->assertEquals('reports:ct:templates:full', $result['template']);
        $this->assertContains('reports:ct:scuc:210101-p', $result['priors'] ?? []);
        $this->assertStringContainsString('Body text here', $result['body']);
    }
}
