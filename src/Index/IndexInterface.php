<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Index;

use Reporion\Auth\User;

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
     * null both when the page does not exist and when $principal is not
     * entitled to it (CLAUDE.md invariant 9: 404, never 403). $principal
     * is null for an anonymous caller.
     *
     * @return array<string, mixed>|null
     */
    public function findByPath(string $path, ?User $principal): ?array;

    /**
     * One namespace's direct children (Search\Query::visibilityClause()).
     *
     * @return list<array<string, mixed>>
     */
    public function listNamespace(string $ns, ?User $principal): array;

    /**
     * Same rows as listNamespace(), ordered most-recently-updated first —
     * the Workbench worklist sidebar (Search\Query::visibilityClause()).
     *
     * @return list<array<string, mixed>>
     */
    public function listWorklist(string $ns, ?User $principal, int $limit = 20): array;

    /**
     * The Workbench status bar's two real numbers — total and draft count,
     * visibility-filtered (Search\Query::visibilityClause()).
     *
     * @return array{total: int, draft: int}
     */
    public function namespaceStats(string $ns, ?User $principal): array;

    /**
     * The immediate sub-namespaces of $ns, each with a page count
     * (Search\Query::visibilityClause()) — pages directly in $ns itself
     * are not sub-namespaces and are excluded.
     *
     * @return list<array{name: string, count: int}>
     */
    public function listSubnamespaces(string $ns, ?User $principal): array;

    /**
     * Every page, listing rules applied (Search\Query::visibilityClause()).
     *
     * @return list<array<string, mixed>>
     */
    public function listSitemap(?User $principal): array;

    /**
     * Full-text search, listing rules applied (Search\Query::visibilityClause()).
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term, ?User $principal): array;

    /**
     * All pages for one patient, ordered by study date desc
     * (Search\Query::visibilityClause()).
     *
     * @return list<array<string, mixed>>
     */
    public function findByPatientKey(string $patientKey, ?User $principal): array;
}
