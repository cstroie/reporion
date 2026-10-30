<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

use InvalidArgumentException;

/** A write would make a library source public (Support\LibrarySource, D58): refused, nothing written */
final class PublicSourceException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('A library source is never public');
    }
}
