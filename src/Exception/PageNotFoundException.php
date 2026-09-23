<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

final class PageNotFoundException extends StorageException
{
    public function __construct()
    {
        parent::__construct('Page not found');
    }
}
