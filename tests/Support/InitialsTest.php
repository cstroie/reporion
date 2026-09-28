<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Initials;

final class InitialsTest extends TestCase
{
    public function testTwoWordsGiveOneLetterEach(): void
    {
        self::assertSame('CS', Initials::of('Costin Stroie'));
    }

    public function testALeadingHonorificIsSkipped(): void
    {
        self::assertSame('CS', Initials::of('Dr. Costin Stroie'));
        self::assertSame('CS', Initials::of('Dr Costin Stroie'));
        self::assertSame('AI', Initials::of('Prof. Ana Ionescu'));
    }

    public function testOneWordGivesOneLetter(): void
    {
        self::assertSame('C', Initials::of('cstroie'));
    }

    public function testOnlyTheFirstTwoRealWordsCount(): void
    {
        self::assertSame('CM', Initials::of('Costin Marius Stroie'));
    }

    public function testBlankGivesNothing(): void
    {
        self::assertSame('', Initials::of(''));
        self::assertSame('', Initials::of('   '));
    }

    public function testDiacriticsAreUppercasedCorrectly(): void
    {
        self::assertSame('ĂN', Initials::of('ănuță Nedelcu'));
    }
}
