<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Index;

/**
 * The index is a disposable cache (CLAUDE.md invariant 1): every method here
 * must be safe to skip, retry or fully replay from disk without loss.
 * Storage\FlatFile calls index() as the last step of every durable write;
 * if it throws, the write already committed to disk and must not be undone
 * (see docs/architecture-storage-index.md §5).
 */
interface IndexInterface
{
    public function index(PageSnapshot $snapshot): void;

    public function remove(string $pid): void;

    /**
     * Direct lookup of one known path (Search\Query::pageAccessClause()) —
     * null both when the page does not exist and when it is private and
     * $isOwner is false (CLAUDE.md invariant 9: 404, never 403).
     *
     * @return array<string, mixed>|null
     */
    public function findByPath(string $path, bool $isOwner): ?array;

    /**
     * One namespace's direct children (Search\Query::visibilityClause()).
     *
     * @return list<array<string, mixed>>
     */
    public function listNamespace(string $ns, bool $isOwner): array;

    /**
     * Every page, listing rules applied (Search\Query::visibilityClause()).
     *
     * @return list<array<string, mixed>>
     */
    public function listSitemap(bool $isOwner): array;

    /**
     * Full-text search, listing rules applied (Search\Query::visibilityClause()).
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term, bool $isOwner): array;
}
