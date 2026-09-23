<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\User;
use Reporion\Cli\DoctorCommand;
use Reporion\Cli\Output;

final class DoctorCommandTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = sys_get_temp_dir() . '/reporion-doctor-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public function testAllPassingConfigExitsZero(): void
    {
        $this->createOwnerAccount();
        $command = new DoctorCommand($this->config());

        [$exitCode, $output] = $this->runCommand($command);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('[PASS] PHP version', $output);
        self::assertStringContainsString('[PASS] At least one active owner account exists', $output);
        self::assertStringContainsString('[PASS] data/ writable', $output);
    }

    public function testNoOwnerAccountFails(): void
    {
        // No createOwnerAccount() call: data/users/ stays empty.
        $command = new DoctorCommand($this->config());

        [$exitCode, $output] = $this->runCommand($command);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] At least one active owner account exists', $output);
    }

    public function testAnOwnerAccountThatIsDeactivatedStillFails(): void
    {
        $this->createOwnerAccount(active: false);
        $command = new DoctorCommand($this->config());

        [$exitCode, $output] = $this->runCommand($command);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] At least one active owner account exists', $output);
    }

    public function testEmptySessionSecretFails(): void
    {
        $config = $this->config();
        $config['auth']['session_secret'] = '';
        $command = new DoctorCommand($config);

        [$exitCode, $output] = $this->runCommand($command);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] Session secret configured', $output);
    }

    public function testInvalidTimezoneFails(): void
    {
        $config = $this->config();
        $config['site']['timezone'] = 'Not/A/Real/Zone';
        $command = new DoctorCommand($config);

        [$exitCode, $output] = $this->runCommand($command);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] Timezone configured', $output);
    }

    /**
     * The bug this guards against: is_writable() alone would not have
     * caught tonight's real permission mismatch (a directory group-writable
     * for www-data while a specific file inside it was still costin-owned,
     * 644). A real write-and-delete is the only check narrow enough to
     * match what actually broke.
     */
    public function testUnwritableDataDirectoryFails(): void
    {
        chmod($this->dataDir, 0555);
        $command = new DoctorCommand($this->config());

        try {
            [$exitCode, $output] = $this->runCommand($command);
        } finally {
            // Restore regardless of assertion outcome, or a failed
            // assertion here leaves tearDown() unable to remove the
            // directory and a stray fixture behind.
            chmod($this->dataDir, 0775);
        }

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] data/ writable', $output);
    }

    public function testMissingDataDirectoryFails(): void
    {
        $config = $this->config();
        $config['paths']['data'] = $this->dataDir . '/does-not-exist';
        $command = new DoctorCommand($config);

        [$exitCode, $output] = $this->runCommand($command);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] data/ writable', $output);
    }

    /**
     * The bug this guards against: an earlier version of this check only
     * looked at the HTTP status code, which is ambiguous — the app's own
     * /{path} route matches "/.git/config" too and 404s on it exactly like
     * a correctly-configured docroot would. This spins up a real server
     * exposing an actual .git/config (mirroring exactly what leaked earlier
     * tonight) and proves the content signature, not just the status code,
     * is what FAILs the check.
     */
    public function testDetectsARealExposedGitConfigLiveOverHttp(): void
    {
        $docroot = $this->dataDir . '/fake-docroot';
        mkdir($docroot . '/.git', 0775, true);
        file_put_contents($docroot . '/.git/config', "[core]\n\trepositoryformatversion = 0\n");

        $port = 8900 + random_int(0, 90);
        $process = proc_open(
            [\PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        usleep(300_000); // let the built-in server finish starting

        try {
            $config = $this->config();
            $config['site']['base_url'] = "http://127.0.0.1:{$port}";
            $command = new DoctorCommand($config);

            [$exitCode, $output] = $this->runCommand($command);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('[FAIL] data/.git not web-exposed', $output);
    }

    public function testUnreachableBaseUrlWarnsRatherThanFails(): void
    {
        $this->createOwnerAccount();
        $config = $this->config();
        $config['site']['base_url'] = 'http://127.0.0.1:1'; // reserved, nothing listens
        $command = new DoctorCommand($config);

        [$exitCode, $output] = $this->runCommand($command);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('[WARN] data/.git not web-exposed', $output);
        self::assertStringContainsString('[WARN] Front controller reachable', $output);
    }

    private function createOwnerAccount(bool $active = true): void
    {
        $store = new FlatFileUserStore($this->dataDir);
        $user = $store->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        if (!$active) {
            $store->save(new User(
                $user->username,
                $user->passwordHash,
                $user->isOwner,
                $user->grants,
                false,
                $user->createdAt,
                $user->updatedAt,
            ));
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runCommand(DoctorCommand $command): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);

        $exitCode = $command->run([], new Output($stdout, $stderr));

        rewind($stdout);
        $output = (string) stream_get_contents($stdout);
        fclose($stdout);
        fclose($stderr);

        return [$exitCode, $output];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'auth' => [
                'session_secret' => 'x',
            ],
            'paths' => [
                'data' => $this->dataDir,
            ],
            'site' => [
                'timezone' => 'Europe/Bucharest',
                'base_url' => '',
            ],
        ];
    }
}
