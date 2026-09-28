<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Snippet;

final class SnippetTest extends TestCase
{
    public function testShortTextIsUnchanged(): void
    {
        self::assertSame('short text', Snippet::words('short text', 30));
    }

    public function testCutsAtAWordBoundaryUnderTheLimit(): void
    {
        self::assertSame('Nodul tiroidian drept, fara…', Snippet::words('Nodul tiroidian drept, fara semne de malignitate', 30));
    }

    public function testASingleWordLongerThanTheLimitIsHardCut(): void
    {
        self::assertSame('Supercalifragilisticexpialidoc…', Snippet::words('Supercalifragilisticexpialidocious', 30));
    }

    public function testCollapsesWhitespace(): void
    {
        self::assertSame('a b', Snippet::words("a\n\n  b", 30));
    }

    public function testEmptyStringStaysEmpty(): void
    {
        self::assertSame('', Snippet::words('', 30));
    }
}
