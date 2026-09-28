<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\IndexInterface;
use Reporion\Service\PatientMerge;

/**
 * POST /{path}/patient-merge — confirming a patient-tab "possible match"
 * (Controller\TimelineController) as the same patient. {path} is the report
 * currently on screen (the source of the patient key); the POST body's
 * `target` is the candidate report being allocated to it. Redirects back to
 * {path}'s timeline either way, with `?merge=ok` or `?merge=<error code>`
 * for templates/timeline.php to show.
 */
final class PatientMergeController
{
    public function __construct(
        private readonly IndexInterface $index,
        private readonly PatientMerge $merge,
    ) {
    }

    public function confirm(Request $request, string $path, ?User $principal): Response
    {
        $source = $this->index->findByPath($path, $principal);
        if ($source === null) {
            throw new PageNotFoundException();
        }

        parse_str($request->body, $fields);
        $target = \is_string($fields['target'] ?? null) ? trim($fields['target']) : '';

        $back = $request->basePath . '/' . $path . '/timeline';

        if ($target === '' || $principal === null || !$principal->canWrite($target) || $this->index->findByPath($target, $principal) === null) {
            throw new PageNotFoundException();
        }

        $key = (string) ($source['patient_key'] ?? '') ?: (string) ($source['patient_key_weak'] ?? '');
        if ($key === '') {
            return Response::redirect($back . '?merge=nokey');
        }

        try {
            $this->merge->confirm($key, $target, $principal->username, (string) ($source['pid'] ?? null), $request);
        } catch (RevisionConflictException) {
            return Response::redirect($back . '?merge=conflict');
        }

        return Response::redirect($back . '?merge=ok');
    }
}
