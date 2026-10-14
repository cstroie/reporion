# Study: page = markdown + revisions + an append-only event log; everything else in SQLite

## Context

Today a page directory holds `current.md`, `rev/NNNN.md.gz` and a mutable `meta.json`
(pid, path, rev, revlog, signatures, status, visibility, moves, share_token, locks,
deliveries, archived, created/created_by). `meta.json` caused the stale-`path` bug and
duplicates facts the index also holds. The goal: on disk keep only the page text, its
history and whatever cannot be recomputed; put every other fact in SQLite; and keep the
guarantee that a lost or corrupt `index.sqlite` is rebuilt **identically** from disk.

That last requirement decides the design: anything not derivable from the text must stay
on disk somewhere. The owner chose **append-only event files** for that, not DB-only.

Live archive today: 5,340 pages, 31,577 revisions, 69 signatures, 4,901 `archived`
markers, 50 pages with moves, 1 with deliveries, 0 share tokens.

## Target layout (per page directory)

```
current.md          the document (frontmatter + body), as today; editable in raw mode
rev/NNNN.md.gz      revisions, unchanged format, write-once (minor edit exception stays)
log.ndjson          append-only events: the only system file; never rewritten
```

`meta.json` disappears. Nothing is ever edited in place except `current.md` and the
minor-edit revision (invariant 3, unchanged).

### What goes where

| Fact | Today | Target | Rebuilt from |
|---|---|---|---|
| path | meta.json | directory (already authoritative) | directory |
| pid | meta.json | `log.ndjson` first line (`create`) | log |
| rev, revlog (ts, by, note, kind, minor, auto, bytes, sha256) | meta.json | one `rev` event per revision | log |
| signatures (by, ts, alg, digest, parafa, field_order) | meta.json | `sign` event | log |
| status draft/signed/archived | meta.json | derived: `archive` event, else signature on current rev | log |
| archived marker {by, at, rev} | meta.json | `archive` event | log |
| moves | meta.json | `move` event (stubs stay on disk as today) | log |
| share_token hash, deliveries | meta.json | `share` / `delivery` events | log |
| visibility | meta.json + frontmatter | frontmatter only (already there) | current.md |
| created, created_by | meta.json | the `create` event | log |
| locks | meta.json (always null) | dropped | - |
| title, facets, links, tags, FTS | index | index (unchanged) | current.md |

Frontmatter stays the user's: nothing system-owned is added to it, so a raw edit can never
change pid, history or signatures. Visibility is the only system-relevant field read from
frontmatter, as today.

### Event log format

One JSON object per line, `{"e": "...", "ts": ..., "by": ..., ...}`. Events: `create`
(pid), `rev` (the revlog entry), `minor` (supersedes the `rev` entry of the same n),
`sign`, `archive`/`unarchive`, `move` (from, to), `share`, `delivery`. Fold rules are
pure and live in one class (`Storage\PageLog`), used by both Storage reads and rebuild.
A torn last line (crash mid-append) is ignored, as `Journal` already does.

## Database side

New tables (a migration; schema change needs the owner's approval, which this study asks for):
`page_revisions` (pid, n, ts, by, note, kind, minor, auto, bytes, sha256) replacing the
broken `revisions` mirror; `page_signatures`; `page_moves`; `page_deliveries`; columns on
`pages` for created/created_by/archived_*/share_token_hash. `pages.status` already exists.

Reads that use `meta.json` today (revisions screen, signing checks, export verification,
integrity, trash list) switch to these tables or to `PageLog::fold()` directly.

## Write path

Every Storage write: (1) journal intent, (2) revision file if any, (3) `current.md`,
(4) append the event line + fsync, (5) index upsert, (6) journal done. Appending replaces
today's atomic rewrite of `meta.json`. Journal replay finishes a write by checking whether
the event line is present (by pid + n/op), instead of comparing meta.json fields.

## Rebuild

For each page directory: read `current.md`, read and fold `log.ndjson`, write the rows.
No revision file is opened (sha256/bytes are in the `rev` events), so the cost is close to
today's: one small file per page instead of `meta.json`, well within the 60 s target.
The determinism test (rebuild == incremental) extends to the new tables.

## Trash and restore

The page directory moves to `trash/` intact, log included; restore reads the original path
from the last `move`/`create` event. Purge unchanged.

## Migration of the live archive

A one-off `pages:migrate-log` command (check/apply, journaled, run as www-data):
for each page, write `log.ndjson` from `meta.json` (create, one `rev` per revlog entry,
sign, archive, moves, deliveries), verify the fold equals the meta.json it came from,
then remove `meta.json`. Revision files are not touched. A backup archive (`backup:create`)
first. Pages failing the equality check are left with their meta.json and listed.

## Effects to account for

- Invariants 1, 3, 7 still hold; the docs' `meta.json` sections, FORMATS, backup manifest,
  integrity checks (`rev_changed` uses revlog sha256 from the log), and `allPaths()` (a page
  = `current.md` + `log.ndjson`) change.
- Ask-first items touched: on-disk layout, index schema, revision and signing code.
- Plugins reading `$record->meta` (hipobridge `archived`, deliveries) get the same array,
  produced by the fold, so `PageRecord` stays source-compatible.

## Critical files

`src/Storage/FlatFile.php` (readMeta/writeMeta and every caller, journal recovery,
trash/restore, minor edits), new `src/Storage/PageLog.php`, `src/Index/Sqlite.php`
(write/rebuild/verify), a new migration, `src/Service/Revisions.php`, `Signing`,
`Maintenance/IntegrityVerifyTask.php`, `Backup/*`, docs `architecture-storage-index.md`,
`FORMATS.md`, `CLAUDE.md` invariants 2/3.

## Verification (when implemented)

- Storage tests: round trips, crash between each step of the write path, torn last line.
- PageLog fold tests, including minor edit and re-sign.
- Index: rebuild == incremental, byte-identical, including the new tables.
- Migration on a copy of `data/` (scratch dir, never live): every page's fold equals its
  old meta.json; `integrity:verify` clean; `index:rebuild` time measured on 5,340 pages.
- Full suite before merge (storage, index, signing touched).
