<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Auth\User;
use Reporion\Storage\PageRecord;

/**
 * {snippets}: passages of the caller's earlier reports in the same style,
 * de-identified (roadmap phase 15e, full-text search now, vectors later).
 */
interface Examples
{
    /** @return list<string> de-identified passages, best first */
    public function for(PageRecord $page, string $text, ?User $principal, Redactor $redactor): array;
}
