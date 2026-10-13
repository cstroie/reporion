<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

/**
 * Group storage (D47): CRUD on `data/groups/{name}.json` only. Who belongs
 * to a group is on the account (`User::$groups`), not here — one place to
 * change, and an account record stays the whole truth about that account.
 */
interface GroupStoreInterface
{
    public function find(string $name): ?Group;

    /**
     * @return list<Group> sorted by name
     */
    public function all(): array;

    /**
     * Writes a new group, or overwrites one; updatedAt is recomputed.
     */
    public function save(Group $group): Group;

    public function delete(string $name): void;
}
