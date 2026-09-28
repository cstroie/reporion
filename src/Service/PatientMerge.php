<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Audit\AuditLog;
use Reporion\Http\Request;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;

/**
 * Confirming a "possible match" on the patient tab (TODO 13,
 * Controller\TimelineController::timeline()'s $possibleMatches) — the
 * caller has looked at both reports and decided they are the same person.
 *
 * The confirmation writes an explicit `patient.key` override onto the
 * *target* page's frontmatter, set to the *source* page's own patient key
 * (strong if it has one, else weak) — a new revision, through Storage, same
 * as any other frontmatter write (invariant 5, D11's escape hatch, no
 * separate conf/patient_merges.json). It is deliberately reversible: delete
 * `patient.key` from the page's frontmatter (the editor's Details panel, or
 * by hand) and it goes back to being computed from cnp/name/born/sex.
 *
 * Both pages' readability/writability are the caller's job
 * (Controller\PatientMergeController): this only writes, given an already
 * resolved, already-authorized key.
 */
final class PatientMerge
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly AuditLog $audit,
    ) {
    }

    public function confirm(string $key, string $targetPath, string $actor, ?string $sourcePid = null, ?Request $request = null): PageRecord
    {
        $target = $this->storage->read($targetPath);
        $patient = \is_array($target->frontmatter['patient'] ?? null) ? $target->frontmatter['patient'] : [];
        $patient['key'] = $key;
        $frontmatter = Publishing::merge($target->frontmatter, ['patient' => $patient]);

        $saved = $this->storage->save($targetPath, $frontmatter, $target->body, $target->rev, $actor, 'patient identity confirmed (merge)');

        $this->audit->record('patient.merge', $actor, $request, $saved->pid, $saved->path, $saved->rev, extra: ['source_pid' => $sourcePid]);

        return $saved;
    }
}
