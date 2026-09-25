<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Reporion\Audit\AuditLog;
use Reporion\Http\Request;

final class AuditLogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/reporion-audit-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testALineCarriesThePathHashNeverThePath(): void
    {
        $log = new AuditLog($this->dir);
        $request = new Request('POST', '/x', remoteAddr: '10.0.0.7', userAgent: 'TestAgent/1');

        $log->record('page.save', 'mihai', $request, '01PID', 'reports:mri:site:260101-some-name', 3);

        $raw = (string) file_get_contents($this->dir . '/' . date('Y-m') . '.ndjson');
        $line = json_decode(trim($raw), true);
        self::assertSame('page.save', $line['action']);
        self::assertSame('mihai', $line['actor']);
        self::assertSame('01PID', $line['pid']);
        self::assertSame(3, $line['rev']);
        self::assertSame('10.0.0.7', $line['ip']);
        self::assertSame('TestAgent/1', $line['ua']);
        self::assertSame('ok', $line['outcome']);
        self::assertSame('sha256:' . hash('sha256', 'reports:mri:site:260101-some-name'), $line['path_hash']);
        self::assertStringNotContainsString('some-name', $raw);
    }

    public function testLinesAreAppendedNeverRewritten(): void
    {
        $log = new AuditLog($this->dir);
        $log->record('login', 'a');
        $log->record('login', 'b');

        $lines = file($this->dir . '/' . date('Y-m') . '.ndjson', FILE_IGNORE_NEW_LINES);
        self::assertCount(2, $lines);
        self::assertSame('a', json_decode($lines[0], true)['actor']);
    }

    public function testAnUnwritableDirectoryNeverThrows(): void
    {
        file_put_contents($this->dir, 'a file where the directory should be');
        $log = new AuditLog($this->dir . '/nested');

        $log->record('page.save', 'mihai');

        self::assertFileDoesNotExist($this->dir . '/nested');
        unlink($this->dir);
        mkdir($this->dir);
    }
}
