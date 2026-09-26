<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Index;

use PDO;
use Reporion\Auth\User;
use Reporion\Search\Query;
use Reporion\Support\InternalLink;
use Reporion\Support\PatientKey;
use Throwable;

/**
 * The derived, disposable cache (CLAUDE.md invariant 1). Every write here
 * happens inside one transaction per page (docs/architecture-storage-index.md
 * §5: "Index in one SQLite transaction"), and rebuild() from a stream of
 * PageSnapshot must reproduce exactly what incremental index() calls would
 * have produced — that equivalence is the safety net for the whole
 * cache-is-disposable claim (build order step 2).
 *
 * migrations/*.sql is the only place the schema is defined; this class only
 * ever reads/writes through it, never redeclares a column shape itself.
 */
final class Sqlite implements IndexInterface
{
    private const SNIPPET_OPEN = "\x02";
    private const SNIPPET_CLOSE = "\x03";

    private readonly PDO $pdo;

    public function __construct(string $databasePath, string $migrationsDir)
    {
        $this->pdo = new PDO('sqlite:' . $databasePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        $this->applyMigrations($migrationsDir);
    }

    public function index(PageSnapshot $snapshot): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->write($snapshot);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function remove(string $pid): void
    {
        $this->pdo->beginTransaction();
        try {
            $rowid = $this->rowidFor($pid);
            if ($rowid !== null) {
                $this->pdo->prepare('DELETE FROM fts WHERE rowid = ?')->execute([$rowid]);
            }
            $this->pdo->prepare('DELETE FROM pages WHERE pid = ?')->execute([$pid]);
            // Links to it are broken now; they resolve again if it comes back
            $this->pdo->prepare('UPDATE links SET dst_pid = NULL WHERE dst_pid = ?')->execute([$pid]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Ground truth (Table 1, docs/architecture-storage-index.md §6):
     * everything derived from disk is truncated and rebuilt from $snapshots.
     * Tables not derived per-page (the tag/synonym dictionary, redirects,
     * the import review queue) are untouched.
     *
     * @param iterable<PageSnapshot> $snapshots
     */
    public function rebuild(iterable $snapshots): void
    {
        // One transaction for the whole rebuild, not one per page: the
        // recovery story is literally "rm index.sqlite && index:rebuild",
        // so a crash partway through must never leave a half-empty index —
        // there is no journal to self-heal this the way index() has.
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM pages');
            $this->pdo->exec('DELETE FROM fts');
            foreach ($snapshots as $snapshot) {
                $this->write($snapshot);
            }
            // A link to a page written later in the loop — what index()
            // resolves when that page is written
            $this->pdo->exec('UPDATE links SET dst_pid = (SELECT pid FROM pages WHERE pages.path = links.dst_path) WHERE dst_pid IS NULL');
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * A cheap stat-only drift pass (Table 1: "index:verify", nightly cron).
     * $diskFacts is whatever the caller already had to stat every page for
     * (no filesystem access happens here — Index stays independent of how
     * pages are enumerated on disk).
     *
     * @param iterable<array{pid: string, bytes: int, mtime: int, bodySha: string}> $diskFacts
     *
     * @return array{orphans: list<string>, missing: list<string>, drifted: list<string>}
     */
    /**
     * Page counts per status × visibility — Admin → Index & storage. Every
     * page, deliberately unfiltered: only an owner reaches that screen, and
     * an owner can read everything anyway.
     *
     * @return list<array{status: string, visibility: string, n: int}>
     */
    public function countsByStatusAndVisibility(): array
    {
        $rows = $this->pdo->query('SELECT status, visibility, COUNT(*) AS n FROM pages GROUP BY status, visibility')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): array => ['status' => (string) $row['status'], 'visibility' => (string) $row['visibility'], 'n' => (int) $row['n']], $rows);
    }

    public function verify(iterable $diskFacts): array
    {
        $onDisk = [];
        foreach ($diskFacts as $fact) {
            $onDisk[$fact['pid']] = $fact;
        }

        $indexed = [];
        foreach ($this->pdo->query('SELECT pid, bytes, mtime, body_sha FROM pages') as $row) {
            $indexed[(string) $row['pid']] = $row;
        }

        $orphans = array_values(array_diff(array_keys($indexed), array_keys($onDisk)));
        $missing = array_values(array_diff(array_keys($onDisk), array_keys($indexed)));

        $drifted = [];
        foreach ($onDisk as $pid => $fact) {
            if (!isset($indexed[$pid])) {
                continue;
            }
            $row = $indexed[$pid];
            if ((int) $row['bytes'] !== $fact['bytes']
                || (int) $row['mtime'] !== $fact['mtime']
                || (string) $row['body_sha'] !== $fact['bodySha']
            ) {
                $drifted[] = $pid;
            }
        }

        return ['orphans' => $orphans, 'missing' => $missing, 'drifted' => $drifted];
    }

    /**
     * Direct lookup of one known path — the "API"/page-view access pattern
     * (Table 2). Returns null both when the page truly does not exist and
     * when $principal is not entitled to it: CLAUDE.md invariant 9 (404,
     * never 403) requires that distinction to be invisible from here.
     *
     * @return array<string, mixed>|null
     */
    public function findByPath(string $path, ?User $principal): ?array
    {
        [$clauseSql, $clauseParams] = Query::pageAccessClause($principal);
        $stmt = $this->pdo->prepare('SELECT * FROM pages WHERE path = :path' . $clauseSql);
        $stmt->execute(['path' => $path] + $clauseParams);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function findByPid(string $pid, ?User $principal): ?array
    {
        [$clauseSql, $clauseParams] = Query::pageAccessClause($principal);
        $stmt = $this->pdo->prepare('SELECT * FROM pages WHERE pid = :pid' . $clauseSql);
        $stmt->execute(['pid' => $pid] + $clauseParams);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * One namespace's direct children — the "tree" access pattern. A
     * listing, so it follows visibilityClause(): a caller with no grant
     * covering $ns never sees a private *or* an unlisted page here, even
     * though the latter remains directly reachable via findByPath().
     *
     * Explicit column list, deliberately excluding meta_json: it carries the
     * full frontmatter, including the patient block, and a listing row is
     * exactly the kind of value that ends up handed straight to a template
     * (CLAUDE.md invariant 8 — the patient identity never leaves the box).
     * findByPath() is the single-page read and legitimately needs everything.
     *
     * @return list<array<string, mixed>>
     */
    public function listNamespace(string $ns, ?User $principal): array
    {
        [$clauseSql, $clauseParams] = Query::visibilityClause($principal);
        $stmt = $this->pdo->prepare(
            'SELECT pid, path, ns, title, rev, status, visibility, site, study_date, summary, updated, updated_by,
                    (SELECT GROUP_CONCAT(region, \', \') FROM page_regions WHERE pid = pages.pid) AS region
             FROM pages WHERE ns = :ns' . $clauseSql . ' ORDER BY path'
        );
        $stmt->execute(['ns' => $ns] + $clauseParams);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Same rows as listNamespace(), ordered most-recently-updated first —
     * the namespace drawer's "what's active in this namespace right now"
     * framing (`templates/drawer.php`), as opposed to
     * listNamespace()'s alphabetical "browse everything here" framing
     * (`GET /{ns}:`). No filters yet (modality/date-range/"mine" chips in
     * the mockup) — see docs/BUILD_LOG.md; this is the plain listing only.
     *
     * @return list<array<string, mixed>>
     */
    public function listWorklist(string $ns, ?User $principal, int $limit = 20): array
    {
        [$clauseSql, $clauseParams] = Query::visibilityClause($principal);
        $stmt = $this->pdo->prepare(
            'SELECT pid, path, ns, title, rev, status, visibility, site, study_date, summary, updated, updated_by
             FROM pages WHERE ns = :ns' . $clauseSql . ' ORDER BY updated DESC LIMIT :limit'
        );
        foreach (['ns' => $ns] + $clauseParams as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listRecent(?User $principal, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$clauseSql, $clauseParams] = Query::visibilityClause($principal, 'p.visibility', 'p.ns');
        $where = '';
        $params = [];
        if (isset($filters['modality']) && $filters['modality'] !== '') {
            $where .= ' AND EXISTS (SELECT 1 FROM page_modalities m WHERE m.pid = p.pid AND m.modality = :modality)';
            $params['modality'] = $filters['modality'];
        }
        if (isset($filters['since']) && $filters['since'] !== '') {
            $where .= ' AND p.updated >= :since';
            $params['since'] = $filters['since'];
        }
        if (isset($filters['updated_by']) && $filters['updated_by'] !== '') {
            $where .= ' AND p.updated_by = :updated_by';
            $params['updated_by'] = $filters['updated_by'];
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $where .= ' AND p.status = :status';
            $params['status'] = $filters['status'];
        }
        if (isset($filters['region']) && $filters['region'] !== '') {
            $where .= ' AND EXISTS (SELECT 1 FROM page_regions r WHERE r.pid = p.pid AND r.region = :region)';
            $params['region'] = $filters['region'];
        }
        if (isset($filters['site']) && $filters['site'] !== '') {
            $where .= ' AND p.site = :site';
            $params['site'] = $filters['site'];
        }
        if (isset($filters['ns']) && $filters['ns'] !== '') {
            // The namespace itself or anything under it; LIKE wildcards escaped
            $where .= " AND (p.ns = :ns OR p.ns LIKE :ns_prefix ESCAPE '\\')";
            $params['ns'] = $filters['ns'];
            $params['ns_prefix'] = addcslashes($filters['ns'], '%_\\') . ':%';
        }
        if (isset($filters['study_from']) && $filters['study_from'] !== '') {
            $where .= ' AND p.study_date >= :study_from';
            $params['study_from'] = $filters['study_from'];
        }
        if (isset($filters['study_to']) && $filters['study_to'] !== '') {
            // Inclusive of the whole day
            $where .= ' AND p.study_date < :study_to';
            $params['study_to'] = $filters['study_to'] . "\u{10FFFF}";
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.pid, p.path, p.ns, p.title, p.rev, p.status, p.visibility, p.site, p.study_date, p.summary, p.updated, p.updated_by,
                    (SELECT GROUP_CONCAT(modality, \', \') FROM page_modalities WHERE pid = p.pid) AS modality
             FROM pages p WHERE 1 = 1' . $where . $clauseSql . ' ORDER BY p.updated DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params + $clauseParams as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The immediate sub-namespaces of $ns, each with a page count — the
     * "namespace index" access pattern (`GET /{ns}:`). A page directly in
     * $ns itself is not a sub-namespace and is excluded automatically: the
     * `LIKE` prefix requires a `:` right after $ns, which a page whose own
     * `ns` equals $ns exactly does not have. $ns === '' is the root
     * namespace (`GET /:`) — its immediate children are every top-level
     * namespace in the tree, handled as its own branch below since there
     * is no leading ':' to skip and no exact-`ns`-match page to exclude
     * automatically (a page with ns === '' is excluded explicitly instead).
     *
     * The visibility clause is applied *inside* the aggregate, before
     * `GROUP BY` — not after counting — or a non-zero count for a
     * sub-namespace would leak the existence of pages a caller cannot
     * otherwise see at all (the exact "dangerous failure mode"
     * docs/architecture-storage-index.md calls out for search).
     *
     * @return list<array{name: string, count: int}>
     */
    public function listSubnamespaces(string $ns, ?User $principal): array
    {
        [$clauseSql, $clauseParams] = Query::visibilityClause($principal, 'visibility', 'ns');

        if ($ns === '') {
            // Root: every non-empty ns is already the full "prefix" to
            // split on — there is no leading ':' to skip, and a page
            // whose own ns is '' (already top-level) is not a
            // sub-namespace of anything, so it's excluded explicitly
            // rather than by a "ns LIKE ':%'" match that would never hit.
            $prefixLen = 1;
            $whereSql = "ns != ''";
            $whereParams = [];
        } else {
            // mb_strlen, not strlen: SQLite's SUBSTR/INSTR count UTF-8
            // characters, not bytes, and namespace segments are not
            // restricted to ASCII (assertValidPath() rejects "/" and empty
            // segments, nothing else — Support\Slug's ASCII fold is not
            // applied to a path typed into /new). A byte offset here would
            // slice a multibyte segment like "rapoarte:măgurele" mid-character.
            $prefixLen = mb_strlen($ns) + 2; // +1 for the ':', +1 for SQLite's 1-indexed SUBSTR
            $whereSql = "ns LIKE :prefixLike ESCAPE '\\'";
            $whereParams = ['prefixLike' => Query::likeEscape($ns) . ':%'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                CASE
                    WHEN INSTR(SUBSTR(ns, :prefixLen), ':') > 0
                    THEN SUBSTR(SUBSTR(ns, :prefixLen), 1, INSTR(SUBSTR(ns, :prefixLen), ':') - 1)
                    ELSE SUBSTR(ns, :prefixLen)
                END AS subns,
                COUNT(*) AS cnt
             FROM pages
             WHERE {$whereSql}" . $clauseSql . '
             GROUP BY subns
             ORDER BY subns'
        );
        $stmt->execute(['prefixLen' => $prefixLen] + $whereParams + $clauseParams);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => ['name' => (string) $row['subns'], 'count' => (int) $row['cnt']],
            $rows
        );
    }

    /**
     * Every page, listing rules applied — the "sitemap" access pattern.
     *
     * @return list<array<string, mixed>>
     */
    public function listFeed(array $namespaces, int $limit = 50): array
    {
        if ($namespaces === []) {
            return [];
        }
        [$clauseSql, $clauseParams] = Query::visibilityClause(null, 'p.visibility', 'p.ns');
        $nsSql = [];
        $params = [];
        foreach (array_values($namespaces) as $i => $ns) {
            $nsSql[] = "(p.ns = :ns{$i} OR p.ns LIKE :nsp{$i} ESCAPE '\\')";
            $params['ns' . $i] = $ns;
            $params['nsp' . $i] = addcslashes($ns, '%_\\') . ':%';
        }
        $stmt = $this->pdo->prepare(
            'SELECT p.pid, p.path, p.title, p.summary, p.updated, p.updated_by
             FROM pages p
             WHERE (' . implode(' OR ', $nsSql) . ')
               AND p.patient_key IS NULL AND p.patient_key_weak IS NULL' . $clauseSql . '
             ORDER BY p.updated DESC LIMIT :limit'
        );
        foreach ($params + $clauseParams as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listSitemap(?User $principal): array
    {
        [$clauseSql, $clauseParams] = Query::visibilityClause($principal);
        $stmt = $this->pdo->prepare('SELECT path, updated FROM pages WHERE 1 = 1' . $clauseSql . ' ORDER BY path');
        $stmt->execute($clauseParams);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Full-text search — the "search" access pattern. Same listing rules:
     * a caller with no covering grant never sees a private or unlisted
     * page's title or snippet in their results (the "dangerous failure
     * mode" the architecture doc calls out by name).
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term, ?User $principal): array
    {
        [$clauseSql, $clauseParams] = Query::visibilityClause($principal, 'p.visibility', 'p.ns');

        // Sentinel markers, not literal HTML tags: snippet() extracts raw
        // body text (unescaped markdown source, not rendered HTML), so a
        // report whose text happens to contain "<" or "&" would otherwise
        // reach the template unescaped around genuinely trusted <mark>
        // tags — an XSS hole. The template escapes the whole snippet, then
        // substitutes these markers for <mark>/</mark>.
        $stmt = $this->pdo->prepare(
            "SELECT p.pid, p.path, p.title, p.visibility, p.status, p.site, p.study_date, p.device,
                    (SELECT GROUP_CONCAT(modality, ', ') FROM page_modalities WHERE pid = p.pid) AS modality,
                    snippet(fts, 2, '" . self::SNIPPET_OPEN . "', '" . self::SNIPPET_CLOSE . "', '…', 24) AS snippet,
                    rank AS score
             FROM fts
             JOIN pages p ON p.rowid = fts.rowid
             WHERE fts MATCH :term" . $clauseSql . '
             ORDER BY rank'
        );
        // Quoted as one FTS5 phrase rather than passed raw: an unescaped
        // term is parsed as FTS5 query syntax (AND/OR/NOT, column filters,
        // unbalanced quotes) and a caller-supplied string is exactly where
        // that becomes an uncaught PDOException, not a search result.
        // Structured query syntax (mode=fts|vector|hybrid, filters) is
        // later work (docs/architecture-api.md "Search").
        $stmt->execute(['term' => self::ftsPhrase($term)] + $clauseParams);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function backlinks(string $pid, ?User $principal): array
    {
        [$clauseSql, $clauseParams] = Query::visibilityClause($principal);
        $stmt = $this->pdo->prepare(
            'SELECT p.path, p.title FROM links l JOIN pages p ON p.pid = l.src '
            . 'WHERE l.dst_pid = :pid AND l.kind = :kind' . $clauseSql . ' ORDER BY p.path'
        );
        $stmt->execute(['pid' => $pid, 'kind' => 'link'] + $clauseParams);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function tagCounts(): array
    {
        $rows = $this->pdo->query('SELECT tag, COUNT(*) AS n FROM page_tags GROUP BY tag ORDER BY n DESC, tag')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): array => ['tag' => (string) $row['tag'], 'n' => (int) $row['n']], $rows);
    }

    public function pathsWithTag(string $tag): array
    {
        $stmt = $this->pdo->prepare('SELECT p.path FROM page_tags t JOIN pages p ON p.pid = t.pid WHERE t.tag = ? ORDER BY p.path');
        $stmt->execute([$tag]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function canSeeMedia(string $file, ?User $principal): bool
    {
        [$clauseSql, $clauseParams] = Query::pageAccessClause($principal, 'p.visibility', 'p.ns');
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM links l JOIN pages p ON p.pid = l.src WHERE l.kind = 'media' AND l.dst_path = :file" . $clauseSql . ' LIMIT 1'
        );
        $stmt->execute(['file' => 'media:' . $file] + $clauseParams);

        return $stmt->fetchColumn() !== false;
    }

    public function findSameDay(?string $strongKey, ?string $weakKey, string $date, ?User $principal): array
    {
        if ($strongKey === null && $weakKey === null) {
            return [];
        }
        [$clauseSql, $clauseParams] = Query::visibilityClause($principal, 'p.visibility', 'p.ns');
        $stmt = $this->pdo->prepare(
            'SELECT p.path, p.title, p.study_date, '
            . "(SELECT GROUP_CONCAT(modality, ', ') FROM page_modalities WHERE pid = p.pid) AS modality "
            . 'FROM pages p WHERE (p.patient_key = :strong OR p.patient_key_weak = :weak) '
            . 'AND substr(p.study_date, 1, 10) = :date' . $clauseSql . ' ORDER BY p.path'
        );
        $stmt->execute(['strong' => $strongKey ?? '', 'weak' => $weakKey ?? '', 'date' => $date] + $clauseParams);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function accessionsStartingWith(string $prefix): array
    {
        $stmt = $this->pdo->prepare("SELECT accession FROM pages WHERE accession LIKE :prefix ESCAPE '\\'");
        $stmt->execute(['prefix' => addcslashes($prefix, '%_\\') . '%']);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function findByPatientKey(string $patientKey, ?User $principal): array
    {
        [$clauseSql, $clauseParams] = Query::visibilityClause($principal, 'p.visibility', 'p.ns');

        $stmt = $this->pdo->prepare(
            "SELECT p.pid, p.path, p.title, p.rev, p.status, p.visibility, "
            . "p.site, p.study_date, p.accession, p.device, p.summary, p.updated, p.updated_by, "
            . "(SELECT GROUP_CONCAT(modality, ', ') FROM page_modalities WHERE pid = p.pid) AS modality, "
            . "(SELECT GROUP_CONCAT(region, ', ') FROM page_regions WHERE pid = p.pid) AS region "
            . "FROM pages p "
            . "WHERE p.patient_key = :pk " . $clauseSql . " "
            . "ORDER BY p.study_date DESC"
        );
        $stmt->execute(['pk' => $patientKey] + $clauseParams);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Quoting the WHOLE term as one phrase (an earlier version of this)
     * would only match the tokens adjacent, in that exact order — breaking
     * ordinary multi-word queries and making D28's documented prefix
     * matching on the last token impossible to add. Quoting per token and
     * joining with FTS5's implicit AND keeps both: injection-safe (no
     * token can smuggle FTS5 operator syntax) and D28-compatible (a future
     * prefix_last_token implementation appends `*` inside the last
     * token's quotes, right here).
     */
    private static function ftsPhrase(string $term): string
    {
        $tokens = preg_split('/\s+/', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(
            static fn (string $token): string => '"' . str_replace('"', '""', $token) . '"',
            $tokens
        ));
    }

    /**
     * Turns a raw search() 'snippet' value into HTML safe to echo directly:
     * escape everything, then turn the sentinel markers into real <mark>
     * tags. The only correct place for this is here, next to where the
     * markers are chosen — nothing outside Index\Sqlite should know what
     * they are.
     */
    public static function highlightSnippet(string $rawSnippet): string
    {
        $escaped = htmlspecialchars($rawSnippet, ENT_QUOTES);

        return str_replace([self::SNIPPET_OPEN, self::SNIPPET_CLOSE], ['<mark>', '</mark>'], $escaped);
    }

    /**
     * The same raw search() 'snippet' value, for a non-HTML consumer (the
     * palette's JSON response): the sentinel markers are stripped outright
     * rather than turned into markup, since a JSON client decides its own
     * rendering and shouldn't receive two literal control bytes (\x02/\x03)
     * it has no way to interpret.
     */
    public static function plainSnippet(string $rawSnippet): string
    {
        return str_replace([self::SNIPPET_OPEN, self::SNIPPET_CLOSE], '', $rawSnippet);
    }

    private function write(PageSnapshot $snapshot): void
    {
        $fm = $snapshot->frontmatter;
        $title = (string) ($fm['title'] ?? '');
        $modalities = self::asList($fm['modality'] ?? null);
        $regions = self::asList($fm['region'] ?? null);
        $tags = self::asList($fm['tags'] ?? null);
        $priors = self::asList($fm['priors'] ?? null);

        $patient = \is_array($fm['patient'] ?? null) ? $fm['patient'] : [];
        $patientKey = PatientKey::strong(isset($patient['cnp']) ? (string) $patient['cnp'] : null);
        $patientKeyWeak = isset($patient['name']) && $patient['name'] !== ''
            ? PatientKey::weak(
                (string) $patient['name'],
                isset($patient['born']) ? (int) $patient['born'] : null,
                isset($patient['sex']) ? (string) $patient['sex'] : null,
            )
            : null;

        $this->pdo->prepare(
            'INSERT INTO pages (
                pid, path, ns, title, rev, status, visibility,
                site, device, accession, study_date, protocol, summary,
                patient_key, patient_key_weak,
                updated, updated_by, bytes, mtime, body_sha, meta_json
            ) VALUES (
                :pid, :path, :ns, :title, :rev, :status, :visibility,
                :site, :device, :accession, :study_date, :protocol, :summary,
                :patient_key, :patient_key_weak,
                :updated, :updated_by, :bytes, :mtime, :body_sha, :meta_json
            )
            ON CONFLICT(pid) DO UPDATE SET
                path = excluded.path, ns = excluded.ns, title = excluded.title, rev = excluded.rev,
                status = excluded.status, visibility = excluded.visibility,
                site = excluded.site, device = excluded.device, accession = excluded.accession,
                study_date = excluded.study_date, protocol = excluded.protocol, summary = excluded.summary,
                patient_key = excluded.patient_key, patient_key_weak = excluded.patient_key_weak,
                updated = excluded.updated, updated_by = excluded.updated_by, bytes = excluded.bytes,
                mtime = excluded.mtime, body_sha = excluded.body_sha, meta_json = excluded.meta_json'
        )->execute([
            'pid' => $snapshot->pid,
            'path' => $snapshot->path,
            'ns' => $snapshot->ns,
            'title' => $title,
            'rev' => $snapshot->rev,
            'status' => $snapshot->status,
            'visibility' => $snapshot->visibility,
            'site' => $fm['site'] ?? null,
            'device' => $fm['device'] ?? null,
            'accession' => $fm['accession'] ?? null,
            'study_date' => $fm['study_date'] ?? null,
            'protocol' => $fm['protocol'] ?? null,
            'summary' => $fm['summary'] ?? null,
            'patient_key' => $patientKey,
            'patient_key_weak' => $patientKeyWeak,
            'updated' => $snapshot->updated,
            'updated_by' => $snapshot->updatedBy,
            'bytes' => $snapshot->bytes,
            'mtime' => $snapshot->mtime,
            'body_sha' => $snapshot->bodySha,
            'meta_json' => json_encode($fm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $rowid = (int) $this->rowidFor($snapshot->pid);

        $this->replaceChildRows('page_modalities', 'modality', $snapshot->pid, $modalities);
        $this->replaceChildRows('page_regions', 'region', $snapshot->pid, $regions);
        $this->replaceChildRows('page_tags', 'tag', $snapshot->pid, $tags);
        $this->replaceLinks($snapshot->pid, 'prior', $priors);
        $this->replaceLinks($snapshot->pid, 'link', InternalLink::extract($snapshot->body));
        $this->replaceLinks($snapshot->pid, 'media', array_map(static fn (string $file): string => 'media:' . $file, $snapshot->media));
        // Links written before this page existed (or before it moved here) now resolve
        $this->pdo->prepare('UPDATE links SET dst_pid = ? WHERE dst_path = ? AND dst_pid IS NULL')
            ->execute([$snapshot->pid, $snapshot->path]);
        $this->upsertRevision($snapshot);

        $this->pdo->prepare('DELETE FROM fts WHERE rowid = ?')->execute([$rowid]);
        $this->pdo->prepare('INSERT INTO fts (rowid, title, summary, body, tags, pid) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$rowid, $title, (string) ($fm['summary'] ?? ''), $snapshot->body, implode(' ', $tags), $snapshot->pid]);
    }

    /**
     * @param list<string> $values
     */
    private function replaceChildRows(string $table, string $column, string $pid, array $values): void
    {
        $this->pdo->prepare("DELETE FROM {$table} WHERE pid = ?")->execute([$pid]);
        $insert = $this->pdo->prepare("INSERT INTO {$table} (pid, {$column}) VALUES (?, ?)");
        foreach (array_unique($values) as $value) {
            $insert->execute([$pid, $value]);
        }
    }

    /**
     * Links of one $kind from $pid: `prior` from the frontmatter list, and
     * `link` from the body (Support\InternalLink). template/protocol
     * linking is not derived yet. A target that does not exist yet is kept
     * with dst_pid NULL and resolved when that page is written.
     *
     * @param list<string> $paths
     */
    private function replaceLinks(string $pid, string $kind, array $paths): void
    {
        $this->pdo->prepare('DELETE FROM links WHERE src = ? AND kind = ?')->execute([$pid, $kind]);
        $resolve = $this->pdo->prepare('SELECT pid FROM pages WHERE path = ?');
        $insert = $this->pdo->prepare('INSERT INTO links (src, dst_path, dst_pid, kind) VALUES (?, ?, ?, ?)');

        foreach (array_unique($paths) as $path) {
            $resolve->execute([$path]);
            $dstPid = $resolve->fetchColumn();
            $insert->execute([$pid, $path, $dstPid !== false ? $dstPid : null, $kind]);
        }
    }

    private function upsertRevision(PageSnapshot $snapshot): void
    {
        $this->pdo->prepare(
            'INSERT INTO revisions (pid, n, ts, by, note, bytes, kind, sha256, signed)
             VALUES (:pid, :n, :ts, :by, :note, :bytes, :kind, :sha256, 0)
             ON CONFLICT(pid, n) DO UPDATE SET
                ts = excluded.ts, by = excluded.by, note = excluded.note,
                bytes = excluded.bytes, kind = excluded.kind, sha256 = excluded.sha256'
        )->execute([
            'pid' => $snapshot->pid,
            'n' => $snapshot->rev,
            'ts' => $snapshot->updated,
            'by' => $snapshot->updatedBy,
            'note' => $snapshot->note,
            'bytes' => $snapshot->bytes,
            'kind' => $snapshot->kind,
            'sha256' => $snapshot->bodySha,
        ]);
    }

    private function rowidFor(string $pid): ?int
    {
        $stmt = $this->pdo->prepare('SELECT rowid FROM pages WHERE pid = ?');
        $stmt->execute([$pid]);
        $rowid = $stmt->fetchColumn();

        return $rowid !== false ? (int) $rowid : null;
    }

    /**
     * @return list<string>
     */
    private static function asList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (\is_array($value)) {
            $flat = [];
            array_walk_recursive($value, static function (mixed $v) use (&$flat): void {
                $flat[] = (string) $v;
            });

            return $flat;
        }

        return [(string) $value];
    }

    private function applyMigrations(string $dir): void
    {
        $current = $this->hasTable('schema_meta') ? $this->currentSchemaVersion() : 0;

        $files = glob($dir . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            if (!preg_match('/^(\d+)_/', basename($file), $m)) {
                continue;
            }
            $version = (int) $m[1];
            if ($version <= $current) {
                continue;
            }

            $this->pdo->exec((string) file_get_contents($file));
            $this->pdo->prepare(
                "INSERT INTO schema_meta (key, value) VALUES ('schema_version', :v)
                 ON CONFLICT(key) DO UPDATE SET value = :v"
            )->execute(['v' => (string) $version]);
        }
    }

    private function currentSchemaVersion(): int
    {
        $stmt = $this->pdo->query("SELECT value FROM schema_meta WHERE key = 'schema_version'");
        $value = $stmt !== false ? $stmt->fetchColumn() : false;

        return $value !== false ? (int) $value : 0;
    }

    private function hasTable(string $name): bool
    {
        $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$name]);

        return $stmt->fetchColumn() !== false;
    }
}
