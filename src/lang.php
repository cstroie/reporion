<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

// Global t() helper (D26): "no hard-coded strings in templates" — every
// interface string comes from lang/en.php through this one function.
// Report CONTENT is never translated; t() is for chrome strings only.

if (!\function_exists('reporion_instance')) {
    /**
     * This instance's display values from Admin → Settings
     * (Service\InstanceSettings): strings laid over lang/en.php — `app.name`,
     * `auth.tagline` — and `icon`, the site icon's versioned file name. Set
     * once per boot by the Kernel; with no argument, returns them.
     *
     * @param ?array<string, string> $set
     *
     * @return array<string, string>
     */
    function reporion_instance(?array $set = null): array
    {
        static $values = [];
        if ($set !== null) {
            $values = $set;
        }

        return $values;
    }
}

if (!\function_exists('t')) {
    /**
     * @param array<array-key, string|int|float> $args
     */
    function t(string $key, array $args = []): string
    {
        static $strings = null;
        if ($strings === null) {
            $strings = require \dirname(__DIR__) . '/lang/en.php';
        }

        $template = reporion_instance()[$key] ?? $strings[$key] ?? $key;

        return $args === [] ? $template : vsprintf($template, $args);
    }
}
