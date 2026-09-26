# Storage & index model

Reporion — a flat-file wiki for imaging reports. PHP 8.x, on-disk pages, SQLite index.

*draft 4 · 23 Sep 2026 · D1–D28 decided, D35–D37 supersede D6/D13/D14 · multi-user, small-team deployment*

The disk is the database. Every page is a directory of plain text that a radiologist could read with `cat` in twenty years, and the SQLite index is a disposable cache that can be deleted at any moment and rebuilt from those files. Everything below follows from that one commitment.

## 1. Principles

- **Disk is authoritative, index is derived.** If the two disagree, the disk wins and the index is wrong. No feature may store its only copy of anything in SQLite.

- **A page is a directory, not a row.** Body, history, metadata and attachments for one report live together and move together.

- **Append-only history.** Revisions are never rewritten or deleted; a signed revision is immutable by construction.

- **Canonical rendering is server-side.** The page view, the PDF, the ODT and the index all render through `Render::toHtml()`. The editor's live preview is allowed to use marked.js in the browser for latency (D17) — but it is a preview, never the artefact, and a conformance test keeps the two parsers honest.

- **Identity survives renaming.** Every page carries an immutable id; paths are labels.

- **The index never leaks.** Visibility is resolved inside every query, not applied to its results — an unlisted or private page must not surface in a public search, ever.

## 2. Page identity and paths

A page has two names. The **path** is what people type and see — `reports:mri:mioveni:260922-ionescu-maria`, colon-separated, mapping directly to directories. The **pid** is a 26-character ULID minted at creation, stored in `meta.json`, and never changed by anything, including a move between hospitals.

#### Slug rules

Path segments are normalised: NFKD-folded to ASCII (`Ionescu Mária` → `ionescu-maria`), lowercased, non-alphanumerics collapsed to a single hyphen, trimmed to 64 characters. Reserved segment prefixes: `_` for namespace-level pages (`_index`, `_template`, `_acl`), so a report can never collide with machinery. Reserved namespace roots: `reports`, `protocols`, `templates`, `teaching`, `archive`, `trash`.

> **D1 — decided: keep the patient name in the path** — `{yymmdd}-{name}`, because it is the single biggest driver of day-to-day speed. The compensating rules are now requirements, not suggestions: the path never appears in an export, an externally shared URL, a log line, an audit entry (which stores `path_hash`) or an AI prompt — those all use the pid. The pattern still comes from site settings and no code may assume which one is in force.

#### Moves and redirects

A move rewrites the directory location, records the move in `meta.json`'s `moves` list (`{from, to, ts, by}` — not in the revision log: a move is not a revision, and a revlog entry would duplicate a rev number), and writes a redirect stub at the old path: a directory containing only `redirect` (one line, the new path). Stubs are excluded from the tree and the index but resolve on request, so every link, bookmark and `priors:` reference in another report keeps working forever. Redirect chains collapse at write time, never at read time.

## 3. On-disk layout

```
data/
├─ pages/
│  └─ reports/mri/mioveni/260922-ionescu-maria/
│     ├─ current.md            latest body + frontmatter (authoritative)
│     ├─ meta.json             system state: pid, acl, revlog, signatures
│     ├─ rev/
│     │  ├─ 0001.md.gz         immutable, append-only
│     │  ├─ …
│     │  └─ 0007.md.gz         == current.md, gzipped
│     └─ media/                page-local attachments, key slices
├─ media/                      shared media, addressed by content hash
│  └─ 8f/2c/8f2c41…e9.jpg
├─ index.sqlite                derived — deletable
├─ index.sqlite-wal
├─ journal/
│  └─ 2026-09-22.ndjson        write-intent log for crash recovery
├─ audit/
│  └─ 2026-09.ndjson           reads/writes of non-public pages
├─ trash/
│  └─ 260904-olaru-x.8f2c41/   soft-deleted page dirs, purged by cron
└─ conf/
   ├─ local.php                site settings
   ├─ users.json               users, groups, password hashes, TOTP secrets
   └─ schema/                  per-modality metadata schemas (§7)
```

> **D2 — why `current.md` duplicates the newest revision** — Reading a report is the most common operation in the system and must never require gunzip. `current.md` is a plain-text fast path; `rev/NNNN.md.gz` is the archival copy. They are written in one transaction and verified by checksum during `index:verify`. Cost: ~2× storage on the newest revision, which at 2.4 kB a report is nothing.

## 4. File formats

### current.md

YAML frontmatter followed by markdown. Frontmatter is **editorial** metadata — what a radiologist would consider part of the document. It is written by humans and by the HL7 bridge, and it is what the schema in §7 validates.

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
referrer: "dr. C. Neagu — Neurologie"
indication: "Parestezii membre inferioare, SM cunoscută — control"
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

`indication` is the reason for examination (the referring diagnosis). The modality schemas make it
`required_for: sign`; it is shown in the page's metadata panel and printed as the *Indicație* row of
the report header. An imported report keeps its original `Indicație` sentence in the body (D31) and
has no `indication` field until someone fills it in, so nothing is printed twice.

### meta.json

**System** state — what the application knows about the page and a human would not type. Kept out of the markdown so that frontmatter stays legible and diffs stay meaningful.

```
{
  "pid": "01JB8X4MT7QK2V9Z0C3R5H6ND",
  "created": "2026-09-22T09:14:02+03:00",
  "created_by": "svc-hl7-bridge",
  "path": "reports:mri:mioveni:260922-ionescu-maria",
  "rev": 7,
  "revlog": [
    { "n": 7, "ts": "2026-09-22T09:41:11+03:00", "by": "a.barbu",
      "note": "corectat numărul de leziuni juxtacorticale",
      "bytes": 2412, "sha256": "…", "minor": false, "kind": "edit" }
  ],
  "signatures": [
    { "rev": 6, "by": "a.barbu", "ts": "…", "alg": "sha256",
      "digest": "…", "parafa": "AG-04127" }
  ],
  "status": "draft",
  "visibility": "private",
  "share_token": null,
  "locks": null
}
```

> ⚠︎ **Invariant.** `meta.json` is rewritten atomically (temp file + `rename()`) and is the only mutable file in a page directory besides `current.md`. Anything appended to `revlog` or `signatures` is never edited afterwards.

## 5. Revisions, signing and amendment

### The write path

- Validate frontmatter against the modality schema (§7). Hard errors block the save; soft warnings are returned to the editor.

- Append a write-intent line to `journal/` — pid, target rev, body hash. This is what makes step 3–6 recoverable.

- Write `rev/000N.md.gz`, `fsync`. If this file already exists, the save is a duplicate submission and is idempotently ignored.

- Write `current.md.tmp`, `fsync`, `rename()` over `current.md`.

- Append to `revlog`, rewrite `meta.json` atomically.

- Index in one SQLite transaction (§6). Mark the journal line done.

A crash between 3 and 6 leaves an open journal line; the first request more than a minute later replays it (or `bin/reporion journal:replay`, docs/FORMATS.md §2). A crash before 3 leaves nothing. There is no state in which a revision exists but the reader sees a partial file.

### Revert

`Storage::revert($path, $toRev, $actor)` is a second entry into this exact same write path — same
journal intent (`op: "revert"`), same `rev/000N.md.gz` + `current.md` sequence, same crash-recovery
replay — with one difference: the bytes it writes as revision N+1 are revision `$toRev`'s own bytes,
read back off disk and replayed verbatim, never re-encoded from a caller-supplied frontmatter/body.
That is what makes A2 ("revision 8's content equals revision 6's") literally, byte-for-byte true
rather than true only after normalisation. It is its own `StorageInterface` method, not a `$kind`
parameter on `save()`, because the caller supplies a revision number, never content — `save()`'s
contract is "here is the new content," revert's is "make an old revision current again," and
collapsing those into one method would let a caller claim to be "reverting" while actually
supplying arbitrary bytes. No `base_rev` conflict check either: `save()`'s exists to protect
caller-supplied content the caller might not have seen change; revert never makes that claim, so
there is nothing to protect against — it always appends `$toRev`'s content forward, regardless of
what happened in between. `status` is never carried forward from the reverted revision — reverting
a `signed` page produces a fresh `draft` (D3: it needs signing again, in its own right).

### Signing

Signing computes `sha256` over the canonical bytes of that revision (LF line endings, frontmatter key order normalised) and appends a signature record. From then on, that revision is legally the report.

> **D3 — decided: correction by re-signing, not by addendum** — Editing a signed report produces a new revision which is then signed in its own right, and that new revision becomes the current document. No addendum block is rendered. The superseded signed revision keeps its signature record in `meta.json` and stays readable in history forever, so "what did the referrer receive on 22 Sep" is always answerable. Status stays `signed`; `revlog[].kind` records `resign`, and the history screen marks which signed revision was current at any date.

> ⚠︎ **Consequence worth stating plainly.** A PDF already sent to a referrer may no longer match the current page. Each export therefore embeds the revision number and a verification URL (`/r/<pid>/<rev>`) that renders exactly those signed bytes — that link, not the page, is the citable document.

> **D3b — no deletion of signed content** — A signed revision cannot be deleted, only superseded. Page deletion moves the directory to `trash/` with a 30-day purge; purging a page with signatures requires an explicit admin override and writes an audit entry naming the operator. Not configurable.

### Concurrency

Optimistic, not locked. The editor submits the rev it started from; if the current rev is higher, the API returns `409` with both bodies and a section-level three-way merge suggestion (the mockup's conflict state). A soft advisory lock is written into `meta.json.locks` when an editor opens a page, expires after 15 minutes, and only produces a warning banner — never a hard block, because a radiologist with a locked page and a waiting patient will simply lose trust in the tool.

## 6. The SQLite index

One file, `data/index.sqlite`, WAL mode, `busy_timeout=5000`, `synchronous=NORMAL`. It exists to answer three questions fast: *what pages are there*, *which ones match this text*, and *which ones is this user allowed to see*. Nothing else belongs in it.

### Core tables

```
CREATE TABLE pages (
  pid              TEXT PRIMARY KEY,     -- ULID, from meta.json
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

-- modality and region are LISTS, not scalars (D29) — a combined CT
-- cerebral + cervical study needs both values indexed, and a facet must
-- count a page once per value:
CREATE TABLE page_modalities (pid TEXT NOT NULL, modality TEXT NOT NULL, PRIMARY KEY (pid, modality));
CREATE TABLE page_regions    (pid TEXT NOT NULL, region   TEXT NOT NULL, PRIMARY KEY (pid, region));
```

> This is the illustrative shape; `migrations/001_init.sql` is the only place the schema is
> actually defined (per the working agreement: when a doc and the code disagree, the doc is
> wrong). It also carries `tags`/`page_tags`, `links`, a `revisions` mirror, `redirects`, the
> `import_review` queue and `schema_meta.schema_version` — see that file for the full DDL.

> **D4 — typed columns for the facet set, JSON for the tail** — Fields you filter or sort on get real columns; everything else lives in `meta_json` and is reachable with SQLite's JSON functions when needed. Promoting a field from the tail to a column is a migration plus a reindex — cheap, and the schema file in §7 declares which fields are promoted, so the migration is generated rather than hand-written. The alternative, a generic key/value table, makes every faceted query a pile of self-joins; at 10k reports you would feel it.

#### Tags, links, revisions

```
CREATE TABLE tags      (tag TEXT PRIMARY KEY, grp TEXT, icd10 TEXT,
                        canonical TEXT);       -- synonym → canonical
CREATE TABLE page_tags (pid TEXT, tag TEXT, PRIMARY KEY (pid, tag));
CREATE TABLE links     (src TEXT, dst_path TEXT, dst_pid TEXT,
                        kind TEXT);            -- link|prior|template|protocol
CREATE TABLE revisions (pid TEXT, n INTEGER, ts TEXT, by TEXT, note TEXT,
                        bytes INTEGER, kind TEXT, PRIMARY KEY (pid, n));
```

`links.dst_pid` is null for a target that does not exist yet — that is exactly the broken-links report, and it is a single `WHERE dst_pid IS NULL`.

### Full-text

```
CREATE VIRTUAL TABLE fts USING fts5(
  title, summary, body, tags,
  tokenize="unicode61 remove_diacritics 2"
);
```

> **D5 — `remove_diacritics 2` is not optional** — Romanian clinical text is written with and without diacritics by the same person on the same day. `coledocolitiaza` must match `coledocolitiază`. This is *not* a contentless (`content=''`) table: that mode refuses plain `DELETE`/`UPDATE` — only its special `'delete'` command, which requires supplying back the exact original column values, and nothing in this schema caches those — so a per-row update would have no safe way to remove the stale entry. Letting fts5 keep its own copy of the indexed text costs little at this scale, keeps `snippet()`/`highlight()` working (they need that text present), and the index stays exactly as derived and disposable as before: `index:rebuild` still reproduces it byte-for-byte from disk.

### Vectors

```
CREATE VIRTUAL TABLE vec USING vec0(
  pid TEXT PRIMARY KEY,
  chunk INTEGER,
  embedding FLOAT[1024]          -- bge-m3, on-prem
);
```

Chunking is per section heading, not per fixed token count: a radiology report's sections are already the natural semantic units, and it means a hit can cite *Concluzie* rather than "characters 1400–1900". Ranking is `0.6 × bm25 + 0.4 × cosine`, both normalised per query; the weights live in site settings because you will want to tune them once you have real volume.

### Visibility inside the query

Multi-user (D35–D37, superseding the old D6/D13 single-user design): `visibility` still controls
anonymous/public reachability, and a second axis — a per-namespace grant — controls what an
authenticated non-owner can reach beyond that. Both are resolved in the same query, never after.

| visibility | `owner` | `editor` grant on this ns | `viewer` grant on this ns | authenticated, no grant here | anonymous |
|---|---|---|---|---|---|
| `private` | read + write | read + write | read | 404 | 404 |
| `unlisted` | read + write | read + write | read | 404 unless exact path/token | read, with the exact path or a share token |
| `public` | read + write | read + write | read | read | read |

*Table 2 — the whole access model. The rightmost two columns describe the anonymous/public-facing
surfaces (the public tree, the sitemap, an anonymous search); a user with a namespace grant sees
`private`/`unlisted` pages **inside that namespace** in every listing, not just by direct link —
a grant is ordinary staff access, not a token. `owner`'s own listing is unfiltered.*

The dangerous failure mode is a search that reveals a private report's title or snippet to
someone not entitled to it, so the filter is a predicate in the SQL, applied in one place, that
takes the caller's principal (their grants, if any) as an argument:

```
-- Search\Query::visibilityClause($principal) — actual shape, as built
$sql .= $principal?->isOwner === true
  ? ''                                                          -- owner: everything
  : " AND (p.visibility = 'public'
           OR p.ns = :grant_ns_0 OR p.ns LIKE :grant_ns_prefix_0 ESCAPE '\\'
           OR p.ns = :grant_ns_1 OR p.ns LIKE :grant_ns_prefix_1 ESCAPE '\\'
           ...)";                                                -- one pair of params per grant
-- unlisted with no grant is reachable by direct path/token resolution, never by listing
```

> **D35–D37 — multi-user, namespace-grant ACL** — `data/users/{username}.json` is the
> disk-authoritative account+grant store (invariant 1); there is deliberately **no `user_grants`
> table in `index.sqlite`**. A signed-in principal's grants are already fully resolved in PHP
> before the query runs (`Session::principal()` reads them straight from
> `data/users/{username}.json`), so the SQL predicate takes them as bound parameters instead of
> joining a cache of them — simpler than a join, and it means `index:rebuild` never touches
> `data/users/` at all, because there is nothing about accounts for it to lose. A grant's
> `namespace` is matched by exact equality or a `LIKE ... ESCAPE` prefix against `p.ns` (escaped,
> because `_` and `%` are valid namespace characters and both are SQL `LIKE` wildcards), reusing
> the colon-hierarchy pages already have — no separate inheritance model. `owner` is instance-wide
> and never namespace-scoped. This replaces the single-user D6 design (kept, struck through, in
> `docs/DECISIONS.md` for history).

> ⚠︎ **Kept from the original multi-user design.** Private pages still get an audit line on read,
> identifying the reading principal (`username`, or `anonymous` + a truncated token hash — never
> the raw token, D1-style). Not to police anyone — to answer "who read this" if a share token
> leaks or a grant looks wrong in hindsight.

### Consistency with disk

Three mechanisms, in increasing cost:

| Mechanism | Trigger | Cost at 4k pages | Detects |
|---|---|---|---|
| incremental | every save, move, delete | < 5 ms | nothing; it is the normal path |
| `index:verify` | cron, nightly | ~1 s (stat only) | mtime/size/hash drift, orphan rows, missing pages |
| `index:rebuild` | manual, or version bump | ~40 s (with embeddings, minutes) | everything; it is the ground truth |

*Table 1 — index consistency mechanisms.*

The index carries a `schema_version`; a mismatch on boot triggers a rebuild automatically. That is the escape hatch that lets you change the schema fearlessly — which matters a lot for a system you will extend for years.

## 7. Metadata schemas

One declarative file per modality under `conf/schema/`, and it drives four things at once: the
editor's metadata form, validation for signing (D7 — required blocks signing, never saving; the
"validation on save" phrasing this section used to have was itself wrong), which columns exist in
`pages`, and which facets appear in search.

> **Implementation status.** Only one of those four is built: `Schema\Loader` +
> `Schema\Validator::missingForSign()` load `conf/schema/*.json` (one-level `extends` only),
> resolve the union of every listed `modality`'s fields (D29: modality is a list — a combined
> CT+MR study must satisfy both modalities' `required`/`required_for: ["sign"]` fields, not just
> one), and report which are missing or empty. Nothing yet generates the editor's metadata form
> from this schema, derives `pages` columns from `indexed: true`, or drives search facets — those
> three remain exactly as unbuilt as before this section's validator landed.

```
// conf/schema/mr.json
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

> **D7 — validation has two severities** — `required` blocks signing, never saving. A radiologist mid-dictation must always be able to save a half-finished draft; the system's job is to refuse to let that draft become a signed document. This single rule prevents the most common way clinical software becomes hated.

## 8. Audit and the AI boundary

Audit is append-only NDJSON, one file per month, outside SQLite — because an audit log that lives in a rebuildable cache is not an audit log. Every read of a non-public page, every write, every export, every AI call, every token use: `ts, actor, action, pid, path_hash, ip, ua, scope, outcome`. (Built: writes, exports and logins — `Audit\AuditLog`, docs/FORMATS.md §6; reads not yet.)

AI calls are subject to a single chokepoint, `Ai\Context::build()`, which is the only code allowed to assemble a prompt. It strips `patient`, redacts name-shaped strings in the body, and records the exact context set (this page, N priors, protocol) in the audit line. No provider ships enabled (D15); the egress allow-list defaults to empty and is enforced in code, not documentation — any HTTP client instantiated outside the allow-list throws.

> **D8 — generated text is never silently authoritative** — An AI-produced `summary` or draft section is written as a normal revision attributed to `assistant`, visible in history (the mockup shows this). It can be edited, it can be signed by a human, and it can never be the signer. The audit trail distinguishes "the machine wrote this" from "a doctor accepted this".

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

*Table 3 — hook surface, first cut.*

> **D9 — storage and index are drivers, not hooks** — `StorageInterface` and `IndexInterface` are swappable implementations chosen in config (flat-file + SQLite today; S3 or Postgres conceivable later). They are not plugin territory: a plugin that can reach past the service layer into files is a plugin that can corrupt history.

## 10. Decisions, resolved

- **D10 — decided: content-addressed, page-local view.** Bytes live once in `data/media/<aa>/<bb>/<sha256>.<ext>`; each page directory carries a `media/` manifest mapping human names to hashes, so a key slice reused in a teaching case is stored once and a human browsing the page folder still sees sensible filenames. Refcounted; an unreferenced blob is swept after 30 days.

- **D11 — decided: CNP when known, hash fallback otherwise.** `patient.cnp` is an optional frontmatter field; when present, `patient_key = sha256(cnp)` and the timeline is exact. When absent — the normal case — the key is `sha256(normalise(name) | born | sex)`, diacritic-folded and space-collapsed. Because the two coexist, the index stores **both** keys per page (`patient_key`, `patient_key_weak`) and the timeline groups on the strong key when available, falling back to the weak one. Entering a CNP later on any one report of a patient retroactively links the set at next index pass — which makes filling it in worthwhile without ever being mandatory. Near-key "possible matches" and an admin-pinned `conf/patient_merges.json` stay, for marriage names and typos.

- **D12 — decided: one instance for all hospitals over VPN.** Single `data/` tree, single index, `site` as a first-class indexed field — now purely a facet, not an access dimension. Consequence to design for: the VPN is a hard dependency for reporting, so the editor must autosave locally and survive a dropped link.

- **D13 — superseded by D35.** ~~Personal system, one user.~~ Reporion is for a small team.
  `data/users/{username}.json` holds one record per account (argon2id hash, role, namespace
  grants); a long-lived signed session cookie, no 2FA, no self-service registration — accounts
  are created by an `owner` (CLI or admin screen), never a public signup form. Anonymous visitors
  are unchanged: read-only, `public` pages only. `auth.login` remains a hook so LDAP is still a
  later plugin rather than a rewrite.

- **D14 — superseded by D37.** ~~The owner signs their own reports.~~ Whoever holds `editor` (or
  `owner`) on a page's namespace signs that page as themselves — same no-review-step spirit,
  generalised past "the owner" being the only person who could ever write. `page.sign` stays a
  hook so a veto could be introduced later; no review state is modelled and no screen exists for
  it.

- **D35/D36/D37 — decided: namespace-grant ACL on top of visibility, disk-authoritative.** See
  §"Visibility inside the query" above for the full model and the `visibilityClause($principal)`
  predicate; `docs/DECISIONS.md` has the compressed why.

- **D15 — decided: AI provider interface, nothing enabled.** `Ai\ProviderInterface` plus the `Ai\Context::build()` chokepoint ship in core; no provider is configured, the assistant rail is hidden when none is, and the egress allow-list defaults to empty. This is the cheapest decision to defer — provided the chokepoint exists from day one, so that enabling a provider later cannot bypass identifier stripping.

- **D17 — decided: marked.js in the browser for preview, a PHP parser for everything canonical.** Two parsers is a real risk, so it is bounded three ways. (a) The dialect is **generic CommonMark plus tables** — no custom syntax, no footnotes, no macros, which is exactly the subset where marked.js and a PHP CommonMark implementation agree. (b) A conformance test renders a corpus of real reports plus a spec fixture set through both and fails the build on any difference in normalised HTML. (c) Anything the preview cannot render identically is simply not in the dialect. `POST /render` stays in the API for the print path, the index and any client that would rather not parse.

- **D18 — decided: prose only, no structured findings.** No measurement macros, no per-finding fields. Consequence to accept deliberately: the timeline and compare screens summarise and diff *text*, they cannot chart lesion counts over time. The escape hatch that costs nothing today is the `summary` frontmatter field — short, indexed, and the natural place a future extraction pass would write structured values without touching the body.

- **D19 — decided: existing templates are imported as-is.** No inheritance, no base→modality→protocol composition. Templates are pages under `templates:` whose body is the customised text you already use; "new report" copies one. The template system is therefore *zero code* beyond copy-on-create, and the import tool's job is to bring them in with their frontmatter defaults filled from the file they came from.

- **D20 — decided: Reporion generates accession numbers.** Pattern `{SITE}-{MOD}-{yy}-{seq}`, e.g. `MV-RM-26-0918`, with `seq` a per-site, per-modality, per-year counter (amended 2026-09-25 from per-site-per-year, to match the numbers the import already issued) held in `data/counters.json` and allocated inside the same journal-protected write that creates the page (so two fast creations cannot collide). The field stays editable — when a real accession exists on the request form, typing it over the generated one is a normal metadata edit. **Built so far: import only.** `Import\AccessionAllocator` keeps per-batch counters (`data/import/<batch>/counters.json`) and seeds each one from the highest seq already present in any page's `accession:` frontmatter, so batches never reissue a number; native `create()` does not allocate yet and `data/counters.json` does not exist.

- **D21 — decided: app-managed history, not git.** `rev/NNNN.md.gz` plus `revlog` is the history; no git repository, no auto-commit. Backup is `rsync` to another machine, and because the store is plain files that is a complete backup — no dump step, no consistency window beyond the atomic rename.

- **D22 — decided: rsync, unencrypted at rest.** Recorded as your decision. What I would still do, because it is nearly free: run rsync over SSH (transport is then encrypted regardless), keep the backup target on a full-disk-encrypted volume, and use `--link-dest` dated snapshots so a bad sync or a mistaken purge is recoverable rather than propagated. A backup that faithfully mirrors a deletion is not a backup.

- **D23 — decided: personal server, lighttpd, TLS already handled.** PHP floor is **8.1** since both runtimes are available — enums, readonly properties and `never` are worth having, and 7.4 is long past security support. lighttpd has no `.htaccess`, so two config requirements are absolute: `data/`, `conf/` and `plugins/` live *outside* the document root, and every request rewrites to `public/index.php`. Both go in `docs/deploy-lighttpd.md` with a working `server.modules` snippet.

- **D24 — decided: no dictation.** Romanian speech recognition is not good enough to be worth the subsystem. The editor compensates with expansion macros (`;norm`, `;ctrl12`) stored in settings — which is also the piece that makes an external dictation program typing into the textarea work fine, so nothing here blocks adding one later.

- **D25 — decided: survive drops, do not go fully offline.** IndexedDB draft keyed by pid + base_rev, best-effort autosave with retry, explicit "unsaved — reconnecting" state, and the 409 conflict flow on return. No service worker, no offline read cache, no sync engine.

- **D26 — decided: English interface.** Chrome, admin and error messages in English; report content Romanian. No i18n layer yet — but no hard-coded strings in templates either: they come from one `lang/en.php` map, so adding `ro.php` later is a translation job rather than a refactor.

- **D27 — decided: media in `data/media/`, images by paste and drag.** Simplified from D10: a flat per-year directory (`data/media/2026/<sha256>.<ext>`) rather than a two-level hash fan-out, since a personal archive will not exceed a few thousand files. Clipboard paste and file drag in the editor; no PACS C-GET, no DICOM ingest — those become plugins if you ever want them.

- **D28 — decided: synonym-driven search, no stemmer.** SQLite ships no Romanian stemmer, so recall comes from a synonym table applied at *query* time (`hernie` → `hernie OR hernia OR herniar*`), seeded from the tag synonyms you already maintain, plus prefix matching on the last token. This is better than a stemmer for clinical Romanian anyway, because the vocabulary is small, closed and yours.

### Exports at launch

Core: **PDF with per-site letterhead**, **ODT**, **plain markdown**. Each is an `export.<fmt>` implementation, so DICOM SR, bulk result-set export and expiring share links are later plugins with no core change. PDF and ODT both render from the same server-side HTML the page uses — not from a second template — so the signed document, the printed sheet and the screen cannot drift.

### Migration of the existing archive

Thousands of legacy reports in DOC, PDF and paper scans. This is a substantial subsystem and should be a **separate CLI tool writing through the normal storage API**, never a script that writes files directly — that way every imported page gets a pid, a revision, an index row and an audit entry exactly like a native one.

```
reporion import:scan   --from /mnt/archive --dry-run   # inventory, dedupe by hash
reporion import:text   --batch 2019-mioveni             # DOC/PDF → markdown
reporion import:ocr    --batch scans-2016 --lang ron    # scans → text layer
reporion import:meta   --batch 2019-mioveni --review    # extract metadata, queue low-confidence
reporion import:commit --batch 2019-mioveni
```

Design rules for the importer: every imported page records `imported_from` (original filename + hash) in frontmatter and keeps the source file in `data/import/originals/`; metadata extraction emits a **confidence per field**, and anything below threshold lands in a review queue rather than being guessed into the index; imports are batched and reversible (a batch id on every page, so `import:rollback` is possible before signing); and no imported page is ever marked `signed` — legacy documents enter as `archived`, a fourth status meaning "authoritative elsewhere, read-only here".

> ⚠︎ **Sequencing advice.** Build the importer *after* storage, index and ACL are solid, but *before* the editor screens — importing 4 000 real reports is by far the best test of the schema, the search ranking and the patient-key heuristic, and it is much cheaper to discover a schema mistake then than after a year of dictation.

## 11. What Claude Code should build first

- `Storage\FlatFile` — read, write, revisions, atomic rename, journal replay. With tests that kill the process mid-write.

- `Index\Sqlite` — schema, incremental upsert, `verify`, `rebuild`. Rebuild must be correct before anything else is built on top.

- `Acl` — ~~resolution order, `page_read` materialisation~~. Collapsed by D13 to `Visibility`: one predicate, one place, plus a test suite asserting a private page is invisible to an anonymous caller in search, tree, sitemap and API alike.

- `Render` — markdown + macro pipeline, server-only, with the export path calling the same code.

- The API surface for the read path, then the editor's write path.

- The importer (§10) — run it against the real archive before building screens.

Screens come last, ported from the mockup one at a time. By then the three interesting bugs in this design will have surfaced, and the spec will be wrong in ways we can only learn by running it.
