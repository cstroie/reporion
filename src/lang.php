<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

// Global t() helper (D26): "no hard-coded strings in templates" — every
// interface string comes from lang/en.php through this one function.
// Report CONTENT is never translated; t() is for chrome strings only.

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

        $template = $strings[$key] ?? $key;

        return $args === [] ? $template : vsprintf($template, $args);
    }
}
