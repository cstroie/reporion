<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use Reporion\Auth\User;

/**
 * The view-model every signed-in document screen needs for its chrome
 * (templates/rail.php, templates/tabs.php) — extracted here once
 * `Controller\EditorController` and `Controller\HistoryController` needed
 * the identical isOwner/canCreate/canWrite/railEditHref computation
 * `Http\PageTemplateRenderer` already had for `Controller\PageController`.
 * One formula, three callers, instead of three copies drifting apart.
 */
final class ChromeVars
{
    /**
     * @return array{isOwner: bool, canCreate: bool, canWrite: bool, railEditHref: ?string}
     */
    public static function forPath(?User $principal, string $path): array
    {
        $canWrite = $principal?->canWrite($path) ?? false;

        return [
            'isOwner' => $principal?->isOwner ?? false,
            // Deliberately not the same question as canWrite($path): a
            // viewer-only or wrong-namespace editor can read/write this one
            // page's screens but must not see a rail icon implying they can
            // create pages anywhere (mirrors the old top-bar New link).
            'canCreate' => $principal?->hasAnyWriteAccess() ?? false,
            'canWrite' => $canWrite,
            // null, not just an inert icon/tab, when the caller cannot
            // write here — the rail's Editor icon and the tab strip's Edit
            // tab must both be gated exactly like the old Edit button was,
            // never merely "does a page exist to point at".
            'railEditHref' => $canWrite ? '/' . $path . '/edit' : null,
        ];
    }
}
