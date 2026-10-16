<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Manifest;

/** The `editor_tool` slot (2026-10-16): a button in the editor rail's Tools section, naming the function its script adds. */
final class ManifestEditorToolTest extends TestCase
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

    /** @param array<string, mixed> $ui */
    private function manifest(array $ui): Manifest
    {
        file_put_contents($this->dir . '/demo/plugin.json', (string) json_encode(['id' => 'demo', 'api' => 1, 'ui' => $ui]));

        return Manifest::fromFile($this->dir . '/demo/plugin.json', 'demo');
    }

    public function testAnEditorToolKeepsItsToolHelpAndScript(): void
    {
        $manifest = $this->manifest(['editor_tool' => [['tool' => 'fixThings', 'label' => 'demo.fix', 'help' => 'demo.fix_help', 'icon' => 'broom', 'href' => '/x/demo/js/tools.js']]]);

        self::assertSame(
            [['label' => 'demo.fix', 'icon' => 'broom', 'href' => '/x/demo/js/tools.js', 'for' => 'report', 'tool' => 'fixThings', 'help' => 'demo.fix_help']],
            $manifest->ui['editor_tool']
        );
    }

    /** @dataProvider badEntries */
    public function testABadEditorToolIsRefused(array $entry): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->manifest(['editor_tool' => [$entry]]);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function badEntries(): iterable
    {
        yield 'no tool' => [['label' => 'demo.x', 'href' => '/x/demo/js/t.js']];
        yield 'a tool that is not a function name' => [['tool' => 'a-b', 'label' => 'demo.x', 'href' => '/x/demo/js/t.js']];
        yield 'a script outside the plugin' => [['tool' => 'fix', 'label' => 'demo.x', 'href' => '/assets/js/t.js']];
    }

    public function testToolBelongsToTheEditorToolSlotOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->manifest(['export_action' => [['tool' => 'fix', 'label' => 'demo.x', 'href' => '/x/demo/e']]]);
    }
}
