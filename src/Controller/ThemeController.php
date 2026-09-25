<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Theme;

/**
 * POST /theme and POST /palette — the top nav's display preferences (A6).
 * Plain form POST + redirect, not an island: both must work with
 * JavaScript off, same as every other write in this app.
 */
final class ThemeController
{
    public function set(Request $request): Response
    {
        parse_str($request->body, $fields);
        $theme = \is_string($fields['theme'] ?? null) && $fields['theme'] === 'light' ? 'light' : 'dark';

        return Response::redirect($request->basePath . Theme::returnPath($fields['return_to'] ?? null))
            ->withHeader('Set-Cookie', Theme::cookieHeader($theme));
    }

    public function setPalette(Request $request): Response
    {
        parse_str($request->body, $fields);
        $palette = Theme::palette(\is_string($fields['palette'] ?? null) ? $fields['palette'] : null);

        return Response::redirect($request->basePath . Theme::returnPath($fields['return_to'] ?? null))
            ->withHeader('Set-Cookie', Theme::paletteCookieHeader($palette));
    }
}
