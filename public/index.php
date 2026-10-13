<?php

declare(strict_types=1);

/**
 * Reporion: single entry point. Everything else lives OUTSIDE this directory (D23).
 *
 * Copyright (C) 2026  <your name>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Not installed yet (no configuration, or no vendor/): the web installer (D43)
if (!is_file(\dirname(__DIR__) . '/conf/local.php') || !is_file(\dirname(__DIR__) . '/vendor/autoload.php')) {
    $base = rtrim(str_replace('\\', '/', \dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    header('Location: ' . ($base === '.' ? '' : $base) . '/install.php', true, 302);
    exit;
}

require \dirname(__DIR__) . '/vendor/autoload.php';

use Reporion\Kernel;

$config = require \dirname(__DIR__) . '/conf/local.php';

Kernel::boot($config)
    ->handle(Reporion\Http\Request::fromGlobals())
    ->send();
