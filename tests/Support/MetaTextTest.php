<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\MetaText;

final class MetaTextTest extends TestCase
{
    public function testScalarsListsAndMapsBecomeText(): void
    {
        self::assertSame('', MetaText::text(null));
        self::assertSame('1970', MetaText::text(1970));
        self::assertSame('yes', MetaText::text(true));
        self::assertSame('MR, CT', MetaText::text(['MR', 'CT']));
        self::assertSame('reports:a, reports:b', MetaText::text([['path' => 'reports:a'], ['path' => 'reports:b']]));
        self::assertSame('a', MetaText::text(['a', null, '']));
    }

    public function testDateAcceptsStringsAndYamlTimestamps(): void
    {
        self::assertSame('01 Sep 2026', MetaText::date('2026-09-01T09:30:00+03:00', 'd M Y'));
        self::assertSame('01 Sep 2026', MetaText::date(1788220800, 'd M Y'));
    }

    public function testUnparseableDateIsShownAsWritten(): void
    {
        self::assertSame('sometime in 2025', MetaText::date('sometime in 2025', 'd M Y'));
        self::assertSame('', MetaText::date(null, 'd M Y'));
    }
}
