<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Theme;

/**
 * POST /theme — the rail's theme-toggle item (Controller/rail.php, chrome
 * slice 4). A plain form POST + redirect, not an island: the toggle must
 * work with JavaScript off, same as every other write in this app.
 */
final class ThemeController
{
    public function set(Request $request): Response
    {
        parse_str($request->body, $fields);
        $theme = \is_string($fields['theme'] ?? null) && $fields['theme'] === 'light' ? 'light' : 'dark';

        $returnTo = $fields['return_to'] ?? null;
        // Only a same-app relative path — "/" and never "//..." (a
        // protocol-relative URL, the open-redirect shape this guards
        // against: a value starting with // is parsed by browsers as
        // scheme-relative, sending the user off this origin entirely).
        $redirectPath = \is_string($returnTo) && str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//')
            ? $returnTo
            : '/';

        return Response::redirect($request->basePath . $redirectPath)
            ->withHeader('Set-Cookie', Theme::cookieHeader($theme));
    }
}
