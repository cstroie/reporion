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

    /** Phase 31: the form's rows and back — a hand-written list survives, an unreadable line is kept as it was */
    public function testRowsAndLinesRoundTripAndKeepWhatTheFormCannotRead(): void
    {
        $written = ['# Menisci', 'Menisc medial | menisc medial', 'Revărsat articular', ['Cartilaj' => ['cartilaj', 'condral']], ['a' => 1, 'b' => 2], ' | orfan'];
        $rows = Checklist::rows($written);
        self::assertSame(['section', 'item', 'item', 'item', 'raw', 'raw'], array_column($rows, 'kind'));
        self::assertSame(['cartilaj', 'condral'], $rows[3]['keywords'], "YAML's map form reads as an item");
        self::assertSame(['a' => 1, 'b' => 2], $rows[4]['raw']);

        self::assertSame(
            ['# Menisci', 'Menisc medial | menisc medial', 'Revărsat articular', 'Cartilaj | cartilaj, condral', ['a' => 1, 'b' => 2], ' | orfan'],
            Checklist::lines($rows),
            'what the form wrote reads as the list it read; the raw lines unchanged'
        );
        self::assertSame(Checklist::parse($written), Checklist::parse(Checklist::lines($rows)), 'the editor sees the same checklist');
    }

    public function testLinesFromTheFormAreCleanAndEmptyRowsGo(): void
    {
        self::assertSame(
            ['# Ligamente', 'LIA / LIP | lia, lip', 'Tendon'],
            Checklist::lines([
                ['kind' => 'section', 'label' => '## Ligamente'],
                ['kind' => 'item', 'label' => 'LIA | LIP', 'keywords' => ' lia,, lip , lia'],
                ['kind' => 'item', 'label' => '', 'keywords' => 'orphan'],
                ['kind' => 'item', 'label' => 'Tendon', 'keywords' => ''],
            ]),
            'a | in a label cannot start the keywords; duplicates and blanks go'
        );
        self::assertNull(Checklist::lines([['kind' => 'item', 'label' => '  ']]), 'nothing left: the key goes');
    }
}
