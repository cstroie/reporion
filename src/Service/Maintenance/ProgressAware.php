<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

/**
 * A task that can say what it is working on, for an operator watching
 * bin/reporion (Cli\PluginTaskCommand). Progress goes to that terminal only
 * — never into the stored run report or the audit, which name pages by pid
 * (invariant 8). The callback gets ('start', ['n' => int, 'total' => int, 'label' => string])
 * when it begins an item and ('done', ['status' => string]) when it has one.
 */
interface ProgressAware
{
    /** @param ?callable(string, array<string, mixed>): void $progress */
    public function setProgress(?callable $progress): void;
}
