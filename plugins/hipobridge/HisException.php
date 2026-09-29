<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Hipobridge;

use RuntimeException;

/**
 * The HIS could not answer. The message is a fixed code — not-configured,
 * unreachable, auth, http-{status}, bad-response — never anything from
 * the request or the response (invariant 8); the screens map it to a
 * `hipobridge.err.*` string.
 */
final class HisException extends RuntimeException
{
    public function reason(): string
    {
        return str_starts_with($this->getMessage(), 'http-') ? 'http' : $this->getMessage();
    }
}
