<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

use RuntimeException;

/**
 * The imaging guide (D62): a CSV that is not the guide's, or holds what looks like a CNP; or no
 * guide loaded. $reason is the machine code, the message a detail safe to show an owner.
 */
final class GuideException extends RuntimeException
{
    public const INVALID = 'invalid';
    public const PATIENT_DATA = 'patient_data';
    public const NOT_LOADED = 'not_loaded';

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($detail !== '' ? $reason . ': ' . $detail : $reason);
    }
}
