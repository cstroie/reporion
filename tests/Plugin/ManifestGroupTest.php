<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Manifest;

/** A setting's optional `group` (2026-10-15): the label of the fieldset Admin → Plugins shows it in. */
final class ManifestGroupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/reporion-manifest-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/demo', 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/demo/plugin.json');
        @rmdir($this->dir . '/demo');
        @rmdir($this->dir);
    }

    /** @param array<string, mixed> $settings */
    private function manifest(array $settings): Manifest
    {
        file_put_contents($this->dir . '/demo/plugin.json', (string) json_encode(['id' => 'demo', 'api' => 1, 'settings' => $settings]));

        return Manifest::fromFile($this->dir . '/demo/plugin.json', 'demo');
    }

    public function testAGroupIsKeptWithItsSetting(): void
    {
        $manifest = $this->manifest(['a' => ['type' => 'text', 'group' => 'Programs'], 'b' => ['type' => 'int']]);

        self::assertSame('Programs', $manifest->settings['a']['group']);
        self::assertArrayNotHasKey('group', $manifest->settings['b'], 'no group, as before');
    }

    public function testAnEmptyOrNonTextOrOverlongGroupIsRefused(): void
    {
        foreach ([' ', '', 5, ['x'], str_repeat('x', 61)] as $bad) {
            try {
                $this->manifest(['a' => ['type' => 'text', 'group' => $bad]]);
                self::fail('refused');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
