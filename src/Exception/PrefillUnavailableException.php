<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

use RuntimeException;

/**
 * A `report.prefill` listener whose server (PACS, HIS) could not answer
 * as distinct from returning null for a ref it does not know. The message
 * is already a user-facing string (the plugin's `*.err.*`), never anything
 * from the request or the answer (invariant 8); the guided form shows it.
 */
final class PrefillUnavailableException extends RuntimeException
{
}
