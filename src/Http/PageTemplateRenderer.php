<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
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
        private readonly IndexInterface $index,
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
    public function render(PageRecord $record, ?User $principal, Request $request): string
    {
        $rendered = $this->render->toHtml($record->body);
        $title = (string) ($record->frontmatter['title'] ?? $record->path);

        // $record->frontmatter is deliberately never passed to either
        // template — it carries the full patient block (CLAUDE.md
        // invariant 8), and neither template needs it today.
        //
        // isOwner/canWrite/canCreate/railEditHref set unconditionally, not
        // only when signed in: only page-view.php reads them, but a var
        // that exists on only one of two render paths is a latent break
        // waiting for the next caller — false/null is the correct value
        // for the anonymous/layout-public.php path too.
        $vars = [
            'title' => $title,
            'contentHtml' => $rendered->html,
            'toc' => $rendered->toc,
            'warnings' => $rendered->warnings,
            'basePath' => $request->basePath,
            // Only the delete menu item's label reads this; set
            // unconditionally for the same reason as everything from
            // ChromeVars below.
            'trashPurgeDays' => $this->trashPurgeDays,
        ] + ChromeVars::forPath($principal, $record->path) + ChromeVars::theme($request);

        $isSignedIn = $principal !== null;
        if ($isSignedIn) {
            $vars += [
                'path' => $record->path,
                'rev' => $record->rev,
                'status' => $record->status,
                'visibility' => $record->visibility,
                // templates/rail.php's and templates/tabs.php's view-model
                // — computed here, not in either template, same as every
                // other var above.
                'railActive' => 'view',
                'tabActive' => 'view',
            ] + ChromeVars::worklist($this->index, $principal, $record->path);
        }

        $template = $isSignedIn ? 'page-view.php' : 'layout-public.php';

        return View::render(\dirname(__DIR__, 2) . '/templates/' . $template, $vars);
    }
}
