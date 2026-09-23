<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

use InvalidArgumentException;

/**
 * A namespace grant (D35/D36): `role` on `namespace` and everything under
 * it. Resolution is a prefix match against a page's `ns` column
 * (`Search\Query::visibilityClause($principal)`,
 * docs/architecture-storage-index.md §"Visibility inside the query") — a
 * grant on `reports:mri` covers `reports:mri:mioveni` too, reusing the
 * colon-namespace hierarchy pages already have instead of a separate
 * inheritance model.
 */
final class Grant
{
    public function __construct(
        public readonly string $namespace,
        public readonly GrantRole $role,
    ) {
        self::assertValidNamespace($namespace);
    }

    /**
     * True if $ns is $this->namespace itself or nested under it.
     */
    public function covers(string $ns): bool
    {
        return $ns === $this->namespace || str_starts_with($ns, $this->namespace . ':');
    }

    private static function assertValidNamespace(string $namespace): void
    {
        if ($namespace === '' || str_starts_with($namespace, ':') || str_ends_with($namespace, ':') || str_contains($namespace, '::')) {
            throw new InvalidArgumentException('Invalid grant namespace');
        }
        foreach (explode(':', $namespace) as $segment) {
            if ($segment === '' || str_contains($segment, '/')) {
                throw new InvalidArgumentException('Invalid grant namespace');
            }
        }
    }
}
