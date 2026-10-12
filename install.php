<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/**
 * The web installer when the archive is unpacked inside a web root (D43):
 * the same installer as public/install.php. Once installed it only
 * redirects to the site, and may be deleted.
 */

require __DIR__ . '/public/install.php';
