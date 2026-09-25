<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

use Reporion\Exception\UserAlreadyExistsException;
use Reporion\Exception\UserNotFoundException;

/**
 * Account storage (D35/D36). Disk-authoritative — any SQLite cache of this
 * (a `user_grants` table for query-time joins) is a disposable projection
 * built from here, never the other way round (CLAUDE.md invariant 1).
 *
 * Deliberately thin: this is CRUD on the account record only. `User`'s own
 * canRead()/canWrite()/roleOn() are pure predicates over a record's own
 * grants and stay on the domain object on purpose — that split is not an
 * oversight. What belongs elsewhere: password *verification*, session
 * issuance, and turning a request into the right `User` to ask — those are
 * Http\Session's and the controllers' job, not this store's.
 */
interface UserStoreInterface
{
    public function find(string $username): ?User;

    /**
     * @return iterable<User>
     */
    public function all(): iterable;

    /**
     * @param list<Grant> $grants
     *
     * @throws UserAlreadyExistsException
     */
    public function create(string $username, string $passwordHash, bool $isOwner, array $grants = [], string $displayName = '', string $title = ''): User;

    /**
     * Overwrites an existing account record (role, grants, active, or a
     * rotated password hash). $user->updatedAt is ignored and recomputed —
     * callers cannot backdate it. Never creates — use create() for that.
     *
     * @throws UserNotFoundException
     */
    public function save(User $user): User;
}
