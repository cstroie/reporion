<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin;

use InvalidArgumentException;

/**
 * What a plugin may ask for: the services the Kernel hands over — never the
 * filesystem or the PDO handle (D9) — its own settings, and its own
 * directory (for its templates). One container per plugin, so a plugin
 * only ever reads its own settings.
 */
final class Container
{
    /**
     * @param array<class-string, object> $services
     * @param array<string, mixed>        $settings this plugin's, defaults applied (Manifest::settingValues())
     */
    public function __construct(
        private readonly array $services,
        private readonly array $settings,
        private readonly string $dir,
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function get(string $class): object
    {
        $service = $this->services[$class] ?? throw new InvalidArgumentException('No such service for plugins');
        \assert($service instanceof $class);

        return $service;
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->settings;
    }

    /** The plugin's own directory — its templates live under it */
    public function dir(): string
    {
        return $this->dir;
    }
}
