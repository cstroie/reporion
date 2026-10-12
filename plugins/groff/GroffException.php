<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Groff;

use RuntimeException;

/** groff could not make the PDF: a fixed reason (no-tool | failed | timeout | empty), and its own words for the owner */
final class GroffException extends RuntimeException
{
    public function __construct(string $reason, public readonly string $log = '')
    {
        parent::__construct($reason);
    }
}
