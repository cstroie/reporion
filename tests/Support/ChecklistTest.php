<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Checklist;

/**
 * A template's `checklist` (FORMATS §3h, roadmap phase 26).
 */
final class ChecklistTest extends TestCase
{
    public function testTheListFormsParse(): void
    {
        $items = Checklist::parse([
            '# Menisci',
            'Menisc medial | menisc medial, cornul posterior',
            'Revărsat articular',
            ['LIA' => ['lia', 'ligament încrucișat anterior']],
            ['Cartilaj' => 'cartilaj'],
            '',
            '#',
            ' | orphan',
            ['not', 'a', 'map'],
        ]);

        self::assertSame([
            ['section' => true, 'label' => 'Menisci', 'keywords' => []],
            ['section' => false, 'label' => 'Menisc medial', 'keywords' => ['menisc medial', 'cornul posterior']],
            ['section' => false, 'label' => 'Revărsat articular', 'keywords' => []],
            ['section' => false, 'label' => 'LIA', 'keywords' => ['lia', 'ligament încrucișat anterior']],
            ['section' => false, 'label' => 'Cartilaj', 'keywords' => ['cartilaj']],
        ], $items);
    }

    public function testABlockStringParsesByLineAndTheListIsBounded(): void
    {
        self::assertSame(['A', 'B'], array_column(Checklist::parse("A\r\nB\n"), 'label'));
        self::assertSame([], Checklist::parse(42));
        self::assertCount(Checklist::MAX, Checklist::parse(array_map(static fn (int $i): string => "Item $i", range(1, 200))));
    }

    public function testMatchingIgnoresCaseDiacriticsAndSpacing(): void
    {
        self::assertSame('tesut si tesut a', Checklist::fold("  Ţesut  ŞI\ttesut  Ă "));
        self::assertTrue(Checklist::mentioned(['menisc  medial'], 'Menisc Medial intact'));
        self::assertTrue(Checklist::mentioned(['revarsat'], 'Fără revărsat.'));
        self::assertFalse(Checklist::mentioned(['lia'], 'Menisc normal.'));
        self::assertNull(Checklist::mentioned([], 'anything'));
    }

    public function testThePromptTextLeavesTheKeywordsOut(): void
    {
        self::assertSame(
            "Menisci:\n- Menisc medial\n- Revărsat",
            Checklist::asText(Checklist::parse(['# Menisci', 'Menisc medial | menisc', 'Revărsat'])),
        );
    }
}
