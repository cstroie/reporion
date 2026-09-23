<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

/**
 * "A template function" (docs/architecture-api.md §2) — a template is a
 * plain PHP file rendered with its variables extracted into scope, nothing
 * more. No compiler, no cache, no DSL.
 */
final class View
{
    /**
     * @param array<string, mixed> $vars
     */
    public static function render(string $templatePath, array $vars): string
    {
        $renderer = static function (string $__templatePath, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            include $__templatePath;

            return (string) ob_get_clean();
        };

        return $renderer($templatePath, $vars);
    }
}
