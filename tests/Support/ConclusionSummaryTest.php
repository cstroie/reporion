<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\ConclusionSummary;

final class ConclusionSummaryTest extends TestCase
{
    private const REPORT = 'reports:mri:mioveni:260101-test-a';

    public function testTheFirstSentenceUnderTheFirstConclusionHeading(): void
    {
        $body = "# TEST PATIENT\n\n## RM cerebral\n\n### Descriere\n\nText. Alt text.\n\n### Concluzii\n\nFără leziuni active. Stabil față de 2025.\n\nAlt paragraf.\n\n### Concluzie\n\nNu aceasta.\n";

        self::assertSame('Fără leziuni active.', ConclusionSummary::extract($body));
    }

    public function testAbbreviationsAndDecimalsDoNotEndTheSentence(): void
    {
        self::assertSame(
            'Nodul de cca. 5 mm, fără modificări față de 2.5 mm anterior.',
            ConclusionSummary::extract("## CONCLUZIE\n\nNodul de cca. 5 mm,\nfără modificări față de 2.5 mm anterior. Restul normal.\n")
        );
    }

    public function testAListTakesItsFirstItemAndMarkdownBecomesPlainText(): void
    {
        self::assertSame(
            'Hernie discală L4-L5',
            ConclusionSummary::extract("### **Concluzii**\n1. **Hernie** discală `L4-L5`\n2. Altceva.\n")
        );
        self::assertSame('Vezi raportul anterior.', ConclusionSummary::extract("### Concluzii\n\nVezi [raportul anterior](reports:mri:x). Apoi.\n"));
    }

    public function testNoConclusionOrAnEmptyOneGivesNothing(): void
    {
        self::assertNull(ConclusionSummary::extract("### Descriere\n\nText.\n"));
        self::assertNull(ConclusionSummary::extract("### Concluzii\n\n### Recomandări\n\nControl.\n"));
        self::assertNull(ConclusionSummary::extract("Concluzii: text fără titlu.\n"));
    }

    public function testFillOnlyAnEmptySummaryOfAReport(): void
    {
        $body = "### Concluzii\n\nNormal.\n";

        self::assertSame('Normal.', ConclusionSummary::fill(self::REPORT, ['title' => 'x'], $body)['summary']);
        self::assertSame('Normal.', ConclusionSummary::fill(self::REPORT, ['summary' => '  '], $body)['summary']);
        self::assertSame('Scris de medic.', ConclusionSummary::fill(self::REPORT, ['summary' => 'Scris de medic.'], $body)['summary']);
        self::assertArrayNotHasKey('summary', ConclusionSummary::fill('docs:notes', [], $body), 'not a report');
        self::assertArrayNotHasKey('summary', ConclusionSummary::fill(self::REPORT, [], "No conclusion.\n"));
    }
}
