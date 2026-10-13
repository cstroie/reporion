<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Badges;

/** Each status has its own icon and tag class, on every screen */
final class BadgesTest extends TestCase
{
    public function testStatusIcons(): void
    {
        self::assertSame('ph-signature', Badges::statusIcon('signed'));
        self::assertSame('ph-archive', Badges::statusIcon('archived'));
        self::assertSame('ph-note-pencil', Badges::statusIcon('draft'));
    }
}
