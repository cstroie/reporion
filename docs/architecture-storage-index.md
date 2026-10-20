# Storage & index model

Reporion: a flat-file wiki for imaging reports. PHP 8.x, on-disk pages, SQLite index.

*draft 5 · 20 Oct 2026 · D1–D28 decided, D35–D37 supersede D6/D13/D14 · multi-user, small-team deployment · storage rewritten for 2.0 (D54, built 2026-10-20: docs/architecture-v2.md)*

The disk is the database. Every page is a plain-text file that a radiologist could read with `cat` in twenty years, its history one zip beside it, and the SQLite index is a disposable cache that can be deleted at any moment and rebuilt from those files. Operational state (comments, moves, media names, drafts, counters) lives in the permanent `data/reporion.sqlite`, which is backed up with `data/` and is not a cache. Everything below follows from that one commitment.

## 1. Principles

- **Disk is authoritative, index is derived.** If the two disagree, the disk wins and the index is wrong. No feature may store its only copy of anything in the *index* (`reporion.sqlite` is the permanent state database, not the index: D54).

- **A page is a file, plus its history and its row (D54, 2026-10-20; was: a page is a directory).** `pages/{ns…}/{slug}.md` is the page's current state, `attic/{ns…}/{slug}.zip` its history (every revision plus the revision log and signatures, so each zip verifies on its own), and its `page` row in `data/reporion.sqlite` everything else (pid↔path, moves, redirects, media names, comments, deliveries, share token, drafts). The three move together under one journal line. Namespaces are directories, so a page `a:b` is `a/b.md` beside the namespace `a/b/`, and a page with pages under it moves and deletes alone. See docs/architecture-v2.md.

- **Append-only history.** Revisions are never rewritten or deleted; a signed revision is immutable by construction.

- **Canonical rendering is server-side.** The page view, the PDF, the ODT and the index all render through `Render::toHtml()`. The editor's live preview renders through the same PHP renderer (`POST /api/v1/render`; marked.js was dropped 2026-10-15, D17), so there is one parser.

- **Identity survives renaming.** Every page carries an immutable id; paths are labels.

- **The index never leaks.** Visibility is resolved inside every query, not applied to its results: an unlisted or private page must not surface in a public search, ever.

## 2. Page identity and paths

A page has two names. The **path** is what people type and see:`reports:mri:mioveni:260922-ionescu-maria`, colon-separated, mapping directly to directories and a file name (`a:b:c` is `pages/a/b/c.md`). The **pid** is a 26-character ULID minted at creation, stored in the page's row in `reporion.sqlite` and as the last key of the page file's frontmatter (D54), and never changed by anything, including a move between hospitals.

#### Slug rules

Path segments are normalised: NFKD-folded to ASCII (`Ionescu Mária` → `ionescu-maria`), lowercased, non-alphanumerics collapsed to a single hyphen, trimmed to 64 characters. A segment may not end in `.md`, `.zip` or `.lock`, contain `.tmp-` or start with `.` (`FileTree::isValidPath`); since D54 `rev`, `meta.json`, `current.md`, `comments.json`, `redirect` and `autosave.*` are ordinary names. Reserved segment prefixes: `_` for namespace-level pages (`_index`, `_template`, `_acl`), so a report can never collide with machinery. A namespace's description is the page with the namespace's own name (`reports:mri:mioveni` for the Mioveni site, 2026-09-26), shown on the namespace index; `_index` still works where it exists. Reserved namespace roots: `reports`, `protocols`, `templates`, `teaching`, `archive`, `trash`. `title`/`summary`/`tags`/`visibility` on that description page are already generic page frontmatter (TODO 13, no schema); `priority` (`low`/`medium`/`high`, unset by default) joins them the same way: a purely decorative background tint on that namespace's card wherever it is listed as a sub-namespace (`Controller\NamespaceController`, `assets/css/wiki.css`'s `.wk-nscard[data-priority]`), read from disk like `tags` rather than as an indexed column, since nothing needs to search or sort by it.

> **D1: decided: keep the patient name in the path**:`{yymmdd}-{name}`, because it is the single biggest driver of day-to-day speed. The compensating rules are now requirements, not suggestions: the path never appears in an export, an externally shared URL, a log line, an audit entry (which stores `path_hash`) or an AI prompt: those all use the pid. Amended 2026-10-11 (owner's decision): an export's *file name* is the page path with `-` for `:` (`reports-mri-polimed-261008-name.pdf`) for a signed-in caller, so reports sent to the imaging center are never mixed up; an anonymous export of a public page keeps `{accession or pid}-rev{N}`. The pattern still comes from site settings and no code may assume which one is in force.

**The page file is the page's path (2026-10-14; rewritten 2026-10-20, D54).** The `page` row's `path` says where the file should be; reads (`FileTree::read()`), the index snapshot (so `index:rebuild` too) and `index:verify` (the path is part of its disk facts) look at the file, so a page file moved on the filesystem by hand is found by `strayPaths()` (a `.md` with no row at its path). It is rejoined to its row by the `pid` in its frontmatter, else, for a file without one (a page converted from 1.x keeps its bytes until its next save), by the bytes its page last had (the row's `file_sha`, only for a row whose own file is gone). Three things reconcile it: `integrity:verify` reports `path_drift` (a page file moved by hand), `duplicate_pid` (a copy: left alone, `FileTree::PATH_COPY`) and `stray_files` (a file no page is known for); `bin/reporion pages:repair-paths` (also Admin → Maintenance) moves the row and the attic zip to the file's path, adds a `move` and a `redirect` row and indexes the page (`FileTree::reconcilePath()`; no revision, so not history), and adopts a file no page is known for as a new page (its bytes revision 1, revlog note `adopted`, auto); and a **signed-in lookup that misses the index** (`Index\Sqlite::findByPath()` through `onMiss()`, wired to `FileTree::adopt()`) does the same, then asks the index again, so visibility is still decided there (invariant 6). Anonymous lookups never write. The repair is refused for a page an open journal intent names (left for `journal:replay`).

#### Moves and redirects

*Rewritten 2026-10-20 (D54): a move was a directory rename plus a `redirect` stub directory.*

A move renames the page file and its attic zip, then in one state transaction sets the row's `path`, adds a `move` row (`{from_path, to_path, ts, by}`; not in the revision log: a move is not a revision, and a revlog entry would duplicate a rev number) and a `redirect` row for the old path (`path → pid`; every older redirect to the same pid is kept). Redirects are rows, not stub directories: they are not in the tree or the index but resolve on request, to the pid's *current* path, so every link, bookmark and `priors:` reference in another report keeps working forever and a chain cannot form. A page with pages under it moves alone: a page `a:b` is the file `a/b.md` beside the namespace directory `a/b/`, which never travel together.

## 3. On-disk layout

*Rewritten 2026-10-20 (D54, docs/architecture-v2.md §3): a page was a directory (`current.md`, `meta.json`, `rev/NNNN.md.gz`, `media.json`, `comments.json`, `autosave.*.json`).*

```
data/
├─ pages/                      current state only: what `cat` shows is the wiki
│  └─ reports/mri/mioveni/
│     └─ 260922-ionescu-maria.md   frontmatter (ending in `pid`) + body; == newest revision (D2)
├─ attic/                      history, mirroring pages/
│  └─ reports/mri/mioveni/
│     └─ 260922-ionescu-maria.zip  0001.md … 0007.md + revlog.json (revlog, signatures, status)
├─ reporion.sqlite (+ -wal)    PERMANENT operational state: page rows (pid↔path), mirrors of the zips,
│                              moves, redirects, media manifest, comments (D52), deliveries, share token,
│                              editor drafts (D25), accession counters (D20). Backed up with data/
├─ index.sqlite (+ -wal)       derived: deletable, rebuilt from the page files + the page rows
├─ vectors.sqlite (+ -wal)     derived: the embeddings of Similar reports; deletable, rebuilt by index:vectors
├─ snapshot/reporion.sqlite    VACUUM INTO copy for rsync (D49); never opened by the app
├─ users/{username}.json       accounts and their own grants (D35/D36); never in the index
├─ groups/{name}.json          named sets of grants + a radiologist flag (D47); accounts name them; never in the index
├─ media/                      shared media, addressed by content hash (D27)
│  └─ 2026/8f2c41…e9.jpg
├─ journal/
│  └─ 2026-09-22.ndjson        write-intent log for crash recovery
├─ audit/
│  └─ 2026-09.ndjson           reads/writes of non-public pages
├─ trash/
│  └─ {pid}/                   a deleted page: page.md, attic.zip and `path` (the path it had); purged by cron
├─ tmp/
│  └─ dicom/{token}            an upload kept only until used (Service\TempUploads): read once and removed;
│                              older than an hour is never given and is swept on the next upload. Holds
│                              nothing else: deleting tmp/ loses nothing (invariant 1)
conf/
└─ local.php                   strict configuration: the data path and the session secret
                               (`paths.state` / `paths.vectors` default to data/reporion.sqlite and data/vectors.sqlite).
                               Site settings are data/settings.yaml; accounts data/users/, groups data/groups/
resources/                     shipped with the code, git-tracked (not instance data)
├─ schema/                     per-modality metadata schemas (§7)
└─ synonyms.txt                seed of the tag dictionary (data/tags.yaml)
```

`reporion.sqlite` is **not a cache**: comments, media names, moves and redirects, deliveries, share tokens and drafts exist only there, so every backup must carry it (docs/FORMATS.md §14). The zip wins any disagreement about revisions and signatures (`integrity:verify` `mirror_drift`, `bin/reporion db:recover`). `Storage\Databases` opens it as `main` with the index (`idx`) and vectors (`vec`) attached, so `Search\Query::visibilityClause()` stays one predicate in one query.

> **D2: why the page file duplicates the newest revision**. Reading a report is the most common operation in the system and must never require unzipping. The page file (`pages/…/{slug}.md`) is the plain-text fast path and what `cat` shows; the attic zip's newest entry is the archival copy. They are written in one journalled sequence and verified by checksum (the row's `file_sha`, `integrity:verify`'s `current_stale`). Cost: ~2× storage on the newest revision, which at 2.4 kB a report is nothing. (Was `current.md` beside `rev/NNNN.md.gz`, until D54, 2026-10-20.)

## 4. File formats

### The page file

The file is YAML frontmatter followed by markdown (`pages/{ns…}/{slug}.md`, once `current.md`). Frontmatter is **editorial** metadata: what a radiologist would consider part of the document. It is written by humans and by the HL7 bridge, and it is what the schema in §7 validates.

```
---
title: RM cerebral nativ și cu substanță de contrast
modality: MR
region: neuro
device: MV-MR-01
site: mioveni
accession: MV-RM-26-0918
study_date: 2026-09-22T09:14:00+03:00
patient: { name: "IONESCU MARIA", born: 1974, sex: F, cnp: null }
referrer: "dr. C. Neagu: Neurologie"
indication: "Parestezii membre inferioare, SM cunoscută: control"
protocol: brain-demyelination-v3
template: templates:mri:cerebral-sm
visibility: private
tags: [demielinizare, SM, follow-up]
priors: [reports:mri:mioveni:260304-ionescu-maria]
summary: >
  Boală demielinizantă supra- și infratentorială, stabilă…
---

## Indicație
…
```

The exam title (reports created in the app, 2026-09-26): the exam, e.g. `IRM Cerebral`, is `exams[].title` (D48:
no top-level `exam_title` is stored; the index adds the titles joined by " + " as `exam_title` to its copy of
the frontmatter, `meta_json`, for its listings), while the
first `#` heading carries the patient's name (and is the title: no `title` key is written, 2026-10-13); exports, the public layout and duplicates use
the exam title (`Support\Exams::title()`) and leave the name heading out (`Support\ReportName`, D30 as amended).
**Shared and per exam (D48, FORMATS §12):** a report's `modality`, `device` and `study_date` (the day) belong to the report;
each exam has its `time`, `region`, `template`, `accession` and study. The index derives `pages.study_date` (the day and the
earliest exam time), `pages.device`, `pages.accession` (the first exam's), `page_modalities`, `page_regions` (every exam's) and
`page_exams` from `Support\Exams::report()`/`of()`, which read every shape a report was ever stored in; `index:rebuild` produces
the same rows whichever shape the files are in.

`indication` is the reason for examination (the referring diagnosis). The modality schemas make it
`required_for: sign`; it is shown in the page's metadata panel and printed as the *Indicație* row of
the report header. An imported report keeps its original `Indicație` sentence in the body (D31) and
has no `indication` field until someone fills it in, so nothing is printed twice.

### The record: `revlog.json` and the state database

*Replaces `meta.json` (D54, 2026-10-20): the file is gone. Its history fields moved into the attic zip; the rest into `data/reporion.sqlite` (docs/architecture-v2.md §4, §6).*

**System** state: what the application knows about the page and a human would not type. Kept out of the markdown so that frontmatter stays legible and diffs stay meaningful. `PageRecord->meta` still has the same keys, assembled from two places:

- `attic/{ns…}/{slug}.zip`'s `revlog.json` `{v: 2, pid, status, archived, revlog, signatures}`: the legal record, authoritative. `revlog` entries are `{n, ts, by, note, bytes, sha256, minor, kind, chain}`; `signatures` entries `{rev, by, ts, alg, digest, parafa, field_order}`.
- `reporion.sqlite`: the `page` row (`pid`, `path`, `rev`, `status`, `visibility`, `created`, `created_by`, `file_sha`/`file_mtime`/`file_bytes`, `trashed_at`/`trashed_by`), the `revision` and `signature` mirrors, `move`, `redirect`, `delivery` and `share` (the share token's hash). The mirrors are rebuilt from the zip when they disagree (`integrity:verify` says `mirror_drift`, `db:recover` repairs); everything else in the database is the only copy.

`created`, `created_by`, `visibility` and the path are the row's. `moves` and `deliveries` are in `PageRecord->meta` only when there are some. There is no `locks` key.

> ⚠︎ **Invariant.** The zip is rewritten atomically (temp file, read back and checked, `rename()`) and no earlier entry of it ever changes. Anything appended to `revlog` or `signatures` is never edited afterwards: **except** a minor edit (`revlog[].minor: true`, `Storage\FileTree::saveMinor()`), which replaces its own revision's entry in place (`NNNN.md` and its revlog line) instead of appending a new one, and only while that revision is unsigned. CLAUDE.md invariant 3's one deliberate exception, refused once that revision carries a signature (D3: a signature covers its exact bytes).

> **History hash chain (2026-10-17).** Every `revlog` entry written from now on carries `chain` = sha256 of `previous \n n \n ts \n by \n sha256`, where `previous` is the preceding entry's `chain` (or, for an entry written before the chain existed, its `sha256`; the first revision's is the page's pid). `Support\Revlog::chained()` writes it at every append, a minor edit re-links its own (newest) entry, and journal recovery does the same. History already on disk is never rewritten (D3, invariant 3): it is anchored by the first chained entry. `integrity:verify` reports `chain_broken` (an entry, or a revision dropped or reordered, no longer follows) and `chain_missing` (a link stripped after the chain started). This is tamper *evidence*: whoever can rewrite the zip can recompute every link, so it catches partial or accidental changes, not a determined attacker with the whole `data/` tree.

> **Autosave drafts (2026-10-12; since D54, 2026-10-20, the `draft` table).** A draft (docs/FORMATS.md §3l) is one row per page and user in `reporion.sqlite`, replacing `autosave.{username}.json` in the page directory. It is a state-only write (one transaction, **no journal line**): never part of a multi-file write, a crash leaves the old draft or the new one, and losing it loses nothing that was saved. It is not read by the index or `index:rebuild`, never appears in `revlog`, and is removed by its author's next Save, minor Save or revert. A move carries it with the page's pid; a purge deletes it. Only an existing page has one, at most 2 MB.

## 5. Revisions, signing and amendment

### The write path

*Rewritten 2026-10-20 (D54): the 1.x sequence was `rev/000N.md.gz`, `current.md`, `meta.json`. Detail in docs/architecture-v2.md §8.*

All of it under one write lock (`data/.write.lock`).

- Validate frontmatter against the modality schema (§7). Hard errors block the save; soft warnings are returned to the editor.

- Append a write-intent line to `journal/`: op, pid, path, target rev, document sha256. This is what makes the steps below recoverable.

- Write the attic zip (`Storage\Attic`): the new `NNNN.md` and `revlog.json`, earlier entries copied byte for byte, temp file `fsync`ed and read back and checked (`AtomicWriter::putChecked()`), then `rename()`. If the revision is already in the zip, the save is a duplicate submission and is idempotently ignored.

- Write the page file (`pages/{ns…}/{slug}.md`): temp, `fsync`, `rename()`. Its frontmatter ends with `pid`, written by `Storage` (never in `PageRecord->frontmatter` or the index).

- One state transaction: the `page` row (rev, status, file sha/mtime/bytes), the `revision` and `signature` mirrors, moves.

- Index in one SQLite transaction (§6). Mark the journal line done.

A crash after the intent leaves an open journal line; the first request more than a minute later replays it (or `bin/reporion journal:replay`, docs/FORMATS.md §2): replay compares what exists with the intent and re-syncs the page from its zip (file missing: written from the zip's entry; row behind: mirrored from `revlog.json`; index behind: reindexed). A crash before the zip rename leaves nothing. A reader sees the row's committed rev while a write is in flight, never a partial file. Signing is journalled too (op `sign`: it rewrites `revlog.json` and the row).

### Revert

`Storage::revert($path, $toRev, $actor)` is a second entry into this exact same write path: same
journal intent (`op: "revert"`), same attic zip + page file sequence, same crash-recovery
replay: with one difference: the bytes it writes as revision N+1 are revision `$toRev`'s own bytes,
read back off disk and replayed verbatim, never re-encoded from a caller-supplied frontmatter/body.
That is what makes A2 ("revision 8's content equals revision 6's") literally, byte-for-byte true
rather than true only after normalisation. It is its own `StorageInterface` method, not a `$kind`
parameter on `save()`, because the caller supplies a revision number, never content:`save()`'s
contract is "here is the new content," revert's is "make an old revision current again," and
collapsing those into one method would let a caller claim to be "reverting" while actually
supplying arbitrary bytes. No `base_rev` conflict check either: `save()`'s exists to protect
caller-supplied content the caller might not have seen change; revert never makes that claim, so
there is nothing to protect against: it always appends `$toRev`'s content forward, regardless of
what happened in between. `status` is never carried forward from the reverted revision: reverting
a `signed` page produces a fresh `draft` (D3: it needs signing again, in its own right).

### Signing

Signing computes `sha256` over the canonical bytes of that revision (LF line endings, frontmatter key order normalised) and appends a signature record. From then on, that revision is legally the report.

> **D3: decided: correction by re-signing, not by addendum**. Editing a signed report produces a new revision which is then signed in its own right, and that new revision becomes the current document. No addendum block is rendered. The superseded signed revision keeps its signature record in the zip's `revlog.json` (mirrored in the state DB) and stays readable in history forever, so "what did the referrer receive on 22 Sep" is always answerable. Status stays `signed`; `revlog[].kind` records `resign`, and the revisions screen marks which signed revision was current at any date.

> ⚠︎ **Consequence worth stating plainly.** A PDF already sent to a referrer may no longer match the current page. Each export therefore embeds the revision number and a verification URL (`/r/<pid>/<rev>`) that renders exactly those signed bytes, that link, not the page, is the citable document.

> **D3b: no deletion of signed content**. A signed revision cannot be deleted, only superseded. Page deletion moves the page file and its zip to `trash/{pid}/` with a 30-day purge; purging a page with signatures requires an explicit admin override and writes an audit entry naming the operator. Not configurable.

### Concurrency

Optimistic, not locked. The editor submits the rev it started from; if the current rev is higher, the API returns `409` with both bodies and a section-level three-way merge suggestion (the mockup's conflict state). A soft advisory lock (not built; meta.json is gone, D54) would expire after 15 minutes and only produce a warning banner: never a hard block, because a radiologist with a locked page and a waiting patient will simply lose trust in the tool.

## 6. The SQLite index

One file, `data/index.sqlite`, WAL mode, `busy_timeout=5000`, `synchronous=NORMAL`. It exists to answer three questions fast: *what pages are there*, *which ones match this text*, and *which ones is this user allowed to see*. Nothing else belongs in it.

### Core tables

```
CREATE TABLE pages (
  pid              TEXT PRIMARY KEY,     -- ULID, from the page file's `pid` / the state DB's `page` row
  path             TEXT NOT NULL UNIQUE, -- colon path
  ns               TEXT NOT NULL,        -- parent namespace
  title            TEXT NOT NULL,
  rev              INTEGER NOT NULL,
  status           TEXT NOT NULL,        -- draft|signed|archived
  visibility       TEXT NOT NULL,        -- private|unlisted|public
  -- denormalised, indexed frontmatter (the facet set):
  device TEXT, site TEXT,
  accession        TEXT, study_date TEXT, protocol TEXT,
  summary          TEXT,
  patient_key      TEXT,                 -- sha256(cnp) when known (D11)
  patient_key_weak TEXT,                 -- sha256(name|born|sex), never the name
  updated          TEXT NOT NULL,
  updated_by       TEXT NOT NULL,
  edited           TEXT, edited_by TEXT, -- newest hand edit (003_hand_edits.sql)
  signed_at        TEXT, signed_by TEXT, -- first signature, first revlog signature, from the state DB's `signature` mirror (004_signed.sql)
  bytes            INTEGER NOT NULL,
  mtime            INTEGER NOT NULL,     -- for reconciliation
  body_sha         TEXT NOT NULL,
  meta_json        TEXT NOT NULL         -- full frontmatter, for rare fields
);
CREATE INDEX pages_ns       ON pages(ns);
CREATE INDEX pages_facets   ON pages(site, status);
CREATE INDEX pages_study    ON pages(study_date DESC);
CREATE INDEX pages_patient  ON pages(patient_key, study_date DESC);
CREATE INDEX pages_patient_weak ON pages(patient_key_weak, study_date DESC);

-- modality and region are LISTS, not scalars (D29): a combined CT
-- cerebral + cervical study needs both values indexed, and a facet must
-- count a page once per value:
CREATE TABLE page_modalities (pid TEXT NOT NULL, modality TEXT NOT NULL, PRIMARY KEY (pid, modality));
CREATE TABLE page_regions    (pid TEXT NOT NULL, region   TEXT NOT NULL, PRIMARY KEY (pid, region));

-- a multi-exam report's exams (phase 12, migrations/index/002_page_exams.sql): one row per
-- `exams:` entry. page_regions takes every exam's regions too, and pages.accession
-- the first exam's number, so existing lookups keep working:
CREATE TABLE page_exams (pid TEXT NOT NULL, n INTEGER NOT NULL, title TEXT NOT NULL, accession TEXT, PRIMARY KEY (pid, n));
```

> This is the illustrative shape; `migrations/index/*.sql` (the vectors' `migrations/vectors/*.sql`, the permanent state's `migrations/state/*.sql`, D54) is the only place the schema is
> actually defined (per the working agreement: when a doc and the code disagree, the doc is
> wrong). It also carries `page_tags`, `links`, `page_exams` and `fts` (the `revisions` mirror is gone, D54), the
> `import_review` queue (legacy, unused since the importer was removed) and `schema_meta.schema_version`: see that file for the full DDL.

> **D4: typed columns for the facet set, JSON for the tail**. Fields you filter or sort on get real columns; everything else lives in `meta_json` and is reachable with SQLite's JSON functions when needed. Promoting a field from the tail to a column is a migration plus a reindex: cheap, and the schema file in §7 declares which fields are promoted, so the migration is generated rather than hand-written. The alternative, a generic key/value table, makes every faceted query a pile of self-joins; at 10k reports you would feel it.

#### Tags, links

```
CREATE TABLE page_tags (pid TEXT, tag TEXT, PRIMARY KEY (pid, tag));
CREATE TABLE links     (src TEXT, dst_path TEXT, dst_pid TEXT,
                        kind TEXT);            -- link|prior|template|protocol
```

The `revisions` table the index used to keep (written on every index(), read by nothing, only
ever each page's newest revision) is dropped (`migrations/index/007_revisions_dropped.sql`,
D54 step 5): every revision of every page is in `data/reporion.sqlite`'s `revision` table, a
mirror of the page's attic zip (docs/architecture-v2.md §6).

There is no `tags` table. The tag dictionary (group, ICD-10, synonyms) is admin-typed data with no
other copy, so it lives on disk in `data/tags.yaml` (docs/FORMATS.md §3g, phase 20: invariant 1),
read directly by Admin → Tags and by search's D28 expansion; `page_tags.tag` is whatever a page's
frontmatter says, in the dictionary or not. An unused `tags` table that `001_init.sql` used to
create (2026-10-14: no longer) stays in an index built before that, harmless: `index:rebuild` never
touched it.

`links.dst_pid` is null for a target that does not exist yet, that is exactly the broken-links report, and it is a single `WHERE dst_pid IS NULL`. It resolves as soon as the target is written (and `rebuild` resolves forward references in a final pass, so a rebuild and incremental indexing agree); removing a page sets links to it back to null. Rows come from `priors` (`kind = prior`) and from the body's links between pages (`kind = link`, `Support\InternalLink::extract()`). `backlinks()` returns, for each linking page, `path`, `title`, `study_date`, `updated` and `kinds` (`link`, `prior` or both), the latest first by study date, else last change, under the visibility predicate. The page's backlinks panel (title, date, how it links; past ten folded) lists both kinds: a follow-up report names the one it follows among its `priors`.

### Full-text

```
CREATE VIRTUAL TABLE fts USING fts5(
  title, summary, body, tags,
  tokenize="unicode61 remove_diacritics 2"
);
```

> **D5:`remove_diacritics 2` is not optional**. Romanian clinical text is written with and without diacritics by the same person on the same day. `coledocolitiaza` must match `coledocolitiază`. This is *not* a contentless (`content=''`) table: that mode refuses plain `DELETE`/`UPDATE`: only its special `'delete'` command, which requires supplying back the exact original column values, and nothing in this schema caches those, so a per-row update would have no safe way to remove the stale entry. Letting fts5 keep its own copy of the indexed text costs little at this scale, keeps `snippet()`/`highlight()` working (they need that text present), and the index stays exactly as derived and disposable as before: `index:rebuild` still reproduces it byte-for-byte from disk.

### Vectors

**Built 2026-10-08 (roadmap phase 34e, `migrations/index/005_page_vectors.sql`; since D54 step 2, 2026-10-20, its own file `data/vectors.sqlite`, `migrations/vectors/001_init.sql`, attached to the index's connection as `vec`, an older index's rows copied there by `Storage\Databases`): *Similar reports* only; search stays FTS5 + synonyms (D28).** No sqlite vector extension: one plain table, one vector per report.

```
CREATE TABLE page_vectors (
  pid    TEXT PRIMARY KEY,
  model  TEXT NOT NULL,       -- the embedding model named in Admin → AI
  sha    TEXT NOT NULL,       -- sha256(model + text embedded): unchanged → not asked again
  dim    INTEGER NOT NULL,
  vec    BLOB NOT NULL        -- dim × float32, little-endian, unit length
);
```

- **What is embedded:** `Service\Ai\Context::forEmbedding()`: the exam title and the conclusion section(s) (`Support\Conclusion`), else `summary`, else the text without the name heading; de-identified like a prompt (D15), ≤ 2 000 characters; a text still holding an identifier is not sent. **One model for the instance** (`ai.embed_server`, `ai.embed_model`, FORMATS §3d), through that server's `POST /embeddings`, its own address, key and egress rule.
- **Filled by `index:vectors`** (`Service\Maintenance\VectorsTask`; also Admin → Maintenance and `index:rebuild --vectors`), in batches of 16, never on a save's request path; a page whose `sha` matches is `current`, vectors of pages that are gone are dropped; `--force` (CLI only) asks for every report again, the `current` ones too. Reports of every status. A batch the server rejects (HTTP 4xx, e.g. a text too long for the model) is retried a text at a time: only what it still rejects is `failed`, asked again next run; a server that stops answering stops the run. A text still holding an identifier after redaction is `withheld` (counted apart from `no_text`), never sent.
- **Not derived per page by `index()`:** no foreign key to `pages`, so a `rebuild()` keeps the vectors (a matching `sha` still says they are right) and `index:vectors` after it asks only for what changed; `remove()` deletes a page's vector. Deleting `index.sqlite` loses nothing but the time to embed again (invariant 1).
- **Query:`Sqlite::similar($pid, $model, $principal)`:** the candidates are picked by `visibilityClause()` first (invariant 6), other patients only (`patient_key`/`patient_key_weak` differ), report paths only (a page moved out of `reports:` drops out before `index:vectors` removes its vector); cosine = dot product of unit vectors, brute force in PHP, top 10 at or above `$minScore` (`ai.embed_min_score`, `Embedder::minScore()`, default 0.5: how near "near" is depends on the model).
- **Normal reports are left out** (they all read alike and would crowd out the rest): tagged `normal`: read live from `page_tags`, so a save counts at once, or a stock conclusion, the same `sha` on at least `ai.embed_common_min` vectors (default 5; 0: off), counted in the query. Neither is a candidate, and neither gets a list (`Sqlite::similarExcluded()`). No column: both are derived at query time from what the index already holds.
- **Staleness:** a save does not re-embed. `Embedder::vectorState()` compares the stored `sha` with the report's text as it is now; the page view says when they differ, until the next `index:vectors`.

### Visibility inside the query

Multi-user (D35–D37, superseding the old D6/D13 single-user design): `visibility` still controls
anonymous/public reachability, and a second axis: a per-namespace grant: controls what an
authenticated non-owner can reach beyond that. Both are resolved in the same query, never after.

| visibility | `owner` | `editor` grant on this ns | `viewer` grant on this ns | authenticated, no grant here | anonymous |
|---|---|---|---|---|---|
| `private` | read + write | read + write | read | 404 | 404 |
| `unlisted` | read + write | read + write | read | 404 unless exact path/token | read, with the exact path or a share token |
| `public` | read + write | read + write | read | read | read |

*Table 2: the whole access model. The rightmost two columns describe the anonymous/public-facing
surfaces (the public tree, the sitemap, an anonymous search); a user with a namespace grant sees
`private`/`unlisted` pages **inside that namespace** in every listing, not just by direct link
a grant is ordinary staff access, not a token. `owner`'s own listing is unfiltered.*

The dangerous failure mode is a search that reveals a private report's title or snippet to
someone not entitled to it, so the filter is a predicate in the SQL, applied in one place, that
takes the caller's principal (their grants, if any) as an argument:

```
-- Search\Query::visibilityClause($principal): actual shape, as built
$sql .= $principal?->isOwner === true
  ? ''                                                          -- owner: everything
  : " AND (p.visibility = 'public'
           OR p.ns = :grant_ns_0 OR p.ns LIKE :grant_ns_prefix_0 ESCAPE '\\'
           OR p.ns = :grant_ns_1 OR p.ns LIKE :grant_ns_prefix_1 ESCAPE '\\'
           ...)";                                                -- one pair of params per grant
-- unlisted with no grant is reachable by direct path/token resolution, never by listing
```

> **D35–D37: multi-user, namespace-grant ACL**:`data/users/{username}.json` is the
> disk-authoritative account+grant store (invariant 1); there is deliberately **no `user_grants`
> table in `index.sqlite`**. A signed-in principal's grants are already fully resolved in PHP
> before the query runs (`Session::principal()` reads them straight from
> `data/users/{username}.json`), so the SQL predicate takes them as bound parameters instead of
> joining a cache of them: simpler than a join, and it means `index:rebuild` never touches
> `data/users/` at all, because there is nothing about accounts for it to lose. A grant's
> `namespace` is matched by exact equality or a `LIKE ... ESCAPE` prefix against `p.ns` (escaped,
> because `_` and `%` are valid namespace characters and both are SQL `LIKE` wildcards), reusing
> the colon-hierarchy pages already have: no separate inheritance model. `owner` is instance-wide
> and never namespace-scoped. This replaces the single-user D6 design (kept, struck through, in
> `docs/DECISIONS.md` for history).
>
> **D47 (2026-10-13): groups and radiologists.** `data/groups/{name}.json` holds named sets of
> grants; an account's record lists the groups it belongs to and `FlatFileUserStore` resolves them
> on read, so the principal the query sees already carries own ∪ groups' ∪ personal (`users:{u}`)
> grants: still bound parameters, still nothing in the index. An account that is not a radiologist
> has its grants on `reports`/`reports:*` dropped in `User::effectiveGrants()`, so the one predicate
> hides private reports from it without a special case.

> ⚠︎ **Kept from the original multi-user design.** Private pages still get an audit line on read,
> identifying the reading principal (`username`, or `anonymous` + a truncated token hash: never
> the raw token, D1-style). Not to police anyone: to answer "who read this" if a share token
> leaks or a grant looks wrong in hindsight.

### Consistency with disk

Three mechanisms, in increasing cost:

| Mechanism | Trigger | Cost at 4k pages | Detects |
|---|---|---|---|
| incremental | every save, move, delete | < 5 ms | nothing; it is the normal path |
| `index:verify` | cron, nightly | ~1 s (stat only) | mtime/size/hash drift, orphan rows, missing pages |
| `index:rebuild` | manual, or version bump | ~40 s (with embeddings, minutes) | everything; it is the ground truth (reads `SELECT pid, path FROM page WHERE trashed_at IS NULL` and opens each page file, D54) |

*Table 1: index consistency mechanisms.*

`integrity:verify` (D54, 2026-10-20) reads each zip, page file and state row against the others. Codes: `rev_missing`, `rev_corrupt`, `rev_changed`, `rev_extra`, `chain_broken`, `chain_missing`, `signature_orphan`, `signature_mismatch`, `current_stale` (the page file is not the newest revision), `mirror_drift` (state rows disagree with the zip: `bin/reporion db:recover`), `state_corrupt` (SQLite integrity or foreign-key check), `media_missing`, `media_changed`, `path_drift`, `duplicate_pid`, `stray_files`, `page_file_missing` (a row whose file is gone), `attic_orphan` (a zip no row has), `journal_open`, `index_*`; `--backup=<dir>` reads `<dir>/attic/`. `db:recover [--dry-run] [--json]` (`Storage\StateRecovery`) adds a row for every zip that has none and puts path and mirror right for the rest (a trashed page's path from `trash/{pid}/path`); it never removes a row and counts conflicts; what only the database held (comments, media names, moves and redirects, deliveries, drafts) does not come back. `doctor` fails when `reporion.sqlite` is missing while pages exist (it never creates one) or fails `PRAGMA quick_check`. The 1.x to 2.0 converter is `php tools/migrate-v2.php --from=data --to=data.v2` (docs/architecture-v2.md §11).

The index carries a `schema_version`; a mismatch on boot triggers a rebuild automatically. That is the escape hatch that lets you change the schema fearlessly, which matters a lot for a system you will extend for years.

## 7. Metadata schemas

One declarative file per modality under `resources/schema/`, and it drives four things at once: the
editor's metadata form, validation for signing (D7: required blocks signing, never saving; the
"validation on save" phrasing this section used to have was itself wrong), which columns exist in
`pages`, and which facets appear in search.

> **Implementation status.** Only one of those four is built: `Schema\Loader` +
> `Schema\Validator::missingForSign()` load `resources/schema/*.json` (one-level `extends` only),
> resolve the union of every listed `modality`'s fields (D29: modality is a list: a combined
> CT+MR study must satisfy both modalities' `required`/`required_for: ["sign"]` fields, not just
> one), and report which are missing or empty. Nothing yet generates the editor's metadata form
> from this schema, derives `pages` columns from `indexed: true`, or drives search facets: those
> three remain exactly as unbuilt as before this section's validator landed.

```
// resources/schema/mr.json
{ "extends": "base",
  "fields": {
    "region":   { "type": "enum", "required": true, "indexed": true,
                  "values": ["neuro","spine","msk","abdomen","pelvis","cardiac","orbits"] },
    "device":   { "type": "ref",  "required": true, "indexed": true, "ref": "devices" },
    "contrast": { "type": "enum", "values": ["none","gadoteric","gadobutrol"] },
    "summary":  { "type": "text", "indexed": true, "generated": "ai",
                  "required_for": ["sign"] }
  } }
```

> **D7: validation has two severities**:`required` blocks signing, never saving. A radiologist mid-dictation must always be able to save a half-finished draft; the system's job is to refuse to let that draft become a signed document. This single rule prevents the most common way clinical software becomes hated.

## 8. Audit and the AI boundary

Audit is append-only NDJSON, one file per month, outside SQLite, because an audit log that lives in a rebuildable cache is not an audit log. Every read of a non-public page, every write, every export, every AI call, every token use: `ts, actor, action, pid, path_hash, ip, ua, scope, outcome`. (Built: writes, exports and logins:`Audit\AuditLog`, docs/FORMATS.md §6; reads not yet.)

AI calls are subject to a single chokepoint, `Ai\Context::build()`, which is the only code allowed to assemble a prompt. It strips `patient`, redacts name-shaped strings in the body, and records the exact context set (this page, N priors, protocol; for the timeline's `evolution`, the patient's other readable reports:`{history}`, at most 8, de-identified, tagged only by date and exam) in the audit line, with the server and model used. No provider ships enabled (D15); egress is enforced in code, not documentation (`Service\Ai\EgressGuard`): a server outside this machine and the private network is refused until the owner accepts that de-identified report text may leave (`ai.external_ack`, Admin → AI; the host allow-list that also stood here was dropped 2026-09-27).

> **D8: generated text is never silently authoritative**. An AI-produced `summary` or draft section is written as a normal revision attributed to `assistant`, visible in history (the mockup shows this). It can be edited, it can be signed by a human, and it can never be the signer. The audit trail distinguishes "the machine wrote this" from "a doctor accepted this".

## 9. What the plugin layer may touch

Plugins get events and services, never the filesystem. The contract is deliberately narrow, because every hook you publish is one you must keep working for years.

| Hook | When | May |
|---|---|---|
| `page.validate` | before write | add errors/warnings |
| `page.save` | after commit | side effects, queue work |
| `page.render` | markdown → HTML | register macros, transform AST |
| `page.sign` | before/after signing | veto, notify |
| `index.fields` | index build | contribute columns/rows |
| `search.query` | before/after query | rewrite query, re-rank |
| `export.<fmt>` | export | own a format end to end |
| `ai.context` / `ai.complete` | AI call | shape context, swap provider |
| `auth.login` | sign-in | own authentication |
| `cron` | scheduled | anything it is allowed to |

*Table 3: hook surface, first cut.*

> **D9: storage and index are drivers, not hooks**:`StorageInterface` and `IndexInterface` are swappable implementations chosen in config (flat-file + SQLite today; S3 or Postgres conceivable later). They are not plugin territory: a plugin that can reach past the service layer into files is a plugin that can corrupt history.

## 10. Decisions, resolved

- **D10: decided: content-addressed, page-local view.** Bytes live once in `data/media/<aa>/<bb>/<sha256>.<ext>`; each page carries a manifest mapping human names to hashes (the `media` table of `reporion.sqlite` since D54, 2026-10-20), so a key slice reused in a teaching case is stored once and the page still shows sensible filenames. Refcounted; an unreferenced blob is swept after 30 days.

- **D11: decided: CNP when known, hash fallback otherwise.** `patient.cnp` is an optional frontmatter field; when present, `patient_key = sha256(cnp)` and the timeline is exact. When absent: the normal case: the key is `sha256(normalise(name) | born | sex)`, diacritic-folded and space-collapsed. Because the two coexist, the index stores **both** keys per page (`patient_key`, `patient_key_weak`) and the timeline groups on the strong key when available, falling back to the weak one. Entering a CNP later on any one report of a patient retroactively links the set at next index pass, which makes filling it in worthwhile without ever being mandatory. Near-key "possible matches" surface marriage names and typos on the patient tab (`Index::findPossiblePatientMatches()`); confirming one (2026-09-28, TODO 13) writes an explicit `patient.key` frontmatter override onto the target page, set to the source's own key:`Index\Sqlite::write()` prefers it over the cnp-derived value. No separate `conf/patient_merges.json`: the override is on the page itself, disk-authoritative per invariant 1, and undone by deleting the field.

- **D12: decided: one instance for all hospitals over VPN.** Single `data/` tree, single index, `site` as a first-class indexed field: now purely a facet, not an access dimension. Consequence to design for: the VPN is a hard dependency for reporting, so the editor must autosave locally and survive a dropped link.

- **D13: superseded by D35.** ~~Personal system, one user.~~ Reporion is for a small team.
  `data/users/{username}.json` holds one record per account (argon2id hash, role, namespace
  grants); a long-lived signed session cookie, no 2FA, no self-service registration: accounts
  are created by an `owner` (CLI or admin screen), never a public signup form. Anonymous visitors
  are unchanged: read-only, `public` pages only. `auth.login` remains a hook so LDAP is still a
  later plugin rather than a rewrite.

- **D14: superseded by D37.** ~~The owner signs their own reports.~~ Whoever holds `editor` (or
  `owner`) on a page's namespace signs that page as themselves: same no-review-step spirit,
  generalised past "the owner" being the only person who could ever write. `page.sign` stays a
  hook so a veto could be introduced later; no review state is modelled and no screen exists for
  it.

- **D35/D36/D37: decided: namespace-grant ACL on top of visibility, disk-authoritative.** See
  §"Visibility inside the query" above for the full model and the `visibilityClause($principal)`
  predicate; `docs/DECISIONS.md` has the compressed why.

- **D15: decided: AI provider interface, nothing enabled.** `Ai\ProviderInterface` plus the `Ai\Context::build()` chokepoint ship in core; no provider is configured, the assistant rail is hidden when none is, and nothing leaves the private network without the owner's acknowledgement. This is the cheapest decision to defer: provided the chokepoint exists from day one, so that enabling a provider later cannot bypass identifier stripping.

- **D17: decided: one PHP parser for everything, the editor preview included. Amended 2026-10-15.** Originally marked.js in the browser for the preview, bounded by a pinned dialect and a conformance test; dropped at the owner's request, with `assets/marked.js`, `package.json` and `RenderConformanceTest`. The dialect is **generic CommonMark plus tables plus definition lists** (`Term` line, then `: definition` lines, league/commonmark's DescriptionList extension, enabled in `Service\Render`): no footnotes, no macros. The preview, the assistant's `show` answers and the AI Evolution panel call `POST /api/v1/render` (`assets/js/render-client.js`).

- **D18: decided: prose only, no structured findings.** No measurement macros, no per-finding fields. Consequence to accept deliberately: the timeline and compare screens summarise and diff *text*, they cannot chart lesion counts over time. The escape hatch that costs nothing today is the `summary` frontmatter field: short, indexed, and the natural place a future extraction pass would write structured values without touching the body.

- **D19: decided: existing templates were imported as-is.** No inheritance, no base→modality→protocol composition. Templates are pages under `templates:` whose body is the customised text you already use; "new report" copies one. The template system is therefore *zero code* beyond copy-on-create. (The import tool that brought them in was removed 2026-10-09.)

- **D20: decided: Reporion generates accession numbers.** Pattern `{SITE}-{MOD}-{yy}-{seq}`, e.g. `MV-RM-26-0918`, with `seq` a per-site, per-modality, per-year counter (amended 2026-09-25 from per-site-per-year, to match the numbers the import already issued) held in the `counter` table of `data/reporion.sqlite` (was `data/counters.json` until D54, 2026-10-20) and allocated inside the same journal-protected write that creates the page (so two fast creations cannot collide). The field stays editable, when a real accession exists on the request form, typing it over the generated one is a normal metadata edit. **Built:** the importer (`Import\AccessionAllocator`, per-batch counters in `data/import/<batch>/counters.json`) and, since phase 7, the new-report form (`Service\Accessions`, the `counter` table): both seeded from the highest seq already present in any page's `accession:` frontmatter (`Support\AccessionFormat`), so neither ever reissues a number. The live number is allocated under a lock just before the create rather than inside its journal window: a crash leaves a gap, never a duplicate (docs/FORMATS.md §3). **A multi-exam report (phase 12) holds one accession per exam** in `exams[].accession`; the seed reads `page_exams` as well as `pages.accession`, so the 2nd and 3rd exam's numbers are never issued again. A search for an accession typed whole finds the report by any of its exams' numbers, first, under the same `visibilityClause()`.

- **D21: decided: app-managed history, not git.** The attic zip (`attic/{ns…}/{slug}.zip`: one entry per revision plus `revlog.json`) is the history (was `rev/NNNN.md.gz` plus `meta.json`'s `revlog`, until D54, 2026-10-20); no git repository, no auto-commit. Backup is `rsync` to another machine, and because the store is plain files that is a complete backup: no dump step, no consistency window beyond the atomic rename.

- **D22: decided: rsync, unencrypted at rest.** Recorded as your decision. What I would still do, because it is nearly free: run rsync over SSH (transport is then encrypted regardless), keep the backup target on a full-disk-encrypted volume, and use `--link-dest` dated snapshots so a bad sync or a mistaken purge is recoverable rather than propagated. A backup that faithfully mirrors a deletion is not a backup.

- **D23: decided: personal server, lighttpd, TLS already handled.** PHP floor is **8.1** since both runtimes are available: enums, readonly properties and `never` are worth having, and 7.4 is long past security support. lighttpd has no `.htaccess`, so two config requirements are absolute: `data/`, `conf/` and `plugins/` live *outside* the document root, and every request rewrites to `public/index.php`. Both go in `docs/deploy-lighttpd.md` with a working `server.modules` snippet.

- **D24: decided: no dictation.** Romanian speech recognition is not good enough to be worth the subsystem. The editor compensates with expansion macros (`;norm`, `;ctrl12`): stored as pages under `templates:snippets:` (amended 2026-09-26, phase 11: shared ones, plus `templates:snippets:{modality ns}:` for that modality's reports), not in settings, which is also the piece that makes an external dictation program typing into the textarea work fine, so nothing here blocks adding one later.

- **D25: decided: survive drops, do not go fully offline.** IndexedDB draft keyed by pid + base_rev, best-effort autosave with retry, explicit "unsaved: reconnecting" state, and the 409 conflict flow on return. No service worker, no offline read cache, no sync engine. Amended 2026-10-12: a server-side autosave draft as well (`saveDraft`/`readDraft`/`discardDraft`), never a revision. **Amended by D54 (2026-10-20):** the draft is a row of `reporion.sqlite` (`draft`, one per page and user), not a file in the page directory.

- **D26: decided: English interface.** Chrome, admin and error messages in English; report content Romanian. No i18n layer yet, but no hard-coded strings in templates either: they come from one `lang/en.php` map, so adding `ro.php` later is a translation job rather than a refactor.

- **D27: decided: media in `data/media/`, images by paste and drag.** Simplified from D10: a flat per-year directory (`data/media/2026/<sha256>.<ext>`) rather than a two-level hash fan-out, since a personal archive will not exceed a few thousand files. Clipboard paste and file drag in the editor; no PACS C-GET, no DICOM ingest: those become plugins if you ever want them.

- **D28: decided: synonym-driven search, no stemmer.** SQLite ships no Romanian stemmer, so recall comes from a synonym table applied at *query* time (`hernie` → `hernie OR hernia OR herniar*`), seeded from the tag synonyms you already maintain, plus prefix matching on the last token. This is better than a stemmer for clinical Romanian anyway, because the vocabulary is small, closed and yours.

### Exports at launch

Core: **PDF with per-site letterhead**, **ODT**, **plain markdown**. Each is an `export.<fmt>` implementation, so DICOM SR (built, in `plugins/dicom`), bulk result-set export and expiring share links are later plugins with no core change. PDF and ODT both render from the same server-side HTML the page uses, not from a second template, so the signed document, the printed sheet and the screen cannot drift.

## 11. What Claude Code should build first

- `Storage\FileTree` (was `FlatFile`, D54): read, write, revisions in the attic zip, atomic rename, journal replay. With tests that kill the process mid-write.

- `Index\Sqlite`: schema, incremental upsert, `verify`, `rebuild`. Rebuild must be correct before anything else is built on top.

- `Acl`: ~~resolution order, `page_read` materialisation~~. Collapsed by D13 to `Visibility`: one predicate, one place, plus a test suite asserting a private page is invisible to an anonymous caller in search, tree, sitemap and API alike.

- `Render`: markdown + macro pipeline, server-only, with the export path calling the same code.

- The API surface for the read path, then the editor's write path.

Screens come last, ported from the mockup one at a time. By then the three interesting bugs in this design will have surfaced, and the spec will be wrong in ways we can only learn by running it.
