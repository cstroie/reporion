<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Dicom;

use RuntimeException;

/**
 * The PACS could not answer. The message is a fixed code — not-configured,
 * no-tool, unreachable, rejected, timeout, failed — never anything from the
 * query or its answer (invariant 8); screens map it to `dicom.err.*`.
 */
final class DicomException extends RuntimeException
{
}
