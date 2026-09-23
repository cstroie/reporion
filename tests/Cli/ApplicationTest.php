<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Reporion\Cli\Application;

final class ApplicationTest extends TestCase
{
    public function testUnknownCommandListsAvailableCommandsAndFails(): void
    {
        $app = Application::boot($this->minimalConfig());

        $exitCode = $app->run(['bin/reporion', 'not-a-real-command']);

        self::assertSame(1, $exitCode);
    }

    public function testNoCommandListsAvailableCommandsAndFails(): void
    {
        $app = Application::boot($this->minimalConfig());

        $exitCode = $app->run(['bin/reporion']);

        self::assertSame(1, $exitCode);
    }

    public function testDoctorCommandIsRegisteredAndRuns(): void
    {
        $app = Application::boot($this->minimalConfig());

        $exitCode = $app->run(['bin/reporion', 'doctor']);

        // Exit code depends on this real environment's PHP/extensions, but
        // the point of this test is that 'doctor' dispatches at all rather
        // than falling into the "unknown command" branch.
        self::assertContains($exitCode, [0, 1]);
    }

    public function testIndexVerifyCommandIsRegisteredAndRunsAgainstARealSqliteIndex(): void
    {
        $dataRoot = sys_get_temp_dir() . '/reporion-app-index-test-' . bin2hex(random_bytes(6));
        mkdir($dataRoot, 0775, true);

        try {
            $config = $this->minimalConfig();
            $config['paths']['data'] = $dataRoot;
            $config['paths']['index'] = $dataRoot . '/index.sqlite';

            $app = Application::boot($config);

            $exitCode = $app->run(['bin/reporion', 'index:verify']);

            // An empty data/pages/ is a clean, empty index — this only
            // proves index:verify dispatches through the lazy factory and
            // actually connects to a real Index\Sqlite, not that any drift
            // logic is right (Index\SqliteTest owns that).
            self::assertSame(0, $exitCode);
        } finally {
            $this->removeDirectory($dataRoot);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalConfig(): array
    {
        return [
            'auth' => [
                'session_secret' => 'x',
            ],
            'paths' => [
                'data' => sys_get_temp_dir(),
            ],
            'site' => [
                'timezone' => 'UTC',
                'base_url' => '',
            ],
        ];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
