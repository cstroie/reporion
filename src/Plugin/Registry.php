<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin;

/**
 * What the loader found and ran this boot: every plugin directory (with its
 * manifest, or why it is not a valid one), which are enabled, which loaded
 * and which failed. Admin → Plugins shows all of it; the templates read the
 * interface slots of the loaded ones (reporion_plugin_ui()).
 */
final class Registry
{
    /**
     * @param array<string, Manifest> $manifests valid plugins found, by id
     * @param array<string, string>   $invalid   directory name → why it is not a plugin
     * @param list<string>            $enabled
     * @param list<string>            $loaded    enabled and registered without error
     * @param array<string, string>   $failed    id → exception class, for the admin screen
     */
    public function __construct(
        public readonly array $manifests,
        public readonly array $invalid,
        public readonly array $enabled,
        public readonly array $loaded,
        public readonly array $failed,
    ) {
    }

    /**
     * The interface slots of the loaded plugins, per slot.
     *
     * @return array<string, list<array{plugin: string, label: string, icon: string, href: string}>>
     */
    public function ui(): array
    {
        $slots = [];
        foreach ($this->loaded as $id) {
            foreach ($this->manifests[$id]->ui as $slot => $item) {
                $slots[$slot][] = ['plugin' => $id] + $item;
            }
        }

        return $slots;
    }
}
