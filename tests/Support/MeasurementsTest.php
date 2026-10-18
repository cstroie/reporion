<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Measurements;

final class MeasurementsTest extends TestCase
{
    /**
     * The cases the editor's twin (assets/js/editor-aids.js) is tested against too: tests/Render/EditorAidsTest
     *
     * @return array<string, array{string, list<array{text: string, mm: float, unit: string}>}>
     */
    public static function cases(): array
    {
        return [
            'mm' => ['Nodul de 12 mm în lobul drept.', [['text' => '12 mm', 'mm' => 12.0, 'unit' => 'mm']]],
            'cm, comma' => ['Chist de 1,2 cm.', [['text' => '1,2 cm', 'mm' => 12.0, 'unit' => 'cm']]],
            'dimensions' => ['Formațiune 12x8 mm și 3 x 4 x 5 cm', [['text' => '12x8 mm', 'mm' => 12.0, 'unit' => 'mm'], ['text' => '3 x 4 x 5 cm', 'mm' => 50.0, 'unit' => 'cm']]],
            'slash, no space' => ['diametre 12/8mm', [['text' => '12/8mm', 'mm' => 12.0, 'unit' => 'mm']]],
            'not a size' => ['T2, L4-L5, 3 mmHg, 2020', []],
        ];
    }

    /**
     * @dataProvider cases
     *
     * @param list<array{text: string, mm: float, unit: string}> $expected
     */
    public function testReadsTheSizesWritten(string $text, array $expected): void
    {
        self::assertSame($expected, Measurements::of($text));
    }
}
