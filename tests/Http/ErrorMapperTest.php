<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use PHPUnit\Framework\TestCase;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ErrorMapper;
use RuntimeException;

final class ErrorMapperTest extends TestCase
{
    public function testPageNotFoundBecomes404(): void
    {
        $response = ErrorMapper::map(new PageNotFoundException());

        self::assertSame(404, $response->status);
    }

    public function testAnyOtherThrowableBecomesAGeneric500(): void
    {
        $response = ErrorMapper::map(new RuntimeException('some internal detail'));

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('some internal detail', $response->body);
    }

    /**
     * A 500 with nothing logged anywhere is undiagnosable outside a
     * debugger — this is the fix for exactly that gap.
     */
    public function testA500LeavesATraceInTheErrorLog(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'reporion-error-log-');
        $previousLogSetting = ini_set('error_log', $logFile);
        $previousLogErrorsSetting = ini_set('log_errors', '1');

        try {
            ErrorMapper::map(new RuntimeException('irrelevant'));
            $logged = (string) file_get_contents($logFile);
        } finally {
            ini_set('error_log', $previousLogSetting !== false ? $previousLogSetting : '');
            ini_set('log_errors', $previousLogErrorsSetting !== false ? $previousLogErrorsSetting : '1');
            unlink($logFile);
        }

        self::assertStringContainsString(RuntimeException::class, $logged);
        self::assertStringContainsString(__FILE__, $logged);
    }
}
