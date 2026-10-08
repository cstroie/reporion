<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Manifest;

/** plugin.json `commands` and a ui slot holding a list: what a manifest may say. */
final class ManifestCommandsTest extends TestCase
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

    /** @param array<string, mixed> $extra */
    private function manifest(array $extra): Manifest
    {
        file_put_contents($this->dir . '/demo/plugin.json', (string) json_encode(['id' => 'demo', 'api' => 1] + $extra));

        return Manifest::fromFile($this->dir . '/demo/plugin.json', 'demo');
    }

    public function testCommandsAreNamedClasses(): void
    {
        self::assertSame(['demo:run' => 'RunCommand'], $this->manifest(['commands' => ['demo:run' => 'RunCommand']])->commands);
        self::assertSame([], $this->manifest([])->commands);
    }

    public function testACommandNameOrClassThatIsNotOneIsRefused(): void
    {
        foreach ([['run' => 'RunCommand'], ['demo:run' => '../Run'], ['demo:run' => 'Cli\\Run'], ['Demo:Run' => 'Run']] as $bad) {
            try {
                $this->manifest(['commands' => $bad]);
                self::fail('accepted ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testASlotMayHoldOneEntryOrAList(): void
    {
        $one = $this->manifest(['ui' => ['new_report' => ['label' => 'a', 'href' => '/x/demo/a']]]);
        $two = $this->manifest(['ui' => ['new_report' => [['label' => 'a', 'href' => '/x/demo/a'], ['label' => 'b', 'icon' => 'star', 'href' => '/x/demo/b']]]]);

        self::assertCount(1, $one->ui['new_report']);
        self::assertSame(['/x/demo/a', '/x/demo/b'], array_column($two->ui['new_report'], 'href'));
        $this->expectException(InvalidArgumentException::class);
        $this->manifest(['ui' => ['new_report' => [['label' => 'a', 'href' => '/x/other/a']]]]);
    }
}
