<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Asset;

final class AssetTest extends TestCase
{
    public function testTheVersionFollowsTheFile(): void
    {
        $mtime = (int) filemtime(\dirname(__DIR__, 2) . '/assets/css/wiki.css');

        self::assertSame('/reporion/assets/css/wiki.css?v=' . dechex($mtime), Asset::url('/reporion', 'css/wiki.css'));
        self::assertSame('/assets/css/nope.css', Asset::url('', 'css/nope.css'), 'a missing file gets no version');
    }
}
