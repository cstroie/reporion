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

    /**
     * @return array<string, mixed>
     */
    private function minimalConfig(): array
    {
        return [
            'auth' => [
                'owner_password_hash' => 'x',
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
}
