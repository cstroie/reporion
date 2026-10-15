<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;

/**
 * Whether a page shows its collapsed Debug panel: the instance switch
 * (Admin → Settings → Debug, `debug.panels`, off unless an owner turns it
 * on) and an owner as the caller. One answer for the core and for plugins,
 * since a panel may hold patient data.
 */
final class DebugPanels
{
    public function __construct(private readonly bool $enabled)
    {
    }

    public function shows(?User $principal): bool
    {
        return $this->enabled && $principal?->isOwner === true;
    }
}
