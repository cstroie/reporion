<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

use Reporion\Storage\PageRecord;

/**
 * The editor submitted a base revision that is no longer current (optimistic
 * concurrency, docs/architecture-storage-index.md §5). The caller maps this
 * to HTTP 409 and returns both bodies for the three-way merge UI.
 */
final class RevisionConflictException extends StorageException
{
    public function __construct(
        public readonly PageRecord $current,
        public readonly int $submittedBaseRev,
    ) {
        parent::__construct('Revision conflict');
    }
}
