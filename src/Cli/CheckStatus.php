<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

/**
 * WARN never fails `doctor` (exit 0) — only FAIL does. Several checks are
 * inherently best-effort (see DoctorCommand's own docblock) and must not
 * block on a false positive.
 */
enum CheckStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
}
