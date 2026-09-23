<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

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
    ) {
    }

    public function render(PageRecord $record, bool $isOwner, string $basePath = ''): string
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
        ];

        if ($isOwner) {
            $vars += [
                'path' => $record->path,
                'rev' => $record->rev,
                'status' => $record->status,
                'visibility' => $record->visibility,
            ];
        }

        $template = $isOwner ? 'page-view.php' : 'layout-public.php';

        return View::render(\dirname(__DIR__, 2) . '/templates/' . $template, $vars);
    }
}
