<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Service\PageMoves;
use Reporion\Storage\FileTree;
use Reporion\Storage\PageRecord;
use Reporion\Support\LibrarySource;

/**
 * Two library sources that are one article merged into one (docs/library.md, D60; EndNote's and
 * Zotero's "merge duplicates"). The survivor keeps its key, gets the fields, tags, original and text
 * it lacked from the other, and the other goes to the trash with a redirect to it; every page that
 * cited the other is re-cited to the survivor.
 *
 * The order leaves two whole pages if it stops half way (nothing is lost until the last step):
 * 1 the survivor saved; 2 the citing pages re-cited (an unsigned page: a new revision; a signed one is
 * never edited, D3: its `[@old]` still finds the survivor through the redirect, Citations::record());
 * 3 the other deleted with its redirect; 4 its saved analyses and abstract follow it to the trash.
 * Its comments go to the trash with it (they are the page's, D52). The other's original file stays in
 * data/sources/ (content-addressed; the trashed page still names it).
 */
final class Merge
{
    public function __construct(
        private readonly Library $library,
        private readonly FileTree $storage,
        private readonly AuditLog $audit,
        private readonly PageMoves $moves,
        private readonly Analyses $analyses,
    ) {
    }

    /**
     * @return array{into: PageRecord, from_pid: string, from_key: string, changed: list<string>, relinked: int, left_signed: int, subpages: int}
     *
     * @throws PageNotFoundException when either is missing or the caller may not write both (they should not learn which)
     * @throws LibraryException INVALID (not sources, or the same one)
     * @throws \Reporion\Exception\RevisionConflictException
     */
    public function merge(string $into, string $from, User $principal, string $actor, ?Request $request = null): array
    {
        if (!$principal->canWrite($into) || !$principal->canWrite($from)) {
            throw new PageNotFoundException();
        }
        $loser = $this->storage->read($from);
        if (!LibrarySource::isSource($loser->frontmatter) || $into === $from) {
            throw new LibraryException(LibraryException::INVALID);
        }

        ['page' => $kept, 'changed' => $changed] = $this->library->mergeRecords($into, $from, $actor);
        if ($changed !== []) {
            $this->audit->record('page.save', $actor, $request, $kept->pid, $kept->path, $kept->rev, extra: ['merged_from' => $loser->pid, 'fields' => \count($changed)]);
        }

        ['fixed' => $fixed, 'skippedSigned' => $signed] = $this->moves->relink([$from => $kept->path], $actor, 'source merged into ' . substr($kept->path, \strlen($this->library->namespace()) + 1));
        foreach ($fixed as $page) {
            $this->audit->record('page.save', $actor, $request, $page->pid, $page->path, $page->rev, extra: ['reason' => 'link-fixup', 'merged_from' => $loser->pid]);
        }

        $this->storage->delete($from, $actor, $kept->pid);
        $this->audit->record('page.delete', $actor, $request, $loser->pid, $loser->path, $loser->rev, extra: ['merged_into' => $kept->pid]);
        $subpages = $this->analyses->trashWith($from, $actor, $this->audit, $request);
        $this->audit->record('library.merge', $actor, $request, $kept->pid, $kept->path, $kept->rev, extra: ['from' => $loser->pid, 'relinked' => \count($fixed), 'left_signed' => $signed]);

        return [
            'into' => $this->storage->read($kept->path),
            'from_pid' => $loser->pid,
            'from_key' => substr($from, \strlen($this->library->namespace()) + 1),
            'changed' => $changed,
            'relinked' => \count($fixed),
            'left_signed' => $signed,
            'subpages' => $subpages,
        ];
    }
}
