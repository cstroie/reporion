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
     * Replay any journal write-intents left open by a crash. Idempotent —
     * safe to call repeatedly, safe to call when there is nothing to do.
     *
     * @return list<array{pid: string, rev: int, outcome: string}>
     */
    public function replayJournal(): array;
}
