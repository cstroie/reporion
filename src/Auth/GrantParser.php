<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

use InvalidArgumentException;

/**
 * Parses a "<namespace>:<role>" spec into a Grant — the one shared piece
 * between `bin/reporion user:create --grant=` and the admin screen's
 * grants field, so the two don't drift.
 */
final class GrantParser
{
    /**
     * @throws InvalidArgumentException if $spec isn't "<namespace>:editor|viewer"
     */
    public static function parse(string $spec): Grant
    {
        // Split on the LAST colon, not the first: a namespace is itself
        // colon-separated ("reports:mri"), so "reports:mri:editor" is
        // namespace "reports:mri" + role "editor", not "reports" +
        // "mri:editor".
        $lastColon = strrpos($spec, ':');
        if ($lastColon === false) {
            throw new InvalidArgumentException("Invalid grant \"{$spec}\" — expected <namespace>:editor|viewer");
        }

        $namespace = substr($spec, 0, $lastColon);
        $roleText = substr($spec, $lastColon + 1);
        $role = GrantRole::tryFrom($roleText);
        if ($role === null) {
            throw new InvalidArgumentException("Invalid grant role \"{$roleText}\" in \"{$spec}\" — expected editor or viewer");
        }

        return new Grant($namespace, $role);
    }
}
