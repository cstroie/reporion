<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

use DateTimeImmutable;
use FilesystemIterator;
use InvalidArgumentException;
use Reporion\Exception\UserAlreadyExistsException;
use Reporion\Exception\UserNotFoundException;
use Reporion\Storage\AtomicWriter;
use RuntimeException;

/**
 * `data/users/{username}.json`, one file per account (D36) — the same
 * "disk is authoritative" shape as a page, minus revisions: an account has
 * no history requirement, so a single atomic overwrite (AtomicWriter::put(),
 * the same temp+fsync+rename primitive Storage\FlatFile uses for
 * current.md) is enough. No journal: unlike a page write, one account
 * record is one file, so there is no multi-file sequence a crash could
 * leave half-done.
 */
final class FlatFileUserStore implements UserStoreInterface
{
    public function __construct(
        private readonly string $dataRoot,
    ) {
    }

    public function find(string $username): ?User
    {
        $this->assertValidUsername($username);
        $file = $this->userPath($username);

        if (!is_file($file)) {
            return null;
        }

        return $this->decode($this->readFile($file), $file);
    }

    public function all(): iterable
    {
        $usersRoot = $this->dataRoot . '/users';
        if (!is_dir($usersRoot)) {
            return;
        }

        $iterator = new FilesystemIterator($usersRoot, FilesystemIterator::SKIP_DOTS);
        $names = [];
        foreach ($iterator as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), '.json')) {
                $names[] = $item->getFilename();
            }
        }
        sort($names);

        foreach ($names as $name) {
            $file = $usersRoot . '/' . $name;
            yield $this->decode($this->readFile($file), $file);
        }
    }

    public function create(string $username, string $passwordHash, bool $isOwner, array $grants = []): User
    {
        $this->assertValidUsername($username);
        if (is_file($this->userPath($username))) {
            throw new UserAlreadyExistsException();
        }

        $now = self::now();
        $user = new User($username, $passwordHash, $isOwner, $grants, true, $now, $now);
        $this->write($user);

        return $user;
    }

    public function save(User $user): User
    {
        $this->assertValidUsername($user->username);
        if (!is_file($this->userPath($user->username))) {
            throw new UserNotFoundException();
        }

        $updated = new User(
            $user->username,
            $user->passwordHash,
            $user->isOwner,
            $user->grants,
            $user->active,
            $user->createdAt,
            self::now(),
        );
        $this->write($updated);

        return $updated;
    }

    private function write(User $user): void
    {
        $usersRoot = $this->dataRoot . '/users';
        if (!is_dir($usersRoot) && !mkdir($usersRoot, 0775, true) && !is_dir($usersRoot)) {
            throw new RuntimeException('Cannot create users directory');
        }

        $json = json_encode([
            'username' => $user->username,
            'password_hash' => $user->passwordHash,
            'is_owner' => $user->isOwner,
            'grants' => array_map(
                static fn (Grant $grant): array => ['namespace' => $grant->namespace, 'role' => $grant->role->value],
                $user->grants
            ),
            'active' => $user->active,
            'created' => $user->createdAt,
            'updated' => $user->updatedAt,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        AtomicWriter::put($this->userPath($user->username), $json . "\n");
    }

    /**
     * A distinct failure from "corrupt JSON" (decode()) — file_get_contents()
     * returning false on an is_file()-confirmed path means a *read* failed
     * (permissions is exactly what bit this project twice already on the
     * live box: costin vs www-data ownership). Casting that false to an
     * empty string would make decode() report "corrupt record" for a file
     * that is perfectly fine, sending whoever investigates down the wrong
     * path entirely.
     */
    private function readFile(string $file): string
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('User record unreadable');
        }

        return $contents;
    }

    private function decode(string $raw, string $sourceFile): User
    {
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            throw new RuntimeException('Corrupt user record: ' . basename($sourceFile));
        }

        $grants = [];
        foreach ((array) ($decoded['grants'] ?? []) as $grantData) {
            if (!\is_array($grantData) || !isset($grantData['namespace'], $grantData['role'])) {
                throw new RuntimeException('Corrupt user record: ' . basename($sourceFile));
            }
            $role = GrantRole::tryFrom((string) $grantData['role']);
            if ($role === null) {
                throw new RuntimeException('Corrupt user record: ' . basename($sourceFile));
            }
            $grants[] = new Grant((string) $grantData['namespace'], $role);
        }

        return new User(
            (string) $decoded['username'],
            (string) $decoded['password_hash'],
            (bool) $decoded['is_owner'],
            $grants,
            (bool) $decoded['active'],
            (string) $decoded['created'],
            (string) $decoded['updated'],
        );
    }

    private function userPath(string $username): string
    {
        return $this->dataRoot . '/users/' . $username . '.json';
    }

    /**
     * Username becomes a filename directly, so this is the only thing
     * standing between a bad username and path traversal — matches
     * Storage\FlatFile::assertValidPath()'s strictness for the same reason.
     */
    private function assertValidUsername(string $username): void
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9_.-]{0,62}[a-z0-9])?$/', $username) !== 1) {
            throw new InvalidArgumentException('Invalid username');
        }
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');
    }
}
