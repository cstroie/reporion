<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Storage;

/**
 * The only thing allowed to touch a page path on disk (CLAUDE.md invariant 5).
 * Controllers, plugins, importers and CLI commands call this, never
 * file_put_contents directly.
 */
interface StorageInterface
{
    /**
     * @param array<string, mixed> $frontmatter
     */
    public function create(string $path, array $frontmatter, string $body, string $actor, ?string $note = null): PageRecord;

    /**
     * @param array<string, mixed> $frontmatter
     *
     * @throws \Reporion\Exception\PageNotFoundException when $path does not exist
     * @throws \Reporion\Exception\RevisionConflictException when $baseRev is not the current rev
     */
    public function save(string $path, array $frontmatter, string $body, int $baseRev, string $actor, ?string $note = null): PageRecord;

    /**
     * @throws \Reporion\Exception\PageNotFoundException
     */
    public function read(string $path): PageRecord;

    /**
     * The canonical plaintext bytes of one historical revision (frontmatter + body).
     *
     * @throws \Reporion\Exception\PageNotFoundException
     */
    public function readRevision(string $path, int $rev): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function revisions(string $path): array;

    /**
     * A2 (docs/architecture-api.md): revert is a forward operation.
     * Restoring revision $toRev writes a brand new revision whose bytes
     * equal $toRev's, verbatim — history never loses a step and the old
     * revision (and its signature, if any — D3b) is never touched. The new
     * revision's status is never carried forward as `signed`: reverting to
     * an old signed revision produces a fresh, unsigned draft that must be
     * signed again in its own right, exactly like any other edit.
     *
     * @throws \Reporion\Exception\PageNotFoundException when $path or $toRev does not exist
     */
    public function revert(string $path, int $toRev, string $actor, ?string $note = null): PageRecord;

    /**
     * Appends a signature record for the *current* revision and sets
     * `status: signed` — a meta.json-only operation, never a new revision:
     * signing doesn't change what the document says, only that it is now
     * legally the report (docs/architecture-storage-index.md §5). No
     * journal entry either — a single atomic meta.json rewrite (temp +
     * fsync + rename) is already all-or-nothing on its own; there is no
     * multi-file sequence for a crash to leave half-done.
     *
     * Idempotent per revision: a repeated sign of a revision that already
     * has a signature record is a no-op, not a second signatures[] entry —
     * the same "duplicate submission" reasoning create()/save() already
     * apply to a retried request.
     *
     * $schemaFields is `Schema\Loader::fieldsFor()`'s result, passed
     * through unchanged to `Support\Canonical::bytes()` for the
     * signature's key ordering — Storage does not depend on Schema\Loader
     * itself; the caller resolves it and (via Schema\Validator, before
     * ever calling this) confirms the page is actually complete enough to
     * sign. This method does not re-check that.
     *
     * @param array<string, array<string, mixed>> $schemaFields
     *
     * @throws \Reporion\Exception\PageNotFoundException
     */
    public function sign(string $path, string $actor, array $schemaFields, ?string $parafa = null): PageRecord;

    /**
     * Replay any journal write-intents left open by a crash. Idempotent —
     * safe to call repeatedly, safe to call when there is nothing to do.
     *
     * @return list<array{pid: string, rev: int, outcome: string}>
     */
    public function replayJournal(): array;

    /**
     * Soft delete (docs/architecture-storage-index.md §3, D3b): moves the
     * page directory into trash/, intact — history, meta.json and all. Not
     * implemented here: `?purge=1` (permanent deletion) — D3b requires an
     * audit entry naming the operator for that, and audit log
     * infrastructure (docs/FORMATS.md §6) does not exist yet.
     *
     * @throws \Reporion\Exception\PageNotFoundException
     */
    public function delete(string $path, string $actor): void;
}
