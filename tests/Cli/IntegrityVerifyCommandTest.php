<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Cli\IntegrityVerifyCommand;
use Reporion\Cli\Output;
use Reporion\Index\Sqlite;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * bin/reporion integrity:verify — text and --json, exit 1 on any problem
 * (for cron), pages by pid only (invariant 8). Fixture data is fictitious.
 */
final class IntegrityVerifyCommandTest extends StorageTestCase
{
    private const PATH = 'reports:mri:mioveni:260920-test-unu';

    public function testCleanAndDamagedArchives(): void
    {
        $index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $storage = new FlatFile($this->dataRoot, $index);
        $storage->create(self::PATH, ['title' => 'TEST Unu', 'visibility' => 'private'], "# TEST Unu\n", 'owner');
        $storage->sign(self::PATH, 'owner', []);
        $command = new IntegrityVerifyCommand(MaintenanceRunner::standard($storage, $index, new AuditLog($this->dataRoot . '/audit'), $this->dataRoot, 30));

        [$exit, $out] = $this->runCommand($command, []);
        self::assertSame(0, $exit);
        self::assertStringContainsString('1 page(s), 1 revision(s), 1 signature(s), 0 media file(s) — all intact', $out);

        $dir = $this->dataRoot . '/pages/' . str_replace(':', '/', self::PATH);
        file_put_contents($dir . '/rev/0001.md.gz', (string) gzencode("---\ntitle: X\n---\n\nchanged\n"));
        [$exit, $out] = $this->runCommand($command, []);
        self::assertSame(1, $exit, 'cron sees the failure');
        self::assertStringContainsString('signature_mismatch', $out);
        self::assertStringContainsString($storage->read(self::PATH)->pid . ' rev 1', $out);
        self::assertStringNotContainsString('test-unu', $out, 'never a path (invariant 8)');

        [$exit, $json] = $this->runCommand($command, ['--json', '--backup=/nonexistent']);
        self::assertSame(1, $exit);
        $data = json_decode($json, true);
        self::assertSame('integrity:verify', $data['task']);
        self::assertSame('/nonexistent', $data['options']['backup']);
        self::assertGreaterThan(0, $data['summary']['backup_unreadable']);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string}
     */
    private function runCommand(IntegrityVerifyCommand $command, array $args): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);
        $exit = $command->run($args, new Output($stdout, $stderr));
        rewind($stdout);
        $out = (string) stream_get_contents($stdout);
        fclose($stdout);
        fclose($stderr);

        return [$exit, $out];
    }
}
