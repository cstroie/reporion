# Reporion 2.0: storage and database

*draft 1 · 2026-10-20 · D54 · decided, not built. Until the 2.0 driver ships, CLAUDE.md's
invariants and `docs/architecture-storage-index.md` describe the running code; this document
describes the target. It supersedes `docs/study-meta-json-to-event-log.md`.*

## 1. What changes, in one paragraph

A page is a **file**, not a directory. `data/pages/` holds only the wiki's current state, one
`.md` per page, with namespaces as directories (as in DokuWiki). Every revision of a page sits in
one zip in `data/attic/`, mirroring the pages tree; the zip also carries the page's revision log
and signatures, so each zip verifies on its own. Everything else a page has (its pid↔path
binding, moves, redirects, media manifest, comments, deliveries, share token, autosave drafts,
trash state) and the instance's counters live in a **permanent** SQLite database,
`data/reporion.sqlite`. Search facets, links, tags, exams and FTS stay in a disposable
`data/index.sqlite`; embeddings move to a disposable `data/vectors.sqlite`, the one that may grow
large and is the one that costs model calls to rebuild. Tree walks become queries.

## 2. Owner's decisions (2026-10-20)

| Question | Answer |
|---|---|
| Revisions | one zip per page, `attic/{ns…}/{slug}.zip` |
| Metadata | out of the pages tree; then (same day) into the database rather than sidecar files |
| `pid` in the page file's frontmatter | yes (§5) |
| Converter | PHP, a one-off script, not a maintenance task (§11) |
| Databases | three files: permanent state, index (facets + FTS), vectors |
| Revlog and signatures | both in the zip and in the database |
| Accounts, groups, settings, tags dictionary | stay files (D36 unchanged) |
| Comments and autosave drafts | into the database |

## 3. On-disk layout

```
data/
├─ pages/                                   current state only: what `cat` shows is the wiki
│  └─ reports/mri/mioveni/
│     ├─ 260922-ionescu-maria.md            frontmatter (with pid) + body; == newest revision (D2)
│     └─ …
├─ attic/                                   history, mirroring pages/
│  └─ reports/mri/mioveni/
│     └─ 260922-ionescu-maria.zip           0001.md … 0007.md + revlog.json
├─ reporion.sqlite (+ -wal)                 PERMANENT: operational state (§6). Backed up like data/
├─ index.sqlite (+ -wal)                    derived: deletable, rebuilt from pages/ + reporion.sqlite
├─ vectors.sqlite (+ -wal)                  derived: deletable, rebuilt by index:vectors (asks the model)
├─ snapshot/reporion.sqlite                 consistent copy for backup (§9); never opened by the app
├─ trash/{pid}/                             page.md + attic.zip of a deleted page
├─ media/{year}/{sha256}.{ext}              unchanged (D27)
├─ users/, groups/                          unchanged (D36, D47)
├─ settings.yaml, site/, tags.yaml          unchanged
├─ journal/, audit/                         unchanged: append-only ndjson
├─ tmp/, backups/, maintenance/             unchanged
```

`counters.json` is gone (table `counter`). No redirect stubs, no `meta.json`, `media.json`,
`comments.json` or `autosave.*.json` remain on disk.

**Names.** A slug is `[a-z0-9-]`, so a page `foo.md` and a namespace `foo/` sit side by side
and nothing is a reserved segment any more (`rev`, `meta.json`, `comments.json`, `autosave.*`,
`redirect` all stop being reserved). The `_` prefix and the reserved roots of
architecture-storage-index §2 stay. The "move a page onto a namespace of the same name, entry by
entry" machinery goes away: the two never share a directory.

## 4. The attic zip

```
260922-ionescu-maria.zip
├─ 0001.md          revision 1, the exact bytes written (frontmatter + body), deflated
├─ …
├─ 0007.md          == pages/…/260922-ionescu-maria.md
└─ revlog.json      {v: 2, pid, status, archived, revlog: [...], signatures: [...]}
```

`revlog.json` has the fields of today's `meta.json` that are history: `revlog` (with `chain`,
`sha256` = sha256 of the revision's bytes), `signatures` (with `field_order`), `status` and the
`archived` marker. Nothing operational (moves, share token, deliveries) goes in it.

**Write-once, by verification.** A zip cannot be appended to atomically, so every save rewrites
the container, but no entry is ever changed:

1. read `x.zip` and build the new container in memory (`Support\Zip`, a plain-PHP reader and
   writer over zlib: the server has no ext-zip). Entries not touched are copied with their
   compressed bytes as they were (`ZipEntry::sameAs()`), never re-encoded; the new `NNNN.md`
   and `revlog.json` are raw DEFLATE;
2. before writing, check the record only grows: same pid, every earlier revlog entry and
   signature unchanged, the `archived` marker never taken away, the new entry chained;
3. write a temp file beside `x.zip`, `fsync` it, then **read it back and check**
   (`AtomicWriter::putChecked()`): exactly one entry per revision `1..N` plus `revlog.json`,
   each entry inflates and matches its CRC, its revlog `sha256` and `bytes`, the chain holds,
   `revlog.json` is the record meant, and every earlier entry is byte-identical to before;
4. `rename()` over `x.zip`, `fsync` the directory.

A check that fails removes the temp file, leaves `x.zip` as it was and the save fails
(`AtticException`, a fixed `reason` code, never a path). A process killed before the rename
leaves the old zip and a stray `x.zip.tmp-*`, which no reader opens. The same append twice is
a no-op (journal replay). `Storage\Attic::problems()` lists everything wrong with a zip, for
`integrity:verify`. The minor
edit (`saveMinor()`) replaces its own `NNNN.md` at step 2, only while that revision is
unsigned: invariant 3's one exception, unchanged. Signing writes no revision but rewrites
`revlog.json` the same way.

Size: the live archive is about 6 revisions of ~2.4 kB per page (31,577 revisions over 5,340
pages), so a zip is tens of kB and the rewrite costs nothing that matters.

## 5. The page file and its pid

The page file is today's `current.md` with one more frontmatter key, `pid`, written by
`Storage` on every save. It lets a page file moved or copied by hand be rejoined to its row.

- `pid` is the system's: an incoming `pid` in a save or raw edit is ignored and the row's is
  written (a raw edit cannot rebind history).
- It is part of the revision's bytes from the first 2.0 save on, so it is covered by a later
  signature like any other key. Signing canonicalisation does not change.
- **Pages migrated from 1.x keep their bytes** (§11): their page file has no `pid` until its next
  save, because adding one would make the file differ from the newest revision (D2) and, on a
  signed page, from the signed bytes. For such a file, rejoining falls back to its sha256: a page
  file whose bytes equal the current revision of a row whose own file is missing.

**Rejoining** (today's `adopt()` / `pages:repair-paths`): a page file whose path has no row is
looked up by `pid`, else by sha256. Found and the row's file is gone: the row moves to the new
path (a `move` row, the zip moved with it). Found and the row's file still exists: a copy,
reported (`duplicate_pid`), not adopted. Not found: a new page (a pid minted, revision 1 =
the file's bytes), so a `.md` dropped into `pages/` becomes a page.

## 6. `reporion.sqlite`: the permanent database

Two kinds of table, and the difference matters:

- **Mirrors** of the zip (`revision`, `signature`, and `page.rev/status/archived_*`): the zip is
  authoritative; `db:recover` and `integrity:verify` compare and repair from it.
- **Sole copies**: everything else. Lost without a backup, they are lost (§10).

The schema below is illustrative; `migrations/state/001_init.sql` (built in step 2) is the only
place it is defined. There, every child table's `pid` is `ON DELETE CASCADE` (a purge removes the
page row and with it everything else), and a delivery's `to` is the column `target`.

```sql
CREATE TABLE schema_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);

-- one row per page, the pid <-> path binding (the path is where the file is)
CREATE TABLE page (
  pid          TEXT PRIMARY KEY,
  path         TEXT NOT NULL UNIQUE,         -- colon path; for a trashed page, its path at deletion
  rev          INTEGER NOT NULL,             -- mirror of the zip
  status       TEXT NOT NULL CHECK (status IN ('draft','signed','archived')),  -- mirror
  archived_at  TEXT, archived_by TEXT, archived_rev INTEGER,                   -- mirror
  created      TEXT NOT NULL,
  created_by   TEXT NOT NULL,
  file_sha     TEXT NOT NULL,                -- sha256 of the page file as last written: a hand edit differs
  file_mtime   INTEGER NOT NULL,
  file_bytes   INTEGER NOT NULL,
  trashed_at   TEXT,                         -- NULL = live; set = files are in trash/{pid}/
  trashed_by   TEXT
);
CREATE UNIQUE INDEX page_live_path ON page(path) WHERE trashed_at IS NULL;

CREATE TABLE revision (                      -- mirror of revlog.json
  pid TEXT NOT NULL REFERENCES page(pid), n INTEGER NOT NULL,
  ts TEXT NOT NULL, by TEXT NOT NULL, note TEXT, kind TEXT NOT NULL,
  minor INTEGER NOT NULL DEFAULT 0, auto INTEGER NOT NULL DEFAULT 0,
  bytes INTEGER NOT NULL, sha256 TEXT NOT NULL, chain TEXT,
  PRIMARY KEY (pid, n)
);
CREATE INDEX revision_ts ON revision(ts DESC);

CREATE TABLE signature (                     -- mirror of revlog.json
  pid TEXT NOT NULL REFERENCES page(pid), rev INTEGER NOT NULL,
  by TEXT NOT NULL, ts TEXT NOT NULL, alg TEXT NOT NULL, digest TEXT NOT NULL,
  parafa TEXT, field_order TEXT NOT NULL,    -- JSON
  PRIMARY KEY (pid, rev, ts)
);

CREATE TABLE move     (pid TEXT NOT NULL REFERENCES page(pid), from_path TEXT NOT NULL,
                       to_path TEXT NOT NULL, ts TEXT NOT NULL, by TEXT NOT NULL);
CREATE TABLE redirect (path TEXT PRIMARY KEY, pid TEXT NOT NULL REFERENCES page(pid));
CREATE TABLE media    (pid TEXT NOT NULL REFERENCES page(pid), sha256 TEXT NOT NULL,
                       ext TEXT NOT NULL, name TEXT NOT NULL, bytes INTEGER NOT NULL,
                       w INTEGER, h INTEGER, added TEXT NOT NULL, by TEXT NOT NULL);
CREATE TABLE comment  (id TEXT PRIMARY KEY, pid TEXT NOT NULL REFERENCES page(pid),
                       by TEXT NOT NULL, ts TEXT NOT NULL, text TEXT NOT NULL, quote TEXT,
                       rev INTEGER, resolved_by TEXT, resolved_ts TEXT);
CREATE TABLE delivery (pid TEXT NOT NULL REFERENCES page(pid), target TEXT NOT NULL,
                       rev INTEGER NOT NULL, at TEXT NOT NULL, by TEXT NOT NULL,
                       outcome TEXT NOT NULL, extra TEXT);           -- JSON, scalars only
CREATE TABLE share    (pid TEXT PRIMARY KEY REFERENCES page(pid), hash TEXT NOT NULL,
                       created TEXT NOT NULL, expires TEXT, uses INTEGER NOT NULL DEFAULT 0,
                       max_uses INTEGER, note TEXT);
CREATE TABLE draft    (pid TEXT NOT NULL REFERENCES page(pid), username TEXT NOT NULL,
                       base_rev INTEGER NOT NULL, raw INTEGER NOT NULL, form TEXT NOT NULL,
                       saved TEXT NOT NULL, PRIMARY KEY (pid, username));
CREATE TABLE counter  (key TEXT PRIMARY KEY, value INTEGER NOT NULL);   -- D20
```

Limits carry over: comments 500 per page, text ≤ 4000, quote ≤ 300 (D52); deliveries 200 kept;
a draft ≤ 2 MB (D25). A redirect is a row, not a stub: an old path resolves to a pid and then to
that pid's current path, so chains cannot form. Accession allocation is `BEGIN IMMEDIATE` on
`counter` (D20's lock), still allocated before the create.

**Schema changes** to this file are data migrations (`migrations/state/NNN_*.sql`), each one
tested against the previous schema with data in it; there is no "delete and rebuild". The two
derived files keep `migrations/index/` and `migrations/vectors/` and may still be dropped.

## 7. The derived databases

`index.sqlite`: today's `pages`, `page_modalities`, `page_regions`, `page_tags`, `links`,
`page_exams` and `fts`, unchanged in shape. Dropped: `revisions` (now the `revision` mirror in
the state DB), `import_review` (the importer is gone), the unused `redirects`. `index:rebuild`
no longer walks `pages/`: it reads `SELECT pid, path FROM page WHERE trashed_at IS NULL` and
opens each page file. The determinism test (rebuild == incremental) stays.

`vectors.sqlite`: `page_vectors`, moved as it is (D15). Its own file because it is the one that
grows with the model's dimension and the one whose rebuild asks the embedding server.

**One connection, three files.** `reporion.sqlite` is the main database; the other two are
`ATTACH`ed as `idx` and `vec`, so `Search\Query::visibilityClause()` stays one predicate in one
query (invariant 6). In WAL mode SQLite does **not** commit attached databases atomically
together, so no write relies on it: the state transaction commits first, the index upsert
follows in its own transaction, and a crash between them is drift that `index:verify` repairs,
as today.

## 8. Write path

```
1. journal intent            (op, pid, path, target rev, document sha256)
2. attic zip                 §4: temp, fsync, verify, rename
3. page file                 temp, fsync, rename        (== the zip's newest entry, D2)
4. state transaction         page row, revision + signature mirrors, moves, …
5. index transaction         facets, links, tags, exams, fts
6. journal done
```

Replay compares what exists with the intent: zip has the revision and the page file does not →
write the file from the zip's entry; file written and the row is behind → mirror the zip's
`revlog.json` into the state DB; index behind → reindex. A crash before 2 leaves nothing. Writes
that touch only the state DB (comments, media manifest, delivery, share, draft, counter) are one
transaction and no journal line. Media bytes are still written first, `link()`-once (FORMATS §3b).

**Move:** journal → rename the page file → rename the zip → state transaction (path, `move` row,
`redirect` row for the old path, every older redirect to the same pid kept) → index → done.
**Delete:** both files into `trash/{pid}/`, `trashed_at` set, index row removed. **Restore:**
back to the stored path (refused if it is taken). **Purge:** files and rows removed; signed
pages need the override and audit entry (D3b, unchanged).

## 9. Backup

`reporion.sqlite` holds sole copies, so every backup must have it, and a live WAL database is
never copied as files:

- **D44 archive** (`backup:create`): `VACUUM INTO data/tmp/…` first, archived as
  `data/reporion.sqlite`; `index.sqlite*` and `vectors.sqlite*` still left out. Restore puts it
  back before `journal:replay`, then rebuilds the index.
- **D49 rsync**: each run first writes `data/snapshot/reporion.sqlite` (`VACUUM INTO` a temp
  name, `rename()`), then rsyncs with the live `*.sqlite*` excluded and `snapshot/` included.
- `doctor` fails when `reporion.sqlite` is missing or fails `PRAGMA integrity_check` (it never
  creates an empty one over a data directory that has pages), and warns when the snapshot is
  older than the backup interval.

## 10. Losing a database

| Lost | Effect | Recovery |
|---|---|---|
| `index.sqlite` | none | `index:rebuild` |
| `vectors.sqlite` | Similar reports empty | `index:vectors` (asks the model) |
| `reporion.sqlite`, with a backup | changes since the backup | restore the snapshot, then `db:recover --merge` re-reads every zip and page file (revisions and signatures since the snapshot come back from disk) |
| `reporion.sqlite`, no backup | comments, media names, moves and redirects, deliveries, share tokens, drafts | `db:recover`: pages, revisions, signatures, status from disk; counters re-seeded from accessions; reports what it could not recover |

Nothing legal is in the last row: every revision and signature is in a zip.

## 11. The converter (1.x → 2.0)

`tools/migrate-v2.php`, run once by hand as the owner of `data/` with the instance stopped. It
uses the autoloader and the 1.x reading code (`Support\Revlog`, the canonicaliser), so it checks
rather than only copies. Not a `bin/reporion` command, not in Admin → Maintenance.

```
php tools/migrate-v2.php --from=data --to=data.v2 [--dry-run] [--json]
```

1. Refuses if `--to` exists, an open journal intent exists, or a lock file is held.
2. Copies what does not change (users, groups, media, audit, journal, settings, site, tags,
   maintenance) with hard links where the filesystem allows.
3. For each page directory: `current.md` → `pages/…/{slug}.md` byte for byte;
   `rev/NNNN.md.gz` → `attic/…/{slug}.zip` entries (gunzipped) plus `revlog.json` from
   `meta.json`; rows for page, revisions, signatures, moves, deliveries, share token, `media.json`,
   `comments.json`, `autosave.*.json`.
4. Redirect stubs → `redirect` rows (each chain resolved to its pid); `trash/*` → `trash/{pid}/`
   with `trashed_at`; `counters.json` → `counter`.
5. Checks every page: the page file equals the old `current.md`; every zip entry's sha256
   equals the revlog; the chain holds; each signature digest re-computes from its entry; the
   rows equal the `meta.json` they came from. Any mismatch: exit 1, the page named by pid, `--to`
   left for inspection, `--from` untouched.
6. Prints counts only (pages, revisions, signatures, comments, redirects, trash), never a path
   (invariant 8).

Then, by hand: `data` → `data.v1`, `data.v2` → `data`, `bin/reporion index:rebuild`,
`bin/reporion integrity:verify`. Rolling back is renaming back. Run it on a copy first.

## 12. Invariants, as they will read in 2.0

1. **Content and the legal record are on disk; operational state is in `reporion.sqlite`;
   everything derived is in `index.sqlite` and `vectors.sqlite`.** Deleting either derived file
   loses nothing. `reporion.sqlite` is backed up with `data/`. No revision or signature exists
   only in a database; if a mirror and a zip disagree, the zip is right.
2. **A page is a file.** `pages/{ns…}/{slug}.md` is its current state, `attic/{ns…}/{slug}.zip`
   its history, its `page` row (by pid) everything else. File, zip and row move together under
   one journal line.
3. **History is append-only.** A zip entry is written once; rewriting the container is allowed
   only after every earlier entry is checked against the revlog. Signed revisions keep their
   signature record forever. The minor-edit exception stays, for an unsigned entry.
4. Unchanged.
5. **Writes go through `Storage`**, which owns the page files, the attic and `reporion.sqlite`.
   Nothing outside `src/Storage/` builds a page path or writes a state table.
6. Unchanged (the predicate reads `idx.pages` on the shared connection).
7. **Atomic writes only:** temp + `fsync` + `rename()` for files, one transaction per database,
   and a journal line before any write that spans a file and a database.
8. Unchanged. The state DB holds paths and comment text, like the files it replaces; it never
   leaves the box except inside a backup.
9. and 10. Unchanged.

## 13. What is touched

The ask-first list, all of it approved by this decision: on-disk layout, index schema,
revision and signing code (the container, not the canonicalisation), and the `StorageInterface`
driver (D9: a new driver, `Storage\FileTree`, beside `FlatFile` until the switch).

| Area | Change |
|---|---|
| `src/Storage/` | new `FileTree` driver, `Attic` (zip read/write/verify), `StateDb`; `Journal` replay rules; `FlatFile` kept read-only for the converter, then removed |
| `src/Index/` | `Sqlite` reads paths from `page`, no `revisions` table; vectors on `vec` |
| Outside Storage | ~16 files that build page paths today (Signing, Publishing, Duplicates, IntegrityVerify, Doctor, IndexRebuild, several controllers, the Installer) go through `Storage` (invariant 5) |
| Backup | §9 for `BackupService`, `ArchiveReader`, `RsyncBackup`, both targets |
| CLI | `db:recover`; `pages:repair-paths` becomes the rejoin of §5; `journal:replay`, `integrity:verify`, `doctor` |
| Installer | creates the three databases |
| Docs | this file becomes architecture-storage-index draft 5; FORMATS §2–3m, §4, §4b, §14; CLAUDE.md invariants, layout, D2, D25, D52 |

## 14. Order of work

Each step its own commit with its tests; the full suite before each merge (storage, index,
signing are all touched).

1. This document and D54. *Done 2026-10-20.*
2. `Storage\Databases`: `migrations/state/001_init.sql`, `migrations/index/` and
   `migrations/vectors/` split, `page_vectors` moved to `vectors.sqlite` (an older index's rows
   copied, `migrations/index/006_vectors_moved.sql`), the attached connection used by
   `Index\Sqlite`; `reporion.sqlite` created empty. *Done 2026-10-20.* Until step 7 it is
   archived and rsynced as a plain file, which is consistent only while nothing writes it.
3. `Storage\Attic` over `Support\Zip` (now also a reader, and raw DEFLATE entries) and
   `AtomicWriter::putChecked()`: append, minor replace, record-only write, `problems()`; tests
   for every refusal, a corrupt entry, a stray temp file and a process killed before the rename.
   *Done 2026-10-20.*
4. `Storage\FileTree` (FlatFile's constructor and public API, so callers switch by name) and
   `Storage\StateDb` (all SQL of `reporion.sqlite`; `migrations/state/002_page_visibility.sql`
   adds the page's visibility, kept as 1.x kept it in meta.json). One write lock
   (`data/.write.lock`) around every page write; `sign` is journalled now (two writes);
   replay re-syncs a page from its zip. The page file's frontmatter carries `pid` (last key);
   `PageRecord->frontmatter` and the index never see it. A page and a namespace of the same
   name are a file and a directory, so the "has pages under it" refusals are gone. Rejoin
   (`reconcilePath()`, `PATH_COPY` for a copy) and adoption of a hand-made page file;
   accession counters (`nextAccession()`). *Done 2026-10-20.*
5. The index's `revisions` table dropped: written only, never read, and the state DB mirrors
   every revision now.
6. The switch: the Kernel, the CLI and the installer build `FileTree`; every service that read
   the 1.x layout (integrity, doctor, path repair, accessions, index maintenance) goes through
   it (invariant 5); the tests move to `FileTree`.
7. Backup and rsync with the `VACUUM INTO` snapshot, restore, doctor, `db:recover`.
8. `tools/migrate-v2.php`, tested on synthetic 1.x trees; then on a copy of the live archive
   (5,340 pages): counts, `integrity:verify` clean, `index:rebuild` under 60 s.
9. `FlatFile` removed, CLAUDE.md's invariants flipped, the 1.x docs updated, tag 2.0.
