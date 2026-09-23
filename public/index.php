<?php
declare(strict_types=1);

/**
 * Reporion — single entry point. Everything else lives OUTSIDE this directory (D23).
 *
 * Copyright (C) 2026  <your name>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Reporion\Kernel;

$config = require dirname(__DIR__) . '/conf/local.php';

Kernel::boot($config)
    ->handle(Reporion\Http\Request::fromGlobals())
    ->send();
