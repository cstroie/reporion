<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

final class UserAlreadyExistsException extends AuthException
{
    public function __construct()
    {
        parent::__construct('User already exists');
    }
}
