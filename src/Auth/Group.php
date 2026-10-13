<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

use InvalidArgumentException;

/**
 * A named set of grants an owner gives several accounts at once (D47):
 * `data/groups/{name}.json`, disk-authoritative like an account (D36).
 * A member's effective grants are its own plus every group's, and a group
 * may make its members radiologists. Never `owner` — that stays on the
 * account itself.
 */
final class Group
{
    /** Same shape as a username: the name is a filename */
    public const NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9_.-]{0,62}[a-z0-9])?$/';

    /**
     * @param list<Grant> $grants
     */
    public function __construct(
        public readonly string $name,
        public readonly string $displayName,
        public readonly array $grants,
        public readonly bool $radiologist,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
        self::assertValidName($name);
        foreach ($grants as $grant) {
            if (!$grant instanceof Grant) {
                throw new InvalidArgumentException('grants must be a list of Grant');
            }
        }
    }

    /**
     * @param ?list<Grant> $grants
     */
    public function with(?string $displayName = null, ?array $grants = null, ?bool $radiologist = null): self
    {
        return new self(
            $this->name,
            $displayName ?? $this->displayName,
            $grants ?? $this->grants,
            $radiologist ?? $this->radiologist,
            $this->createdAt,
            $this->updatedAt,
        );
    }

    /** The name shown in the chrome: display name, else `@name` */
    public function label(): string
    {
        return $this->displayName !== '' ? $this->displayName : '@' . $this->name;
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    public static function assertValidName(string $name): void
    {
        if (!self::isValidName($name)) {
            throw new InvalidArgumentException('Invalid group name');
        }
    }
}
