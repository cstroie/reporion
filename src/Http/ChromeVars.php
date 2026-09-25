<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;

/**
 * The view-models for the app shell (A6): templates/layout.php (shell()),
 * templates/page-header.php (pageHeader()) and the display preferences
 * (theme()). One place, so every screen's chrome is computed the same way.
 */
final class ChromeVars
{
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

    /**
     * templates/layout.php's view-model (A6): who is signed in, what the
     * top nav may offer them, and the namespace drawer's listing for $ns —
     * visibility-filtered by the same queries as every other listing
     * (invariant 6), never a new predicate.
     *
     * @return array<string, mixed>
     */
    public static function shell(Request $request, ?User $principal, IndexInterface $index, string $ns): array
    {
        return [
            'basePath' => $request->basePath,
            'username' => $principal?->username ?? '',
            'isOwner' => $principal?->isOwner ?? false,
            'canCreate' => $principal?->hasAnyWriteAccess() ?? false,
            'nsHref' => '/' . $ns . ':',
            'drawerNs' => $ns,
            'drawerSubnamespaces' => $index->listSubnamespaces($ns, $principal),
            'drawerRows' => $index->listWorklist($ns, $principal),
        ] + self::theme($request);
    }

    /**
     * templates/page-header.php's view-model, shared by every route of one
     * page — view, edit, history, compare, patient (A6). $tab is the
     * active one.
     *
     * @return array<string, mixed>
     */
    public static function pageHeader(
        ?User $principal,
        string $path,
        string $tab,
        string $title,
        string $visibility,
        string $status,
        int $rev,
        string $pid,
        ?string $device,
        ?string $updated,
        ?string $updatedBy,
    ): array {
        return [
            'headerPath' => $path,
            'headerTab' => $tab,
            'headerTitle' => $title !== '' ? $title : $path,
            'headerVisibility' => $visibility,
            'headerStatus' => $status,
            'headerRev' => $rev,
            'headerPid' => $pid,
            'headerDevice' => $device,
            'headerUpdated' => $updated,
            'headerUpdatedBy' => $updatedBy,
            'canWrite' => $principal?->canWrite($path) ?? false,
        ];
    }

    /**
     * pageHeader() from an Index\Sqlite pages row (findByPath()).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function pageHeaderFromRow(array $row, ?User $principal, string $tab): array
    {
        return self::pageHeader(
            $principal,
            (string) $row['path'],
            $tab,
            (string) $row['title'],
            (string) $row['visibility'],
            (string) $row['status'],
            (int) $row['rev'],
            (string) $row['pid'],
            isset($row['device']) ? (string) $row['device'] : null,
            isset($row['updated']) ? (string) $row['updated'] : null,
            isset($row['updated_by']) ? (string) $row['updated_by'] : null,
        );
    }

    /** The namespace a page lives in: its path minus the last segment. */
    public static function namespaceOf(string $path): string
    {
        $segments = explode(':', $path);
        array_pop($segments);

        return implode(':', $segments);
    }
}
