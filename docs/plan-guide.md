# Plan: the imaging guide (Ghid de indicații radioimagistice) as a searchable dataset

Status: **approved 2026-11-16** (all five decisions as recommended, below); to build on branch `feat/guide`. Decision row to add: **D62**.

**Amended 2026-11-16 (owner):** the guide is one source of the *Workup* rail section (`docs/plan-workup.md`, D63), not a feature on its own. Steps 1–2 below are built; step 3's rail section is folded into Workup (as `Guide::forReport()` and its *Next steps* block); steps 4–5 stay as small follow-ups.

## Context

The Romanian *Ghid de indicații radioimagistice* (public, signed by the Ministry of Health; the
working copy is the GIRI repository, `GHID.csv`) says, for each clinical situation, which imaging
exams are indicated, with what strength, evidence grade and dose. 1 341 rows, 622 situations, 95
subchapters, 14 chapters; 13 columns (`NR.CRT`, `Capitol`, `Subcapitol`, `Situația Clinică`, `Tip`,
`Examen`, `Indicație`, `Grad Indicație`, `Comentarii`, `Alte informații`, `Doza Min`, `Doza Max`,
`Terapeutic`).

Owner's choices (2026-11-16): it is **not** ingested as wiki pages but as a **searchable dataset**
with an API and an MCP tool; it is named **guide** in code, URLs and settings (the content stays
Romanian, D26). GIRI remains the master: Reporion never edits the guide, it loads a new CSV.

Inspiration: the agent figure (RYAI 250651, fig. 1): a differential, the clinical context, then
*guidelines* and *institutional protocols* for the next step. The guide is that step, in Romanian
and official.

## Design

### 1. The data file and its loading
- The CSV lives at `data/guide/guide.csv` with `data/guide/guide.json` beside it: `{sha256, rows,
  loaded_at, loaded_by, source}` (`source`: free text such as `GIRI@e797896`). In `data/`, so it is
  in backups and survives an update; not in `resources/` while the guide is a work in progress (it
  may be shipped there once final, as the default when `data/guide/` is empty).
- `bin/reporion guide:load <file.csv> [--source=<text>] [--dry-run] [--json]`: checks the header
  (the 13 columns, by name), UTF-8, the `Tip`/`Indicație`/`Grad` values against their legends
  (unknown → a warning, the row kept), writes the file atomically (temp + fsync + rename,
  invariant 7), then fills the index tables (§2). Audited `guide.load` with the sha and the row count.
  Also from Admin → Data (`Service\Maintenance`), with an upload, for an owner.
- No patient data can be in it, but the loader still refuses a CNP-shaped number (as the library).

### 2. Index tables (disposable, index.sqlite: **schema change to approve**)
Migration `migrations/index/009_guide.sql`:
- `guide_row(key TEXT PRIMARY KEY, situation TEXT, chapter TEXT, subchapter TEXT, title TEXT,
  tip TEXT, exam TEXT, indication TEXT, grade TEXT, comment TEXT, other TEXT, dose_min TEXT,
  dose_max TEXT, therapeutic INTEGER, ord INTEGER)`;
  `situation` = the situation's key, `key` = situation key + `.` + exam's slug.
- `guide_fts(chapter, subchapter, title, exams, comment, situation UNINDEXED, tokenize = "unicode61
  remove_diacritics 2")`: **one FTS row per situation** (its rows' exams and comments joined), so a
  hit is a situation and BM25 ranks situations, not stray rows.
- **Stable keys:** `NR.CRT` is renumbered on request (GIRI README), so a situation's key is the
  first 12 hex of `sha256(chapter | subchapter | situation text)` (normalised: NFC, trimmed,
  single spaces). A rewording gives a new key; the old one answers 404 (the guide has moved on).
- Filled by `guide:load` and by `index:rebuild` (from `data/guide/guide.csv`; none: empty tables).
  Deleting index.sqlite loses nothing (invariant 1). `index:verify` compares `guide.json`'s sha with
  the one the tables were filled from (a row in the existing `meta` table).

### 3. `Service\Guide`
- `search(string $q, array $filters = [], int $limit = 10): list<situation>`: FTS with D28's
  synonym groups and last-token prefix (as `/search`), plus the guide's own synonyms
  (`resources/guide-synonyms.txt`, taken from GIRI's IRIS app); filters `tip` (one or more codes),
  `chapter`, `indication` (e.g. only `Indicat`). A situation comes with all its exam rows in the
  guide's order.
- `situation(string $key): ?situation`.
- `forReport(PageRecord $page): list<situation>` (the rail): a query from the report's indication
  (frontmatter `indication` + the *Indicație* section, as `Support\Laterality` reads them) and its
  `region`; `modality` → `Tip` (`CT→T`, `MR→M`, `US→E`, `CR/DX/MG→G`, `NM/PT→N`, `RF/XA→X`) marks
  the row of *this* exam; a patient under 18 (frontmatter age/born) ranks *Pediatrie* first, an
  adult leaves it last. Top 3 situations. Nothing is sent anywhere; no AI.
- Modality codes and the legends are constants in `Support\GuideCodes` (with English labels in
  `lang/en.php` for the chrome; the guide's own words shown as they are).

### 4. Where it shows
1. **API** (new rows in `docs/architecture-api.md`):
   - `GET /api/v1/guide?q=&tip=&chapter=&indication=&limit=` → `{data: [situation], page}`.
   - `GET /api/v1/guide/{key}` → one situation.
   - `GET /api/v1/guide/for/{pid}`: `forReport()` for a report the caller may write (else 404).
   Public data: the first two answer **anonymous callers too** (decision below); throttled per IP.
2. **Pages**: `/guide` (search box, chapter list, filters) and `/guide/{key}` (the situation: a
   table of exams, indication with its colour, grade, dose, therapeutic, comment), read-only, with
   the attribution line (Ministry of Health, version from `guide.json`). In the public layout for
   anonymous visitors (invariant 9: an ordinary public route, not a CMS), in the app layout when
   signed in. Print-friendly via the existing screen CSS only (no PDF export).
3. **Editor rail, section *Guide*** (radiologist with the write grant, as D51's sections): the
   matched situations, this exam's row highlighted; *Open* goes to `/guide/{key}`; *Insert
   reference* puts a short line in the text (e.g. „Conform Ghidului de indicații radioimagistice:
   …”), through the undoable path. Runs when the indication changes (debounced), no AI.
4. **Checks (D51)**: one new warning, **only** when the best match is strong (BM25 margin over the
   second, tuned on real indications by `archive-analyst` counts) and this exam's row says
   `Neindicat` / `Neindicat în primă intenție` / `Este necesară justificare detaliată`: „The guide
   lists {exam} as {indication} for {situation}.” A warning, never a block (D7).
5. **MCP** (D56): tool `guide_search {query, tip?, chapter?}` and `guide_get {key}`; public data,
   so offered whatever `mcp.reports` says.
6. **Assistant**: placeholder `{guide}` in `Ai\Context` (the matched situations as a compact
   table) for the `diagnostic`, `gaps-traps` and a future *Workup*; documented in the prompt
   placeholders list. The guide is public: no redaction needed for it, the report text around it
   is redacted as ever.

### 5. Not done
- No editing of the guide in Reporion; no revisions of it (GIRI's git history is its history).
- No wiki pages, no library source (the library may still hold the guide's PDF as an article).
- No automatic refresh from GitHub: the owner loads a CSV when GIRI changes.

## Steps (each a commit with its tests)
1. `Support\GuideCodes`, `Service\Guide` (load, parse, keys, search), migration 009,
   `Index\Sqlite` fill + `index:rebuild` + `index:verify`; `guide:load`. Tests: parsing the real
   header shape on a small anonymised fixture, stable keys across renumbering, search with
   diacritics and synonyms, rebuild = load (byte-identical rows, the index test's rule).
2. API + `/guide` pages (+ the public-layout variant), lang strings, architecture-api rows; an HTTP
   test incl. anonymous access and 404 for an unknown key.
3. Rail section + `GET /api/v1/guide/for/{pid}` + Insert reference; editor JS test; browser check.
4. The Checks warning, after measuring match quality on real indications (counts only).
5. MCP tools; `{guide}` placeholder; docs (D62 row in CLAUDE.md + `docs/decisions-detail.md`,
   FORMATS for `data/guide/`, cli-reference, architecture-storage-index for the tables).

Full suite due after step 1 (index) and before merge; work on branch `feat/guide`.

## Decisions (owner, 2026-11-16)
1. Index schema change (migration 009, `guide_row` + `guide_fts`, disposable): **approved**.
2. New endpoints `/api/v1/guide…`, `/guide`, `/guide/{key}`, MCP tools: **approved**.
3. Anonymous access: **public**: `/guide` and the search API answer anonymous visitors, in the public layout.
4. The CSV comes by **`guide:load <file>`** into `data/guide/`.
5. The Checks warning: **yes, after measuring** match quality on real indications (step 4).
