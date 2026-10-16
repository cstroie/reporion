<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\SignatureTitle;

final class SignatureTitleTest extends TestCase
{
    public function testBreaksAtTheMarkAndDropsEmptyLines(): void
    {
        self::assertSame(['Medic primar', 'Radiologie-Imagistică Medicală'], SignatureTitle::lines('Medic primar | Radiologie-Imagistică Medicală'));
        self::assertSame(['A'], SignatureTitle::lines(' A || '));
        self::assertSame([], SignatureTitle::lines(''));
    }

    public function testInlineJoinsTheLines(): void
    {
        self::assertSame('Medic primar · Radiologie', SignatureTitle::inline('Medic primar|Radiologie'));
    }
}
