<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

use InvalidArgumentException;

/**
 * An account record, straight from `data/users/{username}.json` (D36) —
 * disk-authoritative, same as a page. Immutable: every change (role,
 * grants, active) goes through UserStoreInterface::save() with a whole new
 * instance, never a setter.
 */
final class User
{
    /**
     * @param list<Grant> $grants ignored when $isOwner is true — an owner's
     *                            access is instance-wide, not grant-based
     */
    public function __construct(
        public readonly string $username,
        public readonly string $passwordHash,
        public readonly bool $isOwner,
        public readonly array $grants,
        public readonly bool $active,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
        // roleOn() calls Grant::covers() on every element without a further
        // type check — a bare string or anything non-Grant slipping in here
        // would fatal inside the one predicate that decides who can read a
        // private report, not at construction time where it's easy to spot.
        foreach ($grants as $grant) {
            if (!$grant instanceof Grant) {
                throw new InvalidArgumentException('grants must be a list of Grant');
            }
        }
    }

    /**
     * Highest role this user holds on $ns — owner overrides any grant,
     * otherwise the most permissive matching grant wins (editor beats
     * viewer if somehow both were granted on overlapping namespaces).
     */
    public function roleOn(string $ns): ?GrantRole
    {
        if ($this->isOwner) {
            return GrantRole::Editor;
        }

        $best = null;
        foreach ($this->grants as $grant) {
            if (!$grant->covers($ns)) {
                continue;
            }
            if ($grant->role === GrantRole::Editor) {
                return GrantRole::Editor;
            }
            $best = GrantRole::Viewer;
        }

        return $best;
    }

    public function canWrite(string $ns): bool
    {
        return $this->isOwner || $this->roleOn($ns) === GrantRole::Editor;
    }

    public function canRead(string $ns): bool
    {
        return $this->roleOn($ns) !== null;
    }
}
