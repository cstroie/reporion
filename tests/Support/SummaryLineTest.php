<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\SummaryLine;

final class SummaryLineTest extends TestCase
{
    public function testEachExamLeadsItsTextOnceAndEndsWithAFullStop(): void
    {
        self::assertSame('IRM Genunchi Drept: Aspect normal. IRM Genunchi Stâng: Minim edem.', SummaryLine::byExam([
            ['label' => 'IRM Genunchi Drept', 'text' => 'aspect normal'],
            ['label' => 'IRM Genunchi Stâng', 'text' => 'irm genunchi stang: Minim edem.'],
        ]));
    }

    public function testNoLabelLeavesTheTextAndEmptyTextsAreLeftOut(): void
    {
        self::assertSame('Fără leziuni.', SummaryLine::byExam([['label' => '', 'text' => 'Fără leziuni'], ['label' => 'CT', 'text' => '  ']]));
        self::assertSame('', SummaryLine::byExam([]));
    }
}
