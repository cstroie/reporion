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
     * findByPath() for several paths in one query — same access rule
     * (Search\Query::pageAccessClause()), keyed by path; a path the caller
     * cannot reach, or that does not exist, is simply absent.
     *
     * @param list<string> $paths
     *
     * @return array<string, array<string, mixed>>
     */
    public function findByPaths(array $paths, ?User $principal): array;

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
     * `$year` (a "YYYY" string), when given, narrows to pages whose
     * `study_date` falls in that year — the namespace index's year-card
     * filter (`listNamespaceYears()`).
     *
     * @return list<array<string, mixed>>
     */
    public function listNamespace(string $ns, ?User $principal, ?string $year = null): array;

    /**
     * The distinct years present in $ns's direct children's `study_date`,
     * each with a page count, most recent first — the namespace index's
     * year-filter cards (Search\Query::visibilityClause()). Pages with no
     * `study_date` are not counted under any year (they always show up
     * under "All" instead).
     *
     * @return list<array{year: string, count: int}>
     */
    public function listNamespaceYears(string $ns, ?User $principal): array;

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
     * `status`, `updated_by`, `not_by` (anyone else), `since` (ISO 8601,
     * against `updated`),
     * `study_from` / `study_to` (dates, against `study_date`). `by_hand`
     * ('1'): by the newest hand edit instead — `since`, `updated_by`/`not_by` and the
     * order use `edited`/`edited_by` (returned as `updated`/`updated_by`),
     * and pages nobody edited by hand are left out (Support\Revlog).
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
     * $sort is 'relevance' (default, FTS5 rank) or 'recent' (updated desc).
     * $ns, given, restricts to that namespace and everything nested under it
     * (a prefix match, same rule as a namespace grant, D36). $filters
     * narrows by facet value (phase 19: modality, region, site, device,
     * status, tag — one value each); $limit/$offset give one page of the
     * results (0: all), the exact accession match first.
     *
     * @param array<string, string> $filters
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term, ?User $principal, string $sort = 'relevance', string $ns = '', array $filters = [], int $limit = 0, int $offset = 0): array;

    /**
     * How many results search() has in all for these arguments (phase 19b)
     *
     * @param array<string, string> $filters
     */
    public function searchCount(string $term, ?User $principal, string $ns = '', array $filters = []): int;

    /**
     * Per facet, the values search()'s results have and how many each
     * (phase 19a), inside the same visibility (invariant 6)
     *
     * @param array<string, string> $filters
     *
     * @return array<string, list<array{value: string, n: int}>>
     */
    public function searchFacets(string $term, ?User $principal, string $ns = '', array $filters = [], int $perFacet = 12): array;

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
     * Every tag with the number of pages carrying it, most used first —
     * Admin → Tags. Unfiltered: only an owner reaches that screen.
     *
     * @return list<array{tag: string, n: int}>
     */
    public function tagCounts(): array;

    /**
     * Paths of the pages carrying $tag, unfiltered (owner tools only).
     *
     * @return list<string>
     */
    public function pathsWithTag(string $tag): array;

    /**
     * All pages for one patient, ordered by study date desc
     * (Search\Query::visibilityClause()), with `exam_title` from the
     * frontmatter (D30 as amended: `title` is the patient's name).
     * $patientKey is matched against *either* the strong or the weak key
     * column (D11) — a caller with only a weak key (no CNP on this page)
     * still finds every page sharing it, itself included, never zero rows.
     *
     * @return list<array<string, mixed>>
     */
    public function findByPatientKey(string $patientKey, ?User $principal): array;

    /**
     * Other patients whose title (name, D30) matches $name but whose keys
     * are not among $ownKeys — a candidate the exact patient_key match
     * cannot find (TODO 13). Read only, never merges anything.
     *
     * @param list<string> $ownKeys
     *
     * @return list<array<string, mixed>>
     */
    public function findPossiblePatientMatches(string $name, array $ownKeys, string $excludePid, ?User $principal): array;

    /**
     * Pages for this patient (strong or weak key, D11) with a study on
     * $date (Y-m-d), through the listing predicate — the new-report form's
     * "a report for this patient and date exists" check.
     *
     * @return list<array{path: string, title: string, study_date: ?string, modality: ?string}>
     */
    public function findSameDay(?string $strongKey, ?string $weakKey, string $date, ?User $principal): array;

    /**
     * Which of $refs (frontmatter `order_ref`, `{system}:{Type}/{id}` — the
     * order a report answers in another system) already have a page the
     * caller can see, through the listing predicate: ref → path.
     *
     * @param list<string> $refs
     *
     * @return array<string, string>
     */
    public function findByOrderRefs(array $refs, ?User $principal): array;

    /**
     * Which of $uids (frontmatter `study_uid` — or an `exams[].study_uid` —
     * the DICOM Study Instance UID of the exam) already have a page the caller can see, through the
     * listing predicate: uid → path.
     *
     * @param list<string> $uids
     *
     * @return array<string, string>
     */
    public function findByStudyUids(array $uids, ?User $principal): array;

    /**
     * Every accession starting with $prefix, unfiltered — only the number
     * allocator reads it (Service\Accessions).
     *
     * @return list<string>
     */
    public function accessionsStartingWith(string $prefix): array;

    /**
     * The reports (`reports:` pages, the leaf checked by the caller with
     * Support\ReportPath) the caller can list, for /stats (phase 24): one
     * row per page whose exam date or first signature is on or after
     * $filters['since'] (a date), or that is still a draft. Optional
     * filters: `site`, `modality`. Never the patient fields.
     *
     * @param array{since: string, site?: string, modality?: string} $filters
     *
     * @return list<array{path: string, title: string, status: string, site: ?string, study_date: ?string, signed_at: ?string, signed_by: ?string, updated: string, modalities: list<string>}>
     */
    public function statsRows(?User $principal, array $filters): array;
}
