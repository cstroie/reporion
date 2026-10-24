<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Support\Asset;

/**
 * The notes app (D55): an offline-first PWA over the JSON API, at /notes.
 * Its notes are ordinary pages under the account's own namespace,
 * users:{username}:notes (D46); everything it reads or writes goes through
 * /api/v1 (session, pages, render). This controller only serves the shell:
 * the same HTML for everyone (no account, no page in it, so the service
 * worker may cache it for offline use), the worker itself (served from
 * /notes/ so that its scope covers the app) and the web manifest.
 */
final class NotesController
{
    /** The strings the app shows, from lang/en.php (D26) */
    private const STRINGS = [
        'notes.title', 'notes.new', 'notes.search', 'notes.empty', 'notes.untitled', 'notes.back',
        'notes.delete', 'notes.delete_confirm', 'notes.preview', 'notes.edit', 'notes.sign_out',
        'notes.sign_out_unsynced', 'notes.theme', 'notes.theme_system', 'notes.theme_light',
        'notes.theme_dark', 'notes.offline', 'notes.syncing', 'notes.synced',
        'notes.unsynced', 'notes.sync_failed', 'notes.sync_now', 'notes.conflict',
        'notes.conflict_resolved', 'notes.conflict_markers', 'notes.preview_offline',
        'notes.recreated', 'notes.edited', 'notes.not_found', 'notes.storage_failed',
        'auth.username', 'auth.password', 'auth.submit', 'auth.invalid', 'nav.signin', 'app.name',
    ];

    public function shell(Request $request): Response
    {
        $b = $request->basePath;
        $strings = [];
        foreach (self::STRINGS as $key) {
            $strings[$key] = t($key);
        }

        return Response::html(View::render(\dirname(__DIR__, 2) . '/templates/notes.php', [
            'basePath' => $b,
            'config' => [
                'base' => $b,
                'api' => $b . '/api/v1',
                'sw' => $b . '/notes/sw.js',
                'scope' => $b . '/notes',
                'strings' => $strings,
            ],
        ]))->withHeader('Cache-Control', 'no-cache');
    }

    /**
     * GET /notes/sw.js: assets/js/notes-sw.js, preceded by what it
     * precaches (the shell and the versioned assets it loads) and a cache
     * name made of their URLs, so a changed asset is a new cache.
     */
    public function serviceWorker(Request $request): Response
    {
        $b = $request->basePath;
        $precache = [$b . '/notes'];
        foreach (self::assets() as $file) {
            $precache[] = Asset::url($b, $file);
        }
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/assets/js/notes-sw.js');
        $head = 'const PRECACHE = ' . json_encode($precache, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ";\n"
            . 'const CACHE = ' . json_encode('notes-' . substr(hash('sha256', implode("\n", $precache) . $source), 0, 12), JSON_THROW_ON_ERROR) . ";\n"
            . 'const BASE = ' . json_encode($b, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ";\n";

        return new Response(200, $head . $source, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            // Served from /notes/, a worker may control /notes/… only: the app's own URL is /notes
            'Service-Worker-Allowed' => $b . '/notes',
        ]);
    }

    /** GET /notes/manifest.webmanifest */
    public function manifest(Request $request): Response
    {
        $b = $request->basePath;
        $manifest = [
            'name' => t('notes.title') . ' · ' . t('app.name'),
            'short_name' => t('notes.title'),
            'start_url' => $b . '/notes',
            'scope' => $b . '/notes',
            'display' => 'standalone',
            // tokens.css --color-bg (the dark default)
            'background_color' => '#0d1017',
            'theme_color' => '#0d1017',
            'icons' => [
                ['src' => Asset::url($b, 'icon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml'],
                ['src' => Asset::url($b, 'apple-touch-icon.png'), 'sizes' => '180x180', 'type' => 'image/png'],
            ],
        ];

        return new Response(200, (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * The assets the shell loads, which the worker precaches
     *
     * @return list<string>
     */
    public static function assets(): array
    {
        return ['css/tokens.css', 'css/wiki.css', 'css/phosphor.css', 'css/cyberpunk.css', 'css/themes.css', 'css/notes.css', 'js/notes.js', 'icon.svg'];
    }
}
