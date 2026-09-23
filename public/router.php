<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/**
 * Front-controller router for PHP's built-in development server only:
 *
 *   php -d ffi.enable=1 -S host:port -t public public/router.php
 *
 * (what `bin/reporion serve` will run once it exists). Mirrors lighttpd's
 * url.rewrite-if-not-file in production (docs/deploy-lighttpd.md) — lighttpd
 * has no .htaccess, so that rewrite is server config there, but the built-in
 * server needs this script to get the same behaviour: serve a real static
 * file as-is, otherwise hand everything to index.php.
 *
 * -d ffi.enable=1 is not optional: Support\Fsync needs it for every write,
 * and — confirmed empirically — the built-in server does NOT get the same
 * FFI trust a plain `php script.php` CLI invocation does, even though both
 * are launched from the CLI. Without it every write 500s.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . (is_string($path) ? $path : '');

if ($path !== '/' && is_string($path) && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
