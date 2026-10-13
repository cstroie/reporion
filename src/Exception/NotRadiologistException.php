<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

use RuntimeException;

/** A signed-in caller who may write the report but is not a radiologist asked to sign it (D37, amended 2026-10-13) */
final class NotRadiologistException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only a radiologist may sign');
    }
}
