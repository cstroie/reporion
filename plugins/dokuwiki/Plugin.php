<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Dokuwiki;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Plugin\Container;
use Reporion\Plugin\Hooks;
use Reporion\Plugin\PluginInterface;

/**
 * DokuWiki conversion tools (2026-10-16): the editor's Tools section gets three buttons (links,
 * formatting, tables) while this plugin is enabled. The work is done in the browser by
 * `assets/tools.js`, which this plugin serves at `GET /x/dokuwiki/js/tools.js` (plugins/ is
 * outside the docroot) to signed-in callers: the editor loads it through the `editor_tool` slot.
 * Nothing is read, written or sent: no page, no patient data, no service of the container.
 */
final class Plugin implements PluginInterface
{
    public function register(Hooks $hooks, Container $container): void
    {
        $hooks->route('GET', '/js/tools.js', static function (Request $request, array $params, ?User $principal): Response {
            $script = @file_get_contents(__DIR__ . '/assets/tools.js');
            if ($principal === null || $script === false) {
                throw new PageNotFoundException();
            }

            return new Response(200, $script, [
                'Content-Type' => 'text/javascript; charset=utf-8',
                'Cache-Control' => 'private, no-cache',
            ]);
        });
    }
}
