<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Publishing;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;

/**
 * GET/POST /{path}/visibility — the page's Visibility… action, under the
 * page header (Service\Publishing): making a page public first shows what
 * becomes visible and needs an explicit acknowledgement (D16); a signed
 * report is refused with the way forward (publish a duplicate).
 */
final class VisibilityController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly Publishing $publishing,
    ) {
    }

    public function form(Request $request, string $path, ?User $principal): Response
    {
        $page = $this->writablePage($path, $principal);

        return $this->render($request, $principal, $page, chosen: $page->visibility, confirm: false, error: null);
    }

    public function change(Request $request, string $path, ?User $principal): Response
    {
        $page = $this->writablePage($path, $principal);
        parse_str($request->body, $fields);
        $visibility = \is_string($fields['visibility'] ?? null) ? $fields['visibility'] : '';
        $changes = ['visibility' => $visibility];

        if (Publishing::needsAcknowledgement($page, $changes) && ($fields['acknowledge'] ?? null) !== '1') {
            return $this->render($request, $principal, $page, chosen: $visibility, confirm: true, error: null);
        }

        try {
            $this->publishing->apply($page, $changes, isset($fields['base_rev']) ? (int) $fields['base_rev'] : $page->rev, $principal->username, $request);
        } catch (InvalidArgumentException $e) {
            return $this->render($request, $principal, $page, chosen: $visibility, confirm: false, error: $e->getMessage());
        } catch (RevisionConflictException) {
            return $this->render($request, $principal, $this->storage->read($path), chosen: $visibility, confirm: false, error: t('vis.err_conflict'));
        }

        return Response::redirect($request->basePath . '/' . $path);
    }

    private function writablePage(string $path, ?User $principal): PageRecord
    {
        if ($principal === null || !$principal->canWrite($path) || $this->index->findByPath($path, $principal) === null) {
            throw new PageNotFoundException();
        }

        return $this->storage->read($path);
    }

    private function render(Request $request, User $principal, PageRecord $page, string $chosen, bool $confirm, ?string $error): Response
    {
        $indexed = $this->index->findByPath($page->path, $principal);
        if ($indexed === null) {
            throw new PageNotFoundException();
        }

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/page-visibility.php', [
            'path' => $page->path,
            'rev' => $page->rev,
            'current' => $page->visibility,
            'chosen' => $chosen,
            'signed' => $page->status === 'signed',
            'confirm' => $confirm,
            'preview' => Publishing::preview($page),
            'error' => $error,
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($page->path))
          + ChromeVars::pageHeaderFromRow($indexed, $principal, 'visibility'), t('vis.title')), $error !== null ? 422 : 200);
    }
}
