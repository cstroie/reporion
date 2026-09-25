<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\User;
use Reporion\Auth\UserStoreInterface;
use Reporion\Exception\UserAlreadyExistsException;
use Reporion\Exception\UserNotFoundException;

/**
 * Test double for Session's tests — pure in-memory, so Session stays
 * testable "without a real request" (its own docblock's claim) and
 * without touching disk either.
 */
final class InMemoryUserStore implements UserStoreInterface
{
    /** @var array<string, User> */
    private array $users = [];

    public function put(User $user): void
    {
        $this->users[$user->username] = $user;
    }

    public function find(string $username): ?User
    {
        return $this->users[$username] ?? null;
    }

    public function all(): iterable
    {
        return array_values($this->users);
    }

    public function create(string $username, string $passwordHash, bool $isOwner, array $grants = [], string $displayName = '', string $title = ''): User
    {
        if (isset($this->users[$username])) {
            throw new UserAlreadyExistsException();
        }
        $user = new User($username, $passwordHash, $isOwner, $grants, true, 'now', 'now', $displayName, $title);
        $this->users[$username] = $user;

        return $user;
    }

    public function save(User $user): User
    {
        if (!isset($this->users[$user->username])) {
            throw new UserNotFoundException();
        }
        $this->users[$user->username] = $user;

        return $user;
    }
}
