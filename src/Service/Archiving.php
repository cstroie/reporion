<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\NotRadiologistException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ReportPath;

/**
 * Archive a report that will not be signed here (D65): a finished report by
 * someone else, imported or typed in. Reversible: unarchive returns it to
 * a draft. A radiologist with the write grant (User::canActOnReport());
 * never a page that carries a signature (Storage refuses it). Audited
 * `page.archive` / `page.unarchive` (pid and path hash only).
 */
final class Archiving
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @throws PageNotFoundException not a report, or not the caller's to write
     * @throws NotRadiologistException
     */
    public function archive(PageRecord $record, User $actor, int $baseRev, ?Request $request): PageRecord
    {
        return $this->set($record, $actor, 'archived', $baseRev, $request);
    }

    /**
     * @throws PageNotFoundException
     * @throws NotRadiologistException
     */
    public function unarchive(PageRecord $record, User $actor, int $baseRev, ?Request $request): PageRecord
    {
        return $this->set($record, $actor, 'draft', $baseRev, $request);
    }

    /** @param 'draft'|'archived' $status */
    private function set(PageRecord $record, User $actor, string $status, int $baseRev, ?Request $request): PageRecord
    {
        if (!ReportPath::isReport($record->path)) {
            throw new PageNotFoundException();
        }
        // As signing (D37, D47): seen but not a radiologist's is a 403; anyone else who may not write it, not there
        if (!$actor->isRadiologist() && $actor->canRead($record->path)) {
            throw new NotRadiologistException();
        }
        if (!$actor->canActOnReport($record->path)) {
            throw new PageNotFoundException();
        }
        if ($record->status === $status) {
            return $record;
        }
        $changed = $this->storage->setStatus($record->path, $status, $baseRev, $actor->username);
        $this->audit->record($status === 'archived' ? 'page.archive' : 'page.unarchive', $actor->username, $request, $changed->pid, $changed->path, $changed->rev);

        return $changed;
    }
}
