<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

use RuntimeException;

/**
 * A backup archive that cannot be written, read or trusted (D44): a refused
 * entry, a hash that does not match, a target that must not be replaced.
 * The message never names a page path (invariant 8); it is safe for the CLI.
 */
final class BackupException extends RuntimeException
{
}
