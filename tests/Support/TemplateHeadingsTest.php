<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\TemplateHeadings;

/**
 * A template page: one `#` (its name), one `##` (the exam), then sections (D45).
 */
final class TemplateHeadingsTest extends TestCase
{
    private const SHAPED = "# Art: Genunchi\n\n## IRM Genunchi\n\n### Tehnica\n\nText.\n";

    public function testTheNameIsTheHashAndTheExamTheHashHashAfterIt(): void
    {
        self::assertSame('Art: Genunchi', TemplateHeadings::name(self::SHAPED));
        self::assertSame('IRM Genunchi', TemplateHeadings::exam(self::SHAPED));
    }

    public function testAnOldShapeNamesNoExamItsHashHashAreSections(): void
    {
        self::assertNull(TemplateHeadings::name("## Descriere\n\nText.\n"));
        self::assertNull(TemplateHeadings::exam("## Descriere\n\nText.\n\n## Concluzii\n"));
    }

    public function testHeadingsInFencedCodeDoNotCount(): void
    {
        self::assertSame([], TemplateHeadings::problems([], self::SHAPED . "\n```\n# code\n## code\n```\n"));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function bodies(): iterable
    {
        yield 'right' => [self::SHAPED, []];
        yield 'no headings' => ["Text.\n", ['name.missing', 'exam.missing']];
        yield 'sections only' => ["## Descriere\n\n## Concluzii\n", ['name.missing', 'exam.many']];
        yield 'two names' => ["# A\n\n## B\n\n# C\n", ['name.many']];
        yield 'two exams' => ["# A\n\n## B\n\n## C\n", ['exam.many']];
        yield 'exam first' => ["## B\n\n# A\n", ['order']];
        yield 'no exam' => ["# A\n\n### Tehnica\n", ['exam.missing']];
    }

    /**
     * @dataProvider bodies
     *
     * @param list<string> $expected
     */
    public function testTheProblemsOfAShape(string $body, array $expected): void
    {
        self::assertSame($expected, TemplateHeadings::problems([], $body));
    }

    public function testATextTemplateHasNoHeadingsToCheck(): void
    {
        self::assertSame([], TemplateHeadings::problems(['format' => 'text'], "Just text.\n"));
    }

    public function testAReportBodyStartsWithoutTheTemplatesNameHeading(): void
    {
        self::assertSame("## IRM Genunchi\n\n### Tehnica\n\nText.\n", TemplateHeadings::withoutName(self::SHAPED));
        self::assertSame("Text.\n\n# Not first\n", TemplateHeadings::withoutName("Text.\n\n# Not first\n"));
    }
}
