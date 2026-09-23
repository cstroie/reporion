<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Ulid;

final class UlidTest extends TestCase
{
    public function testGeneratesTwentySixCrockfordBase32Characters(): void
    {
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', Ulid::generate());
    }

    public function testIsLexicographicallySortableByTime(): void
    {
        $earlier = Ulid::generate(1000);
        $later = Ulid::generate(2000);

        self::assertLessThan($later, $earlier);
    }

    public function testIsUnique(): void
    {
        self::assertNotSame(Ulid::generate(), Ulid::generate());
    }
}
