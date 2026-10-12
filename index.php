<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/**
 * Front controller when the archive is unpacked inside a web root (D43):
 * the URL then maps to this directory, not to public/ (D23). It runs the
 * same public/index.php; SCRIPT_NAME is this file, so the base path is
 * this directory's URL. Only this file, install.php and assets/ may be
 * served from here — .htaccess (Apache) or the server config install.php
 * prints (lighttpd, nginx) sends everything else to this file, which
 * answers 404 for it.
 */

require __DIR__ . '/public/index.php';
