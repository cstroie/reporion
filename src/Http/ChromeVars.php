<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;

/**
 * The view-model every signed-in document screen needs for its chrome
 * (templates/rail.php, templates/tabs.php, templates/worklist.php) —
 * extracted here once `Controller\EditorController` and
 * `Controller\HistoryController` needed the identical
 * isOwner/canCreate/canWrite/railEditHref computation
 * `Http\PageTemplateRenderer` already had for `Controller\PageController`.
 * One formula, three callers, instead of three copies drifting apart.
 */
final class ChromeVars
{
    /**
     * @return array{isOwner: bool, canCreate: bool, canWrite: bool, railEditHref: ?string, railNsHref: string}
     */
    public static function forPath(?User $principal, string $path): array
    {
        $canWrite = $principal?->canWrite($path) ?? false;

        // Same "segments minus the last one" derivation as worklist()'s
        // $ns — '' (root) when $path has nothing before its last segment,
        // which GET /: (the root namespace index) now serves, so this is
        // never null: unlike railEditHref this isn't a permission gate,
        // visibility is enforced by the namespace-index route itself, same
        // as the Search icon.
        $segments = explode(':', $path);
        array_pop($segments);
        $ns = implode(':', $segments);

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
            'railNsHref' => '/' . $ns . ':',
        ];
    }

    /**
     * templates/worklist.php's view-model — the namespace derived from
     * $path the same way PageController::delete()'s post-delete redirect
     * already does (segments minus the last one), and the listing itself
     * going through the same visibility-filtered query
     * (Index\Sqlite::listWorklist()) as every other listing in this app.
     *
     * Also carries templates/status.php's two real numbers
     * (Index\Sqlite::namespaceStats()) — same namespace, same visibility
     * predicate, no reason to derive $ns or hit the index twice.
     *
     * @return array{worklistNs: string, worklistRows: list<array<string, mixed>>, statusTotal: int, statusDraft: int}
     */
    public static function worklist(IndexInterface $index, ?User $principal, string $path): array
    {
        $segments = explode(':', $path);
        array_pop($segments);
        $ns = implode(':', $segments);
        $stats = $index->namespaceStats($ns, $principal);

        return [
            'worklistNs' => $ns,
            'worklistRows' => $index->listWorklist($ns, $principal),
            'statusTotal' => $stats['total'],
            'statusDraft' => $stats['draft'],
        ];
    }

    /**
     * The display preferences for <body>: theme and palette cookies (A6),
     * both plain POST + redirect forms in the top nav — no JavaScript, no
     * localStorage. No principal required: a display preference isn't
     * gated on being signed in.
     *
     * @return array{theme: string, palette: string, themeBodyClass: string, currentUrl: string}
     */
    public static function theme(Request $request): array
    {
        $theme = $request->cookie(Theme::COOKIE_NAME) === 'light' ? 'light' : 'dark';
        $palette = Theme::palette($request->cookie(Theme::PALETTE_COOKIE_NAME));

        return [
            'theme' => $theme,
            'palette' => $palette,
            'themeBodyClass' => ($theme === 'light' ? ' theme-light' : '')
                . ($palette === Theme::DEFAULT_PALETTE ? '' : ' palette-' . $palette),
            'currentUrl' => $request->path,
        ];
    }
}
