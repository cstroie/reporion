<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use Reporion\Auth\User;
use Reporion\Service\Render;
use Reporion\Storage\PageRecord;

/**
 * A4 (docs/architecture-api.md §6): the owner and public layouts render the
 * same document body from the same Render::toHtml() call — only the
 * surrounding chrome differs — so the reader view cannot drift from the
 * report view. Shared by Controller\PageController and Controller\HomeController.
 */
final class PageTemplateRenderer
{
    public function __construct(
        private readonly Render $render,
        private readonly int $trashPurgeDays,
    ) {
    }

    /**
     * $principal picks the chrome (the "app" view vs the bare public
     * layout) for whoever is already established as entitled to read
     * $record — that decision happened in Index\Sqlite's query
     * (Search\Query), not here. Any signed-in user gets the app chrome now,
     * not just the owner: an editor or viewer with a namespace grant is
     * ordinary staff using the app, the same as the owner is (D35).
     */
    public function render(PageRecord $record, ?User $principal, string $basePath = ''): string
    {
        $rendered = $this->render->toHtml($record->body);
        $title = (string) ($record->frontmatter['title'] ?? $record->path);

        // $record->frontmatter is deliberately never passed to either
        // template — it carries the full patient block (CLAUDE.md
        // invariant 8), and neither template needs it today.
        $vars = [
            'title' => $title,
            'contentHtml' => $rendered->html,
            'toc' => $rendered->toc,
            'warnings' => $rendered->warnings,
            'basePath' => $basePath,
            // Set unconditionally, not only when signed in: only
            // page-view.php reads this (to show/hide the admin link), but
            // a var that exists on only one of two render paths is a
            // latent break waiting for the next caller — false is the
            // correct value for the anonymous/layout-public.php path too.
            'isOwner' => $principal?->isOwner ?? false,
            // Same reasoning: gates the Edit link. Read access to $record
            // is already established by the time this renders (Index\Sqlite's
            // query decided that) — this only answers whether they may
            // additionally write to it.
            'canWrite' => $principal?->canWrite($record->path) ?? false,
            // Gates the global "New" nav link — deliberately not the same
            // question as canWrite($record->path): a viewer-only or
            // wrong-namespace editor can read this specific page but must
            // not see a link implying they can create pages anywhere.
            'canCreate' => $principal?->hasAnyWriteAccess() ?? false,
            // Only the delete menu item's label reads this; set
            // unconditionally for the same reason as isOwner/canWrite above.
            'trashPurgeDays' => $this->trashPurgeDays,
        ];

        $isSignedIn = $principal !== null;
        if ($isSignedIn) {
            $vars += [
                'path' => $record->path,
                'rev' => $record->rev,
                'status' => $record->status,
                'visibility' => $record->visibility,
                // templates/rail.php's view-model: computed here, not in
                // the template, same as every other var above — the
                // template's job is display, not deriving a route from
                // $record->path itself. null, not just an inert icon, when
                // the caller cannot write here — the rail's Editor icon
                // must be gated exactly like the old Edit button was
                // (canWrite above), not merely "does a page exist to
                // point at": a viewer must not see a live-looking edit
                // link for a page they cannot save.
                'railActive' => 'view',
                'railEditHref' => $vars['canWrite'] ? '/' . $record->path . '/edit' : null,
            ];
        }

        $template = $isSignedIn ? 'page-view.php' : 'layout-public.php';

        return View::render(\dirname(__DIR__, 2) . '/templates/' . $template, $vars);
    }
}
