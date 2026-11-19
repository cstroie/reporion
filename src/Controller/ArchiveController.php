<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\NotRadiologistException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Exception\SignedPageException;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Service\Archiving;
use Reporion\Storage\StorageInterface;

/**
 * Archive / unarchive a report that will not be signed here (D65):
 * POST /api/v1/pages/{path}/archive|unarchive {base_rev} and the page
 * header's form POST /{path}/archive|unarchive (a redirect back).
 */
final class ArchiveController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly Archiving $archiving,
    ) {
    }

    public function api(Request $request, string $path, ?User $principal, bool $archive): Response
    {
        if ($principal === null) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        $fields = $request->json();
        $baseRev = \is_int($fields['base_rev'] ?? null) ? $fields['base_rev'] : null;
        if ($baseRev === null) {
            return ApiResponse::error(422, 'invalid', 'base_rev is required.', ['base_rev' => 'required']);
        }

        try {
            $record = $this->storage->read($path);
            $changed = $archive
                ? $this->archiving->archive($record, $principal, $baseRev, $request)
                : $this->archiving->unarchive($record, $principal, $baseRev, $request);
        } catch (PageNotFoundException) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        } catch (NotRadiologistException) {
            return ApiResponse::error(403, 'not_radiologist', 'Only a radiologist may archive a report.');
        } catch (SignedPageException) {
            return ApiResponse::error(409, 'signed', 'A signed report is corrected and signed again, never archived.');
        } catch (RevisionConflictException $e) {
            return ApiResponse::error(409, 'conflict', 'The page changed since base_rev.', ['rev' => $e->current->rev]);
        }

        return ApiResponse::json(['pid' => $changed->pid, 'path' => $changed->path, 'rev' => $changed->rev, 'status' => $changed->status]);
    }

    /** The page header's form: back to the page, whatever happened (its badge shows the status) */
    public function form(Request $request, string $path, ?User $principal, bool $archive): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $baseRev = \is_string($fields['base_rev'] ?? null) && ctype_digit($fields['base_rev']) ? (int) $fields['base_rev'] : 0;
        $record = $this->storage->read($path);
        try {
            $archive
                ? $this->archiving->archive($record, $principal, $baseRev, $request)
                : $this->archiving->unarchive($record, $principal, $baseRev, $request);
        } catch (RevisionConflictException | SignedPageException) {
            // A stale or signed page: nothing changed; the page shows what it is now
        }

        return Response::redirect($request->basePath . '/' . $record->path);
    }
}
