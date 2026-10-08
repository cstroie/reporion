<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Ai;

use PHPUnit\Framework\TestCase;
use Reporion\Service\Ai\EntityFilter;

final class EntityFilterTest extends TestCase
{
    public function testWhatContextEscapedComesBackEvenSplitAcrossChunks(): void
    {
        $filter = new EntityFilter();
        $out = '';
        foreach (['Nodul &', 'l', 't; 5 mm, ', 'chist &g', 't; 2 cm &amp; R&D'] as $chunk) {
            $out .= $filter->push($chunk);
        }
        self::assertSame('Nodul < 5 mm, chist > 2 cm &amp; R&D', $out . $filter->finish());
    }

    public function testAPartialEntityAtTheEndIsJustText(): void
    {
        $filter = new EntityFilter();
        self::assertSame('A ', $filter->push('A &l'));
        self::assertSame('&l', $filter->finish());
    }
}
