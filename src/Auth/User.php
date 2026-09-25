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
        // How the account signs a printed report (name, professional
        // title); optional, empty when never set.
        public readonly string $displayName = '',
        public readonly string $title = '',
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
    /** This account with $changes applied, everything else kept. */
    public function with(?bool $active = null, ?string $displayName = null, ?string $title = null, ?string $passwordHash = null): self
    {
        return new self(
            $this->username,
            $passwordHash ?? $this->passwordHash,
            $this->isOwner,
            $this->grants,
            $active ?? $this->active,
            $this->createdAt,
            $this->updatedAt,
            $displayName ?? $this->displayName,
            $title ?? $this->title,
        );
    }

    /** The name a printed report shows for this account: display name, else username. */
    public function signatureName(): string
    {
        return $this->displayName !== '' ? $this->displayName : $this->username;
    }

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

    /**
     * True if this account could write *somewhere*, regardless of which
     * namespace. Exists for the one case canWrite($ns) can't answer: a
     * write endpoint that doesn't know the target namespace yet because
     * the request that would carry it hasn't been validated as well-formed
     * (PagesApiController::create() — the namespace is inside the JSON
     * body, not a route parameter, so there is no $ns to check before the
     * body is parsed). This is a coarse gate only: a caller passing it
     * still needs canWrite($ns) checked against the real namespace once
     * it's known.
     */
    public function hasAnyWriteAccess(): bool
    {
        if ($this->isOwner) {
            return true;
        }

        foreach ($this->grants as $grant) {
            if ($grant->role === GrantRole::Editor) {
                return true;
            }
        }

        return false;
    }
}
