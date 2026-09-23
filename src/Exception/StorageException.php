<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

use RuntimeException;

/**
 * Base for storage-layer failures. Messages must never contain a page path
 * (CLAUDE.md invariant 8: the patient path never leaves the box, including
 * in a log line) — keep messages generic and attach structured data to
 * subclasses instead.
 */
abstract class StorageException extends RuntimeException
{
}
