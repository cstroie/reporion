<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Index;

use PDO;
use Reporion\Auth\User;
use Reporion\Search\Query;
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
            'SELECT pid, path, ns, title, rev, status, visibility, site, study_date, summary, updated
             FROM pages WHERE ns = :ns' . $clauseSql . ' ORDER BY path'
        );
        $stmt->execute(['ns' => $ns] + $clauseParams);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Same rows as listNamespace(), ordered most-recently-updated first —
     * the Workbench worklist sidebar's "what's active in this namespace
     * right now" framing (`templates/worklist.php`), as opposed to
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
            'SELECT pid, path, ns, title, rev, status, visibility, site, study_date, summary, updated
             FROM pages WHERE ns = :ns' . $clauseSql . ' ORDER BY updated DESC LIMIT :limit'
        );
        foreach (['ns' => $ns] + $clauseParams as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The Workbench status bar's two real numbers (`templates/status.php`)
     * — a total and a draft count, both visibility-filtered the same as
     * every other listing. Deliberately not the mockup's other four
     * status-bar items (HL7 order queue, embeddings count, backup
     * schedule, an "amended" count — that status value isn't even in the
     * `status` CHECK constraint): none of those have a real backend, and a
     * status bar stating a number that isn't true is worse than an inert
     * button that says nothing yet (see docs/BUILD_LOG.md).
     *
     * @return array{total: int, draft: int}
     */
    public function namespaceStats(string $ns, ?User $principal): array
    {
        [$clauseSql, $clauseParams] = Query::visibilityClause($principal);
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft
             FROM pages WHERE ns = :ns" . $clauseSql
        );
        $stmt->execute(['ns' => $ns] + $clauseParams);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'draft' => (int) ($row['draft'] ?? 0),
        ];
    }

    /**
     * The immediate sub-namespaces of $ns, each with a page count — the
     * "namespace index" access pattern (`GET /{ns}:`). A page directly in
     * $ns itself is not a sub-namespace and is excluded automatically: the
     * `LIKE` prefix requires a `:` right after $ns, which a page whose own
     * `ns` equals $ns exactly does not have.
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

        // mb_strlen, not strlen: SQLite's SUBSTR/INSTR count UTF-8 characters,
        // not bytes, and namespace segments are not restricted to ASCII
        // (assertValidPath() rejects "/" and empty segments, nothing else —
        // Support\Slug's ASCII fold is not applied to a path typed into
        // /new). A byte offset here would slice a multibyte segment like
        // "rapoarte:măgurele" mid-character.
        $prefixLen = mb_strlen($ns) + 2; // +1 for the ':', +1 for SQLite's 1-indexed SUBSTR
        $prefixLike = Query::likeEscape($ns) . ':%';

        $stmt = $this->pdo->prepare(
            "SELECT
                CASE
                    WHEN INSTR(SUBSTR(ns, :prefixLen), ':') > 0
                    THEN SUBSTR(SUBSTR(ns, :prefixLen), 1, INSTR(SUBSTR(ns, :prefixLen), ':') - 1)
                    ELSE SUBSTR(ns, :prefixLen)
                END AS subns,
                COUNT(*) AS cnt
             FROM pages
             WHERE ns LIKE :prefixLike ESCAPE '\\'" . $clauseSql . '
             GROUP BY subns
             ORDER BY subns'
        );
        $stmt->execute(['prefixLen' => $prefixLen, 'prefixLike' => $prefixLike] + $clauseParams);

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
            "SELECT p.pid, p.path, p.title, p.visibility,
                    snippet(fts, 2, '" . self::SNIPPET_OPEN . "', '" . self::SNIPPET_CLOSE . "', '…', 24) AS snippet
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

    public function findByPatientKey(string $patientKey, ?User $principal): array
    {
        [$clauseSql, $clauseParams] = Query::visibilityClause($principal, 'p.visibility', 'p.ns');

        $stmt = $this->pdo->prepare(
            "SELECT p.pid, p.path, p.title, p.rev, p.status, p.visibility, "
            . "p.site, p.study_date, p.accession, p.updated, p.updated_by "
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
        $this->replacePriorLinks($snapshot->pid, $priors);
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
     * Only "priors" is resolved here (out of the four link kinds the schema
     * allows) — that's the one field the frontmatter format documents as a
     * list of page paths. template/protocol linking is not derived yet.
     *
     * @param list<string> $priorPaths
     */
    private function replacePriorLinks(string $pid, array $priorPaths): void
    {
        $this->pdo->prepare("DELETE FROM links WHERE src = ? AND kind = 'prior'")->execute([$pid]);
        $resolve = $this->pdo->prepare('SELECT pid FROM pages WHERE path = ?');
        $insert = $this->pdo->prepare("INSERT INTO links (src, dst_path, dst_pid, kind) VALUES (?, ?, ?, 'prior')");

        foreach (array_unique($priorPaths) as $priorPath) {
            $resolve->execute([$priorPath]);
            $dstPid = $resolve->fetchColumn();
            $insert->execute([$pid, $priorPath, $dstPid !== false ? $dstPid : null]);
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
