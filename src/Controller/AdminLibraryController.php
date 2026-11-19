<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;

/**
 * GET /admin/library[?run={id}]: Admin → Library, owner-only. The library's
 * settings (lookups, web clip, PubMed search, Unpaywall, OpenAlex, the
 * assistant's language and budget: SettingsPanels, saved by POST
 * /admin/settings/library) and its tasks (library:abstracts,
 * library:digest, library:notices: TaskPanels), docs/library.md.
 */
final class AdminLibraryController
{
    public function __construct(
        private readonly IndexInterface $index,
        private readonly SettingsPanels $settings,
        private readonly TaskPanels $tasks,
    ) {
    }

    /** $where: the settings section a refused save belongs to, or null for a refused task run */
    public function show(Request $request, ?User $principal, ?string $error = null, ?string $where = null): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-library.php', [
            'adminTab' => 'library',
            'basePath' => $request->basePath,
        ] + $this->tasks->vars($request, $principal, 'library', $where === null ? $error : null)
          + $this->settings->vars($request, $where !== null ? $error : null, $where)
          + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.library.title')), $error === null ? 200 : 422);
    }
}
