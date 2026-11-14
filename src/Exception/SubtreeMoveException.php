<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

/**
 * A head page (D61) whose subtree cannot follow it: a page under it is
 * signed or a report, or a path it would move to is taken. The whole move
 * or delete is refused before anything is written. The message counts,
 * never names, the pages (invariant 8). An InvalidArgumentException, so
 * every move caller that reports a bad target reports this too.
 */
final class SubtreeMoveException extends \InvalidArgumentException
{
    public function __construct(public readonly string $reason, int $count)
    {
        parent::__construct(t($reason === 'signed_delete' ? 'delete.err_subtree_signed' : 'move.err_subtree_' . $reason, [$count]));
    }
}
