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
     * The same direct lookup by pid (Search\Query::pageAccessClause()) —
     * the /r/{pid}/{rev} permalink, which survives renames. Knowing a pid is
     * treated like knowing an exact path: unlisted is reachable, private
     * only with a covering grant, and a refusal is indistinguishable from
     * "does not exist".
     *
     * @return array<string, mixed>|null
     */
    public function findByPid(string $pid, ?User $principal): ?array;

    /**
     * One namespace's direct children (Search\Query::visibilityClause()).
     *
     * @return list<array<string, mixed>>
     */
    public function listNamespace(string $ns, ?User $principal): array;

    /**
     * Same rows as listNamespace(), ordered most-recently-updated first —
     * the namespace drawer (Search\Query::visibilityClause()).
     *
     * @return list<array<string, mixed>>
     */
    public function listWorklist(string $ns, ?User $principal, int $limit = 20): array;

    /**
     * Recently updated pages across every namespace — the signed-in
     * dashboard (GET /). A listing (Search\Query::visibilityClause()): a
     * caller sees only what search and the namespace index would show them.
     * Filters, all optional: `modality` / `region` (one value each, D29 list
     * semantics), `ns` (that namespace and everything under it), `site`,
     * `status`, `updated_by`, `since` (ISO 8601, against `updated`),
     * `study_from` / `study_to` (dates, against `study_date`).
     *
     * @param array<string, string> $filters
     *
     * @return list<array<string, mixed>>
     */
    public function listRecent(?User $principal, array $filters = [], int $limit = 50, int $offset = 0): array;

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
     * An Atom feed's entries: pages in $namespaces (each with everything
     * under it) that an anonymous caller could list — public only
     * (Search\Query::visibilityClause(null)) — and that carry no patient
     * data at all (no patient key, strong or weak). Newest update first.
     *
     * @param list<string> $namespaces
     *
     * @return list<array<string, mixed>>
     */
    public function listFeed(array $namespaces, int $limit = 50): array;

    /**
     * Full-text search, listing rules applied (Search\Query::visibilityClause()).
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term, ?User $principal): array;

    /**
     * All pages linking to $pid (backlinks), listing rules applied
     * (Search\Query::visibilityClause()).
     *
     * @return list<array<string, mixed>>
     */
    public function backlinks(string $pid, ?User $principal): array;

    /**
     * Whether $principal may fetch an attached file ("{sha256}.{ext}"): it
     * is attached to at least one page they can open by URL
     * (Search\Query::pageAccessClause()).
     */
    public function canSeeMedia(string $file, ?User $principal): bool;

    /**
     * All pages for one patient, ordered by study date desc
     * (Search\Query::visibilityClause()).
     *
     * @return list<array<string, mixed>>
     */
    public function findByPatientKey(string $patientKey, ?User $principal): array;
}
