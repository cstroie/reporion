<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

/**
 * A page's attic zip (D54, docs/architecture-v2.md §4) is unreadable, or a
 * write to it was refused because the result would not keep every earlier
 * revision exactly. The zip on disk is unchanged. `reason` is a fixed code,
 * never a path (invariant 8).
 */
final class AtticException extends StorageException
{
    public function __construct(public readonly string $reason, ?int $rev = null)
    {
        parent::__construct('Attic ' . $reason . ($rev !== null ? ' at revision ' . $rev : ''));
    }
}
