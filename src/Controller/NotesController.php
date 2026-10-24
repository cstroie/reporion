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
 *
 * The app carries a version (VERSION, by hand) and a build (build(): a
 * hash of the shell, the worker and the asset URLs, by itself). Both name
 * the worker's cache, so any change is a new worker; the page tells the
 * writer a new one is ready and reloads into it when asked.
 */
final class NotesController
{
    /**
     * The notes app's version (semver): raised with a change to what the
     * writer sees or does (templates/notes.php, assets/js/notes.js,
     * notes-sw.js, notes.css, the API calls they make). The build hash
     * changes with any byte anyway, so a forgotten bump still updates.
     */
    public const VERSION = '2.0.0';

    /**
     * The app's stylesheets: Reporion's colour tokens and fonts, the icons,
     * and its own look (notes.css); none of Reporion's page components
     */
    public const STYLES = ['css/tokens.css', 'css/phosphor.css', 'css/notes.css'];

    /** The strings the app shows, from lang/en.php (D26) */
    private const STRINGS = [
        'notes.title', 'notes.new', 'notes.search', 'notes.empty', 'notes.untitled', 'notes.back',
        'notes.delete', 'notes.delete_confirm', 'notes.preview', 'notes.edit', 'notes.sign_out',
        'notes.sign_out_unsynced', 'notes.theme', 'notes.theme_system', 'notes.theme_light',
        'notes.theme_dark', 'notes.offline', 'notes.syncing', 'notes.synced',
        'notes.unsynced', 'notes.sync_failed', 'notes.sync_now', 'notes.conflict',
        'notes.conflict_resolved', 'notes.conflict_markers', 'notes.preview_offline',
        'notes.recreated', 'notes.edited', 'notes.not_found', 'notes.storage_failed',
        'notes.update_ready', 'notes.update_reload', 'notes.install', 'notes.install_ready',
        'notes.install_ios', 'notes.install_later', 'notes.installed', 'notes.saved', 'notes.edited_at',
        'notes.not_synced_one', 'notes.signed_in_as',
        'auth.username', 'auth.password', 'auth.submit', 'auth.invalid', 'nav.signin', 'app.name',
    ];

    public function shell(Request $request): Response
    {
        $b = $request->basePath;
        $strings = [];
        foreach (self::STRINGS as $key) {
            $strings[$key] = t($key);
        }

        return Response::html(View::render(self::root() . '/templates/notes.php', [
            'basePath' => $b,
            'config' => [
                'base' => $b,
                'api' => $b . '/api/v1',
                'sw' => $b . '/notes/sw.js',
                'scope' => $b . '/notes',
                'version' => self::VERSION,
                'build' => self::build($b),
                'strings' => $strings,
            ],
        ]))->withHeader('Cache-Control', 'no-cache');
    }

    /**
     * GET /notes/sw.js: assets/js/notes-sw.js, preceded by what it
     * precaches (the shell and the versioned assets it loads), the version
     * and build, and a cache name made of both, so a changed app is a new
     * worker and a new cache.
     */
    public function serviceWorker(Request $request): Response
    {
        $b = $request->basePath;
        $build = self::build($b);
        $head = 'const PRECACHE = ' . json_encode(self::precache($b), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ";\n"
            . 'const VERSION = ' . json_encode(self::VERSION, JSON_THROW_ON_ERROR) . ";\n"
            . 'const BUILD = ' . json_encode($build, JSON_THROW_ON_ERROR) . ";\n"
            . 'const CACHE = ' . json_encode('notes-' . self::VERSION . '-' . $build, JSON_THROW_ON_ERROR) . ";\n"
            . 'const BASE = ' . json_encode($b, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ";\n";
        $source = (string) file_get_contents(self::root() . '/assets/js/notes-sw.js');

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
            // tokens.css: --color-bg (the dark default) behind the splash, the icon's accent-900 tile as the bar
            'background_color' => '#0d1017',
            'theme_color' => '#161f3c',
            // assets/notes/: the app's own icon, not Reporion's (a note card on the accent-900 tile)
            'icons' => [
                ['src' => Asset::url($b, 'notes/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => Asset::url($b, 'notes/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => Asset::url($b, 'notes/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => Asset::url($b, 'notes/icon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ],
        ];

        return new Response(200, (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * The build: 12 hex characters of a hash of the version, the shell
     * template, the worker and the versioned URLs of the assets (whose
     * `?v=` follows each file's mtime)
     */
    public static function build(string $basePath): string
    {
        $parts = [self::VERSION, ...self::precache($basePath)];
        foreach (['templates/notes.php', 'assets/js/notes-sw.js'] as $file) {
            $parts[] = (string) file_get_contents(self::root() . '/' . $file);
        }

        return substr(hash('sha256', implode("\n", $parts)), 0, 12);
    }

    /**
     * The shell and the versioned assets it loads
     *
     * @return list<string>
     */
    private static function precache(string $basePath): array
    {
        $urls = [$basePath . '/notes'];
        foreach (self::assets() as $file) {
            $urls[] = Asset::url($basePath, $file);
        }

        return $urls;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * The assets the shell loads, which the worker precaches
     *
     * @return list<string>
     */
    public static function assets(): array
    {
        return [...self::STYLES, 'js/notes.js', 'notes/icon.svg', 'notes/apple-touch-icon.png'];
    }
}
