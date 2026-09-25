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
            $__level = ob_get_level();
            ob_start();
            try {
                include $__templatePath;
            } catch (\Throwable $__e) {
                // Never let a half-rendered page reach the client in front
                // of the error response (it would carry whatever the template
                // had printed so far, patient data included).
                while (ob_get_level() > $__level) {
                    ob_end_clean();
                }

                throw $__e;
            }

            return (string) ob_get_clean();
        };

        return $renderer($templatePath, $vars);
    }

    /**
     * A signed-in screen: $contentTemplate renders only its own content,
     * templates/layout.php wraps it in the one app shell (A6: top nav,
     * namespace drawer, reading column, and the page header when $vars
     * carries one). Both see the same $vars.
     *
     * @param array<string, mixed> $vars
     */
    public static function page(string $contentTemplate, array $vars, string $pageTitle): string
    {
        $content = self::render($contentTemplate, $vars);

        return self::render(
            \dirname(__DIR__, 2) . '/templates/layout.php',
            ['content' => $content, 'pageTitle' => $pageTitle] + $vars
        );
    }
}
