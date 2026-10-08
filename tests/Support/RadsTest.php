<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Rads;

/**
 * RADS categories as tags (roadmap phase 34h): each system's spellings and
 * categories, read from the conclusion, history and negation left out.
 * Conclusions are fictitious (invariant 10).
 */
final class RadsTest extends TestCase
{
    /** @return array<string, array{string, list<string>}> */
    public static function conclusions(): array
    {
        $c = static fn (string $text): string => "# TEST\n\n## Exam\n\nDescriere.\n\n### Concluzii\n\n" . $text . "\n";

        return [
            'bi-rads sub-category' => [$c('Leziune nodulară sân drept, BI-RADS 4A.'), ['rads:birads-4a']],
            'bi-rads 0, no hyphen' => [$c('BIRADS: 0 bilateral.'), ['rads:birads-0']],
            'roman, one per side' => [$c('BI-RADS categoria IV dreapta; BI-RADS 2 stânga.'), ['rads:birads-4', 'rads:birads-2']],
            'history before' => [$c('Anterior BI-RADS 3, actual BI-RADS 4B.'), ['rads:birads-4b']],
            'history after a colon' => [$c('BI-RADS 2 (examinarea precedentă: BI-RADS 3).'), ['rads:birads-2']],
            'history in a short bracket' => [$c('BI-RADS 3 (anterior), actual BI-RADS 4C.'), ['rads:birads-4c']],
            'negated, and density letters' => [$c('Nu BI-RADS 4. Densitate BI-RADS B, BI-RADS 1.'), ['rads:birads-1']],
            'a normal finding is no negation' => [$c('Fără modificări, BI-RADS 1.'), ['rads:birads-1']],
            'pi-rads with a version' => [$c('Leziune zona periferică, PI-RADS v2.1 scor 4.'), ['rads:pirads-4']],
            'li-rads and a bare LR' => [$c('Nodul segment VII, LI-RADS LR-5; alt nodul LR-M.'), ['rads:lirads-5', 'rads:lirads-m']],
            'lung-rads' => [$c('Lung-RADS 4X.'), ['rads:lungrads-4x']],
            'acr and eu ti-rads' => [$c('Nodul ACR TI-RADS TR4; EU-TIRADS 5 controlateral.'), ['rads:tirads-4', 'rads:eutirads-5']],
            'o-rads' => [$c('Formațiune anexială O-RADS 3.'), ['rads:orads-3']],
            'not a category' => [$c('BI-RADS 7, PI-RADS 6.'), []],
            'the conclusion only' => ["### Indicație\n\nBI-RADS 3.\n\n### Concluzii\n\nBI-RADS 2.\n", ['rads:birads-2']],
            'no conclusion: the whole text' => ["# TEST\n\n## Mamografie\n\nAspect BI-RADS 2.\n", ['rads:birads-2']],
        ];
    }

    /**
     * @param list<string> $expected
     *
     * @dataProvider conclusions
     */
    public function testTags(string $body, array $expected): void
    {
        self::assertSame($expected, Rads::tags($body));
    }

    public function testMergeSpellsAModelsPhrasingAsATagAndAddsEachOnce(): void
    {
        self::assertSame(
            ['mamografie', 'rads:birads-4a', 'rads:birads-2'],
            Rads::merge(['mamografie', 'BI-RADS 4A', 'rads:birads-2'], ['rads:birads-4a', 'rads:birads-2']),
        );
    }
}
