<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

/**
 * The display-theme cookie — deliberately not signed, not tied to a
 * session (Http\Session's cookie is a security claim; this is a display
 * preference an anonymous visitor could set too, even though only
 * signed-in chrome has a toggle for it today). One year, same Path=/ and
 * SameSite=Lax shape as the session cookie.
 */
final class Theme
{
    public const COOKIE_NAME = 'reporion_theme';

    public static function cookieHeader(string $theme): string
    {
        $value = $theme === 'light' ? 'light' : 'dark';

        return \sprintf('%s=%s; Max-Age=%d; Path=/; SameSite=Lax', self::COOKIE_NAME, $value, 60 * 60 * 24 * 365);
    }
}
