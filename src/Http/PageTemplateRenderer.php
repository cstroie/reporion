<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Service\Render;
use Reporion\Storage\PageRecord;
use Reporion\Support\MetaText;

/**
 * A4 (docs/architecture-api.md §6): the owner and public layouts render the
 * same document body from the same Render::toHtml() call — only the
 * surrounding chrome differs — so the reader view cannot drift from the
 * report view. Shared by Controller\PageController and Controller\HomeController.
 */
final class PageTemplateRenderer
{
    /** Frontmatter fields templates/layout-public.php prints — nothing else reaches it */
    private const PUBLIC_FIELDS = ['device'];

    public function __construct(
        private readonly Render $render,
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
    /**
     * @param ?int $currentRev set when $record is an older revision
     *                         (/{path}@{rev}): the page's current rev
     * @param ?array{by: string, ts: string, alg: string, digest: string, parafa: ?string, matches: bool} $signature
     *                         $record's signature, when shown for verification
     */
    public function render(PageRecord $record, ?User $principal, Request $request, ?int $currentRev = null, ?array $signature = null): string
    {
        $rendered = $this->render->toHtml($record->body, $request->basePath);
        $title = MetaText::text($record->frontmatter['title'] ?? null);
        if ($title === '') {
            $title = $record->path;
        }

        // The full frontmatter (patient block included) goes to the
        // signed-in page view only, whose metadata panel is for staff. The
        // anonymous public layout gets just the fields it prints — the
        // patient identity must not even be in its scope (invariant 8).
        $vars = [
            'title' => $title,
            'contentHtml' => $rendered->html,
            'toc' => $rendered->toc,
            'warnings' => $rendered->warnings,
            'basePath' => $request->basePath,
            'currentRev' => $currentRev,
            'signature' => $signature,
        ];

        $vars['backlinks'] = $this->index->backlinks($record->pid, $principal);
        $latestRev = $record->revlog[array_key_last($record->revlog)] ?? null;
        $vars['latestRev'] = $latestRev;
        $vars += [
            'rev' => $record->rev,
            'path' => $record->path,
            'visibility' => $record->visibility,
            'status' => $record->status,
        ];

        if ($principal === null) {
            $public = array_intersect_key($record->frontmatter, array_flip(self::PUBLIC_FIELDS));

            return View::render(\dirname(__DIR__, 2) . '/templates/layout-public.php', $vars + ['frontmatter' => $public]);
        }

        $vars['frontmatter'] = $record->frontmatter;

        $vars += ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($record->path));
        // No header for the stub home page: there is no page to act on
        if ($record->pid !== '') {
            $vars += ChromeVars::pageHeader(
                $principal,
                $record->path,
                'view',
                $title,
                $record->visibility,
                $record->status,
                $record->rev,
                $record->pid,
                isset($record->frontmatter['device']) ? MetaText::text($record->frontmatter['device']) : null,
                \is_array($latestRev) ? (string) $latestRev['ts'] : null,
                \is_array($latestRev) ? (string) $latestRev['by'] : null,
            );
        }

        return View::page(\dirname(__DIR__, 2) . '/templates/page-view.php', $vars, $title);
    }
}
