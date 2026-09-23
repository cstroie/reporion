<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reporion\Support\Slug;

final class SlugTest extends TestCase
{
    public function testFoldsDiacriticsAndLowercases(): void
    {
        self::assertSame('ionescu-maria', Slug::normalize('Ionescu Mária'));
    }

    public function testCollapsesNonAlphanumericsToSingleHyphen(): void
    {
        self::assertSame('a-b-c', Slug::normalize('a---b   c!!'));
    }

    public function testTrimsToSixtyFourCharacters(): void
    {
        $long = str_repeat('a', 100);

        self::assertSame(64, \strlen(Slug::normalize($long)));
    }

    public function testRejectsInputThatNormalisesToEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Slug::normalize('---');
    }
}
