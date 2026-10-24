<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * What a note is (D55): a page directly under an account's notes namespace,
 * `users:{username}:notes:{slug}`, its slug a lowercase ULID. The notes app
 * makes the slug on the device; Reporion's own "new page" there makes it
 * here (newPath()), so nobody is asked to invent one.
 */
final class Notes
{
    public const SEGMENT = 'notes';

    /** `users:{username}:notes`, the username shaped as an account's (FileTree) */
    private const NAMESPACE = '/^users:[a-z0-9](?:[a-z0-9_.-]{0,62}[a-z0-9])?:notes$/';

    public static function namespaceFor(string $username): string
    {
        return 'users:' . $username . ':' . self::SEGMENT;
    }

    public static function isNotesNamespace(string $ns): bool
    {
        return preg_match(self::NAMESPACE, $ns) === 1;
    }

    /** A direct child of a notes namespace */
    public static function isNote(string $path): bool
    {
        $colon = strrpos($path, ':');

        return $colon !== false && $colon < \strlen($path) - 1 && self::isNotesNamespace(substr($path, 0, $colon));
    }

    /** A new note's path in $ns: a fresh lowercase ULID as its slug */
    public static function newPath(string $ns): string
    {
        return $ns . ':' . strtolower(Ulid::generate());
    }
}
