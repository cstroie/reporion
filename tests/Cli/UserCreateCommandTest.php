<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Auth\FlatFileGroupStore;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\GrantRole;
use Reporion\Cli\GroupCreateCommand;
use Reporion\Cli\Output;
use Reporion\Cli\UserCreateCommand;

final class UserCreateCommandTest extends TestCase
{
    private string $dataRoot;

    /**
     * A real argon2id hash: password_hash() is slow by design, so this is
     * computed once and reused, not recomputed per test.
     */
    private static string $realHash;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$realHash = password_hash('correct-horse', PASSWORD_ARGON2ID);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataRoot = sys_get_temp_dir() . '/reporion-user-create-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataRoot);
        parent::tearDown();
    }

    /** D47: group:create, then user:create --group; a group that does not exist is refused */
    public function testGroupCreateThenAMemberGetsItsGrantsAndRadiologistFlag(): void
    {
        $groups = new FlatFileGroupStore($this->dataRoot);
        [$exit, $out] = $this->runCommand(new GroupCreateCommand($groups), ['--name=radiologists', '--grant=reports:editor', '--radiologist']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('Created @radiologists (radiologists)', $out);
        self::assertSame(1, $this->runCommand(new GroupCreateCommand($groups), ['--name=radiologists'])[0], 'no two with one name');
        self::assertSame(1, $this->runCommand(new GroupCreateCommand($groups), ['--name=Bad Name'])[0]);

        $store = new FlatFileUserStore($this->dataRoot);
        self::assertSame(1, $this->runCommand(new UserCreateCommand($store, $groups), ['--username=x', '--password-hash=' . self::$realHash, '--group=nope'])[0]);
        self::assertNull($store->find('x'), 'nothing created when a group is unknown');

        self::assertSame(0, $this->runCommand(new UserCreateCommand($store, $groups), ['--username=ana', '--password-hash=' . self::$realHash, '--group=radiologists'])[0]);
        $ana = (new FlatFileUserStore($this->dataRoot))->find('ana');
        self::assertSame(['radiologists'], $ana?->groups);
        self::assertTrue($ana?->isRadiologist());
        self::assertTrue($ana?->canSign('reports:mri:s:260101-x'));
    }

    public function testCreatesAnOwnerAccount(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        $command = new UserCreateCommand($store);

        [$exitCode, $output] = $this->runCommand($command, ['--username=root', '--password-hash=' . self::$realHash, '--owner']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Created root (owner)', $output);

        $user = $store->find('root');
        self::assertNotNull($user);
        self::assertTrue($user->isOwner);
        self::assertSame(self::$realHash, $user->passwordHash);
    }

    public function testCreatesANonOwnerAccountWithGrants(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        $command = new UserCreateCommand($store);

        [$exitCode] = $this->runCommand($command, [
            '--username=mihai',
            '--password-hash=' . self::$realHash,
            '--grant=reports:mri:editor',
            '--grant=reports:ct:viewer',
        ]);

        self::assertSame(0, $exitCode);

        $user = $store->find('mihai');
        self::assertNotNull($user);
        self::assertFalse($user->isOwner);
        self::assertCount(2, $user->grants);
        self::assertSame('reports:mri', $user->grants[0]->namespace);
        self::assertSame(GrantRole::Editor, $user->grants[0]->role);
        self::assertSame('reports:ct', $user->grants[1]->namespace);
        self::assertSame(GrantRole::Viewer, $user->grants[1]->role);
    }

    /** Only a radiologist signs (D37, amended 2026-10-13): --radiologist sets the flag, and nothing else does */
    public function testRadiologistOptionMarksTheAccount(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        $command = new UserCreateCommand($store);

        [$exitCode, $output] = $this->runCommand($command, ['--username=root', '--password-hash=' . self::$realHash, '--owner', '--radiologist']);
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Created root (owner, radiologist)', $output);
        self::assertTrue($store->find('root')?->radiologist);

        [, $plain] = $this->runCommand($command, ['--username=mihai', '--password-hash=' . self::$realHash, '--grant=reports:mri:editor']);
        self::assertStringContainsString('Created mihai', $plain);
        self::assertStringNotContainsString('radiologist', $plain);
        self::assertFalse($store->find('mihai')?->radiologist, 'not a radiologist unless asked');
        self::assertCount(1, $store->find('mihai')?->grants ?? [], 'the grants survive the second write');
    }

    public function testMissingUsernameFails(): void
    {
        $command = new UserCreateCommand(new FlatFileUserStore($this->dataRoot));

        [$exitCode, $output] = $this->runCommand($command, ['--password-hash=x']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Usage:', $output);
    }

    public function testDuplicateUsernameFails(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        $store->create('root', self::$realHash, true);
        $command = new UserCreateCommand($store);

        [$exitCode, $output] = $this->runCommand($command, ['--username=root', '--password-hash=' . self::$realHash]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('already exists', $output);
    }

    public function testInvalidGrantRoleFails(): void
    {
        $command = new UserCreateCommand(new FlatFileUserStore($this->dataRoot));

        [$exitCode, $output] = $this->runCommand($command, [
            '--username=mihai',
            '--password-hash=' . self::$realHash,
            '--grant=reports:mri:admin',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid grant role', $output);
    }

    public function testPlaintextPasswordHashIsRejected(): void
    {
        $command = new UserCreateCommand(new FlatFileUserStore($this->dataRoot));

        [$exitCode, $output] = $this->runCommand($command, ['--username=root', '--password-hash=hunter2']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('does not look like a password_hash()', $output);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string}
     */
    private function runCommand(\Reporion\Cli\CommandInterface $command, array $args): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);

        $exitCode = $command->run($args, new Output($stdout, $stderr));

        rewind($stdout);
        rewind($stderr);
        $output = (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);

        return [$exitCode, $output];
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
}
