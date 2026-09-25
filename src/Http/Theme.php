<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

/**
 * The display preferences — light/dark theme and colour palette (A6) —
 * each its own cookie, deliberately not signed and not tied to a session
 * (Http\Session's cookie is a security claim; these are display
 * preferences an anonymous visitor could set too). One year, same Path=/
 * and SameSite=Lax shape as the session cookie.
 */
final class Theme
{
    public const COOKIE_NAME = 'reporion_theme';
    public const PALETTE_COOKIE_NAME = 'reporion_palette';

    /** A6: royal blue (the default), lime, amber — design/README.md */
    public const PALETTES = ['royal-blue', 'lime', 'amber'];
    public const DEFAULT_PALETTE = 'royal-blue';

    public static function cookieHeader(string $theme): string
    {
        $value = $theme === 'light' ? 'light' : 'dark';

        return self::header(self::COOKIE_NAME, $value);
    }

    public static function paletteCookieHeader(string $palette): string
    {
        return self::header(self::PALETTE_COOKIE_NAME, self::palette($palette));
    }

    /** Any value outside the allowlist is the default palette. */
    public static function palette(?string $value): string
    {
        return \in_array($value, self::PALETTES, true) ? $value : self::DEFAULT_PALETTE;
    }

    /**
     * Where to send the browser back to after a preference POST: only a
     * same-app relative path — never "//…", which browsers parse as
     * protocol-relative (the open-redirect shape this guards against).
     */
    public static function returnPath(mixed $returnTo): string
    {
        return \is_string($returnTo) && str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//')
            ? $returnTo
            : '/';
    }

    private static function header(string $name, string $value): string
    {
        return \sprintf('%s=%s; Max-Age=%d; Path=/; SameSite=Lax', $name, $value, 60 * 60 * 24 * 365);
    }
}
