<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

use RuntimeException;

/**
 * Base for account-store failures (D35/D36). Messages must never contain a
 * password or password hash — keep them generic.
 */
abstract class AuthException extends RuntimeException
{
}
