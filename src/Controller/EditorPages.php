<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Http\ApiResponse;
use Reporion\Http\Response;
use Reporion\Index\IndexInterface;
use Reporion\Service\EditorPage;
use Reporion\Storage\PageRecord;
use Reporion\Support\ReportPath;

/**
 * The one access rule of the editor's report aids (Recall D50, Checks and
 * Prior D51): a report a radiologist may write (User::canActOnReport()),
 * stored or, before its first Save, the page it will be
 * (Service\EditorPage). A caller who may read the stored report but is no
 * radiologist gets 403 `not_radiologist`; anyone else, and a page that is
 * not a report, 404 (invariant 9).
 */
final class EditorPages
{
    public function __construct(
        private readonly IndexInterface $index,
        private readonly EditorPage $pages,
    ) {
    }

    /**
     * @param string $form     the editor's form, urlencoded, for a page not written yet (Service\EditorPage)
     * @param string $document the text in front, when no form is sent
     */
    public function forReport(string $path, ?User $principal, string $form = '', string $document = ''): PageRecord|Response
    {
        if ($principal === null || $path === '' || !ReportPath::isReport($path)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        if (!$principal->canActOnReport($path)) {
            return !$principal->isRadiologist() && $this->index->findByPath($path, $principal) !== null
                ? ApiResponse::error(403, 'not_radiologist', 'Only a radiologist may write reports.')
                : ApiResponse::error(404, 'not_found', 'Not found.');
        }

        return $this->pages->resolve($path, $principal, $form, $document) ?? ApiResponse::error(404, 'not_found', 'Not found.');
    }
}
