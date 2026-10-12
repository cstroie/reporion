<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\SystemActor;

/** The pseudousers are names, not accounts: none can be created as one */
final class SystemActorTest extends TestCase
{
    public function testNoSystemActorIsAValidUsername(): void
    {
        $dir = sys_get_temp_dir() . '/reporion-sysactor-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $store = new FlatFileUserStore($dir);
        foreach (SystemActor::all() as $actor) {
            self::assertTrue(SystemActor::isSystem($actor));
            try {
                $store->create($actor, 'x', false, []);
                self::fail($actor . ' became an account');
            } catch (\Throwable $e) {
                self::assertStringNotContainsString('became an account', $e->getMessage());
            }
        }
        self::assertSame([], glob($dir . '/*') ?: []);
        @rmdir($dir);
        self::assertFalse(SystemActor::isSystem('owner'));
    }

    public function testEachHasABylineString(): void
    {
        foreach (SystemActor::all() as $actor) {
            self::assertNotSame('actor.' . substr($actor, 7), t('actor.' . substr($actor, 7)));
            self::assertNotSame($actor, display_name($actor));
        }
    }
}
