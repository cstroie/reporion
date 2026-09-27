<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

use RuntimeException;

/**
 * The AI assistant cannot answer (roadmap phase 15): not configured, a
 * provider outside the egress allow-list, the provider failing, or a prompt
 * refused because an identifier would have left (`identifier_leak`).
 * `$reason` is a stable code for the API and the audit line; the message
 * never carries report text or a patient identifier. `$status` is the
 * server's HTTP status when it answered with an error, for the audit line.
 */
final class AiException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '', public readonly ?int $status = null)
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
