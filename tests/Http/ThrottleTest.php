<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use PHPUnit\Framework\TestCase;
use Reporion\Http\Throttle;

final class ThrottleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/reporion-throttle-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testAClaimIsGoodOnce(): void
    {
        $throttle = new Throttle($this->dir . '/t.json');

        self::assertTrue($throttle->claim('code:a', 300));
        self::assertFalse($throttle->claim('code:a', 300));
        self::assertTrue($throttle->claim('code:b', 300));
    }

    public function testAClaimFailsClosedWhenTheNoteCannotBeWritten(): void
    {
        mkdir($this->dir);
        mkdir($this->dir . '/t.json.lock'); // a directory where the lock file should be: fopen fails

        self::assertFalse((new Throttle($this->dir . '/t.json'))->claim('code:a', 300));
    }
}
