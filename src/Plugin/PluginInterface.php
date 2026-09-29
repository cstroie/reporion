<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin;

/**
 * What every plugin's `plugins/{id}/Plugin.php` implements
 * (docs/architecture-api.md §5). register() runs once per boot: it asks the
 * container for the services it needs — never the filesystem or the PDO
 * handle (D9) — and wires its hooks and routes.
 */
interface PluginInterface
{
    public function register(Hooks $hooks, Container $container): void;
}
