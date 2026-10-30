<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

use RuntimeException;

/**
 * An article that cannot go into the library (docs/library.md). $reason is a fixed code the
 * controllers turn into a message (`library.err_{reason}`): never a tool's output or a file path.
 */
final class LibraryException extends RuntimeException
{
    public const UNSUPPORTED = 'unsupported';
    public const TOO_LARGE = 'too_large';
    public const TOOL_MISSING = 'tool_missing';
    public const FAILED = 'failed';
    public const TIMEOUT = 'timeout';
    public const PATIENT_DATA = 'patient_data';
    public const NO_TEXT = 'no_text';
    public const EXPIRED = 'expired';
    public const DUPLICATE = 'duplicate';
    public const INVALID = 'invalid';

    public function __construct(public readonly string $reason, public readonly ?string $pid = null)
    {
        parent::__construct('Library: ' . $reason);
    }
}
