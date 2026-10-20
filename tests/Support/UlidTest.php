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

    public function testIsValidAcceptsWhatGenerateWritesOnly(): void
    {
        self::assertTrue(Ulid::isValid(Ulid::generate()));
        foreach (['', 'forged', strtolower(Ulid::generate()), '01JB8X4MT7QK2V9Z0C3R5H6NDI', '01JB8X4MT7QK2V9Z0C3R5H6ND'] as $bad) {
            self::assertFalse(Ulid::isValid($bad), $bad);
        }
    }

    public function testIsUnique(): void
    {
        self::assertNotSame(Ulid::generate(), Ulid::generate());
    }
}
