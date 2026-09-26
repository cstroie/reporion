<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

use RuntimeException;

/** Another maintenance run that writes (web or CLI) holds the lock */
final class MaintenanceBusyException extends RuntimeException
{
}
