# CLAUDE.md: Reporion

A document management system for radiology reports, for a small team, not a single person.
Flat-file wiki (2.0, D54): a page is a markdown file on disk, its history one zip beside it in
`attic/`, operational state in a permanent SQLite database, search and embeddings in disposable
SQLite caches; PHP 8.1+, no framework, no build step for the server. Multiple admin-created user accounts (owner/editor/viewer, scoped per
namespace) plus anonymous read-only visitors. No self-service registration, no 2FA. GPL-3.0-or-later,
public repository.

Read `docs/architecture-v2.md` (the 2.0 storage), `docs/architecture-storage-index.md` and
`docs/architecture-api.md` before changing anything structural. They are the source of truth;
this file is the working agreement.

## The invariants

These are not preferences. Breaking one is a bug even if tests pass.

1. **Content and the legal record are on disk; operational state is in `data/reporion.sqlite`;
   everything derived is in `data/index.sqlite` and `data/vectors.sqlite`** (D54). Deleting either
   derived file loses nothing. `reporion.sqlite` is permanent and backed up with `data/` (a
   `VACUUM INTO` copy, never the live file). No revision or signature exists only in a database:
   every one is in its page's attic zip; if a mirror row and a zip disagree, the zip is right.
2. **A page is a file.** `pages/{ns…}/{slug}.md` is its current state (with a `pid` key Storage
   writes), `attic/{ns…}/{slug}.zip` its history, its `page` row (by pid) everything else. File,
   zip and row move together under one journal line. A namespace is a directory beside the page
   of the same name.
3. **History is append-only.** A zip entry (`NNNN.md`) is written once: the container is rewritten
   only after every earlier entry is checked byte for byte against the revlog (`Storage\Attic`).
   Revert writes a *new* revision. Signed revisions keep their signature record forever. **One
   deliberate exception:** a minor edit (`Storage\FileTree::saveMinor()`) replaces its own
   still-unsigned newest entry in place instead of writing a new one: refused (a normal new
   revision follows instead) the moment that revision carries a signature. (An autosave draft is a
   disposable state row, not history.)
4. **Canonical rendering is server-side.** The page view, print, PDF, ODT and the index all render
   through `Render::toHtml()` (via `Render::body()`, which shows a D40 text page as typed). The editor's split preview, the assistant's `show` answers and the AI Evolution panel
   render through the same PHP renderer (`POST /api/v1/render`, D17 amended 2026-10-15: marked.js is gone), so there is one parser. The dialect
   is **generic CommonMark + tables + definition lists** and the additions of `Service\Markdown` (docs/FORMATS.md §10c); `tests/Render/RenderFixturesTest` renders the spec fixtures and real-report
   corpus through it. Adding syntax means adding it to `Service\Render` and a fixture.
5. **Writes go through `Storage`.** Controllers, plugins and CLI commands call services.
   Nothing outside `src/Storage/` writes a page file, an attic zip or a table of
   `reporion.sqlite`, or builds a page's file path.
6. **Visibility and ACL are resolved in the query, not after it.** One predicate,
   `Search\Query::visibilityClause($principal)`, combining `visibility` with the caller's
   namespace grants (D35–D37). A private page must be invisible to a caller not entitled to it: by
   visibility or by grant: in search, tree, sitemap, feed and API alike.
7. **Atomic writes only.** Temp file + `fsync` + `rename()` for files, one transaction per
   database, and a journal line before any write that spans a file and a database, so a crash is
   recoverable (replay re-syncs a page from its zip).
8. **The patient path never leaves the box.** `{yymmdd}-{name}` is fine on disk and on screen. It
   must not appear in an export, an external URL, a log line, an audit entry (store `path_hash`) or
   an AI prompt: those use the pid. The patient's *name* in a report's own export (print, PDF,
   ODT patient block) is intended: a report belongs to its patient (2026-09-27). The export's
   **file name** is the page path with `-` for `:` (`reports-mri-polimed-261008-name.pdf`) for a signed-in caller
   the reports go to the imaging center and must never be mixed up (2026-10-11,
   `PrintView::fileName()`); an anonymous export of a public page is `{pid}-rev{N}` (2026-10-12; was `{accession or pid}-rev{N}`).
   The path is still not in the document's content. `reporion.sqlite` holds paths like the files
   it replaced: it never leaves the box except inside a backup.
9. **Two audiences, one page set.** The public site is `visibility: public` pages rendered in
   `templates/layout-public.php`: never a separate CMS, never duplicated content. `site:home` is
   the landing page and is an ordinary editable page. Anonymous requests that are not entitled get
   **404, never 403**.
10. **No patient data in the repository.** Public GPL-3.0 repo (D33): `data/`, `conf/local.php` and
    `uploads/` are gitignored, fixtures in `tests/fixtures/` are anonymised, and no real report,
    name, CNP or accession may appear in code, tests, comments or commit messages.

## Repo layout

```
public/index.php          single entry point (redirects to install.php until conf/local.php and vendor/ exist)
public/install.php        web installer (D43); install.php + index.php at the root for root mode
src/
  Kernel.php              boot, container, plugin discovery
  Http/                   Router, Request, Response, ApiResponse, Session
  Controller/             thin: parse, call service, render
  Service/                one class per use case (Revisions, Signing, Render, PdfExport, OdtExport,
                          PageMoves, Joins, Tags, InstanceSettings, …); Ai/ (provider interface,
                          egress guard, redactor, assistant); Maintenance/ (tasks the CLI and Admin share)
  Search/                 Query (FTS5 query building)
  Cli/                    bin/reporion commands, one class each; DoctorCommand
  Audit/                  AuditLog (append-only data/audit/)
  Exception/              typed exceptions the controllers map to HTTP statuses
  Storage/                StorageInterface + FileTree (page files, journal, writes), Attic (history zips),
                          StateDb (reporion.sqlite), Databases (the three SQLite files), StateRecovery,
                          V1Converter (1.x → 2.0, tools/migrate-v2.php)
  Index/                  IndexInterface + Sqlite (schema, upsert, verify, rebuild)
  Auth/                   UserStoreInterface + FlatFileUserStore, User, Grant (D35/D36)
  Schema/                 frontmatter validation, resources/schema/*.json loader
  Plugin/                 PluginInterface, Hooks, loader
  Support/                Slug, Ulid, Yaml, Diff, Patient key
  Install/                web installer (D43): loaded without the autoloader, before vendor/ exists
templates/                PHP templates for SSR routes
assets/
  js/                     islands: editor, palette, search, worklist, admin
  css/
plugins/<id>/             plugin.json + Plugin.php
conf/                     local.php: strict configuration only: the data path and the session secret. Everything else is in data/
resources/                shipped defaults, git-tracked: schema/*.json (per-modality field definitions), synonyms.txt (seeds data/tags.yaml)
data/                     pages/ ({slug}.md), attic/ ({slug}.zip), reporion.sqlite (permanent), index.sqlite + vectors.sqlite (caches),
                          media/, sources/ (library originals, D58), users/, groups/, journal/, audit/, trash/{pid}/, tmp/ (uploads kept until used), snapshot/ (rsync's
                          copy of reporion.sqlite), backups/ (archives, D44), settings.yaml + site/ (Admin → Settings), tags.yaml
                          (Admin → Tags), maintenance/
migrations/               state/ (reporion.sqlite: data migrations), index/, vectors/ (caches)
bin/reporion              CLI
tests/
docs/
```

## Conventions

**PHP.** 8.1+ (`declare(strict_types=1)` in every file), because the server has both 7.x and 8.x and
7.4 is out of security support. PSR-12, PSR-4 under `Reporion\`. Constructor injection; no service
locators, no static state except `Support\*` pure helpers. Final classes by default. Typed
properties and return types everywhere: including `void` and `never`.

**Deployment.** lighttpd + PHP-FPM, TLS upstream. Document root is `public/` **only** (or root mode, D43, then only `index.php`, `install.php` and `assets/` are served); `data/`,
`conf/` and `plugins/` sit outside it. No `.htaccess` exists on lighttpd, so the rewrite to
`public/index.php` is server config: see `docs/deploy-lighttpd.md`. `bin/reporion doctor` asserts
that `data/` is not fetchable over HTTP; never ship a change that could make it so.

**No framework, but not "no dependencies".** Composer for: markdown parser, YAML, PDF engine, ODT
writer, SQLite extensions. Do not add a framework, an ORM, a DI package, or a frontend build
toolchain without asking.

**Errors.** Domain exceptions in `src/Exception/`, mapped to HTTP status in one place
(`Http\ErrorMapper`). Never `die()`, never a bare `\Exception`, never an error message that leaks a
filesystem path to an anonymous caller.

**JSON API.** Under `/api/v1`. Collections return `{data, page}`; errors return
`{error: {code, message, fields?}}` with a real status. No `Idempotency-Key` (dropped 2026-10-09, FORMATS §7). New
endpoints get a row in `docs/architecture-api.md` in the same commit.

**Frontend.** Server-rendered HTML for documents; islands only where `docs/architecture-api.md`
says so. Vanilla JS or a single small library: no SPA router, no bundler config sprawl. Islands
mount on `[data-island]` and get their config from a `<script type="application/json">` next to the
mount point. CSS uses the design tokens from the mockup; do not invent new colours. Messages to the user (saved, failed, warning, hint) use the notice card: `templates/partials/notice.php`, never a bare `<p role="alert">` (docs/ui-notices.md).
Interface decisions are collected in `docs/design-rules.md`: read it before building a screen, add to it when the owner decides one.
**Form fields** (2026-10-16): the label is a short name; a `*_hint` lang key is the `placeholder` (an example value), a `*_help` key is the `<small>` under the input; input and `<small>` sit inside the `<label>`. No `(e.g. …)` or `(optional)` in a label, no hard-coded placeholder in a template.

**Print CSS is dompdf CSS (D34).** dompdf supports **no flexbox and no CSS grid**. The print and
export templates (`templates/print/*.php`) must use tables and block layout with absolute widths in
`mm`/`pt`, web-safe or explicitly registered fonts, and no `color-mix()`, `var()` fallback chains or
modern selectors. Keep them in their own stylesheet (`assets/css/print.css`): never share a
stylesheet between screen and print, because a screen refactor will otherwise silently break the
signed PDF. Every change to a print template requires a rendered-PDF check, not just a browser one.

**Naming.** Storage uses `pid` for the ULID and `path` for the colon path: never reuse either word
for the other thing. `rev` is always an integer. `visibility` ∈ `private|unlisted|public`. `status` ∈
`draft|signed|archived`. `username` identifies an account; `group` is a name (same shape as a username) for a set of grants in `data/groups/`; `radiologist` is a flag on an account or group, never a role; `role` ∈ `owner|editor|viewer`:`owner`
is instance-wide, `editor`/`viewer` are always paired with a `namespace` in a grant, never bare.

## Testing

PHPUnit. Four kinds, all expected in a PR:

- **Storage**: write/read round trips, revision immutability, and *crash tests*: kill the process
  between journal write and rename, then assert recovery leaves no partial page.
- **Render fixtures**: `tests/Render/RenderFixturesTest` renders the CommonMark fixtures and a real-report
  corpus through the one PHP parser (marked.js and its conformance test were dropped 2026-10-15, D17).
  Node remains only for the editor-*.js tests (`tools/run-editor-*.js`; no `npm install`).
- **Index**: `rebuild` from a fixture tree produces byte-identical rows to incremental indexing of
  the same operations. This test is the safety net for the whole cache-is-disposable claim.
- **Visibility**: for each of private/unlisted/public × {owner, editor-with-grant,
  editor-without-grant, viewer-with-grant, anonymous} × search/tree/sitemap/API, assert exactly
  what is reachable. Add a case here before adding any new listing endpoint.

**Browser checks** run on a throwaway instance, never on `data/`: `tools/browser/start.sh <dir>`
(synthetic fixtures, the fake AI server), `node tools/browser/cdp.mjs shot <dir> <path> <out.png>
[w] [h]`, `tools/browser/stop.sh <dir>`. Project agents in `.claude/agents/` (Sonnet) do the
routine parts: `test-runner`, `browser-check`, `docs-sync`, `archive-analyst` (read-only, counts
only: never names or report text).

`bin/reporion` commands are testable too: prefer a command over a one-off script so the behaviour is
covered and rerunnable.

**After coding, run only the tests of the modules the change touched**: the classes or folders
named by the code you edited (`vendor/bin/phpunit tests/Support/PageTitleTest.php`, `--filter`,
one folder at most), so development is not held up. Never run a whole directory tree such as
`tests/Http tests/Service …` as a check. **Never run the full suite yourself** (~1900 tests, more than
20 minutes by 2026-10-24, far past `composer`'s 300 s limit): say when it is due: before a merge, after a change to storage, index,
visibility, rendering or a shared `Support\*` helper, and let the owner run it.

## Commands

Full descriptions of the commands below: `docs/cli-reference.md`. Every command answers `--help` (what it does and every option, from its static `help()`) and prints what it is about to do as its first stdout line, not with `--json`, whose stdout is the JSON document.

```
bin/reporion serve                      php -S with the right docroot
bin/reporion index:verify [--json]      cheap stat/hash drift pass
bin/reporion integrity:verify [--backup=<dir>] [--json]  the attic zips (every revision, the revlog hash chain), signatures, the state DB
bin/reporion index:rebuild [--vectors]  full rebuild from disk; --vectors then runs index:vectors
bin/reporion db:recover [--dry-run] [--json]  reporion.sqlite's page rows, revisions and signatures made again from the attic zips
bin/reporion index:vectors [--dry-run] [--limit=<n>] [--force] [--json]  Similar reports: each report's de-identified conclusion embedded by the one model of Admin → AI into page_vectors
bin/reporion user:create --username=<u> --password-hash=<h> [--owner] [--radiologist] [--grant=<ns>:editor|viewer]... [--group=<g>]...  an unknown group is refused
bin/reporion group:create --name=<g> [--radiologist] [--grant=<ns>:editor|viewer]... [--display-name=<text>]  a named set of grants for several accounts (D47)
bin/reporion library:add <file>... [--key=<k>] [--tags=<a,b>] [--actor=<u>] [--dry-run] [--json]  articles into the library (docs/library.md): each file's text extracted (poppler, pandoc, LibreOffice), its record read offline
bin/reporion library:import <file>... [--tags=<a,b>] [--actor=<u>] [--dry-run] [--json]  the records of a BibTeX, RIS or CSL-JSON export (Mendeley, Zotero, EndNote) into the library as sources with no file
bin/reporion library:relookup [<key>...] [--tag=<t>] [--all] [--actor=<u>] [--yes] [--dry-run] [--json]  asks CrossRef and PubMed again for sources already in the library, by their DOI or PMID
bin/reporion library:abstracts [--offline] [--dry-run] [--limit=<n>] [--actor=<u>] [--json]  each library source's abstract page `library:{key}:abstract` where it has none: the record's `csl.abstract`
bin/reporion library:notices [--all] [--dry-run] [--limit=<n>] [--actor=<u>] [--json]  each library source's DOI or PMID asked of CrossRef and PubMed
bin/reporion library:fulltext [<key>...] [--limit=<n>] [--actor=<u>] [--dry-run] [--json]  the free PDF of the sources that have a DOI and no file, by Unpaywall
bin/reporion library:duplicates [--json]  the library's sources that look like one article (a shared DOI, PMID, original file, or title and year), group by group, the one to keep first; reads only
bin/reporion library:merge <keep> <from> [--actor=<u>] [--dry-run] [--json]  the source `from` folded into `keep` (D60): its missing fields, tags, file and text move over, every unsigned page citing it is re-cited,
bin/reporion library:export [--format=bibtex|ris|csl|text] [--style=vancouver|apa|ama] [--tag=<t>] [--to=<file>]  the library's references; reads only
bin/reporion page:new <path> [--template=<p>]  create, optionally copying a template page
bin/reporion page:move <from> <to> [--actor=<u>]  a redirect from the old path + fixups in unsigned pages
bin/reporion pages:repair-paths [--dry-run] [--json]  page files under pages/ that are no page where they are: one moved by hand takes its page along
bin/reporion trash:purge [--older-than=30d] [--include-signed --operator=<u>] [--dry-run] [--json]
bin/reporion journal:replay [--min-age=60] [--dry-run] [--json]  finish writes a crash left half-done
bin/reporion pages:summarize [--actor=<u>] [--dry-run] [--namespace=<ns>] [--limit=<n>] [--overwrite] [--json]  the assistant's one-line summary for unsigned reports with none (no assistant: the conclusion's first sentence); dry run sends nothing
bin/reporion pages:tag [--actor=<u>] [--dry-run] [--namespace=<ns>] [--limit=<n>] [--overwrite] [--json]  the assistant's 3–5 tags (the `tags` prompt, whole report, Support\TagList) for unsigned reports with none, plus the RADS categories the
                                        (journal:replay, pages:repair-paths, index:verify, integrity:verify, trash:purge, backup:create, backup:rsync, pages:summarize, index:vectors, library:abstracts and library:notices also run from Admin → Maintenance, pages:tag, tags:prune and tags:review from Admin → Tags: Service\Maintenance)
bin/reporion backup:create [--dry-run] [--json]  an archive of data/ + conf/local.php in data/backups/
bin/reporion backup:rsync [--dry-run] [--if-due] [--json]  a dated, differential rsync snapshot of data/ on the machine Admin → Settings → Backup names, over ssh
bin/reporion backup:verify <archive> [--json]  reads the archive end to end against its manifest; writes nothing
bin/reporion backup:restore <archive> [--replace] [--with-conf] [--actor=<u>]  data/ replaced by the archive's (the old kept in data/.pre-restore-<ts>/), then journal:replay, index rebuild, integrity check
bin/reporion tags:prune [--max-uses=<1-5>] [--actor=<u>] [--dry-run] [--json]  remove tags carried by at most `--max-uses` reports (default 1) from the reports' frontmatter
bin/reporion tags:review [--no-ai] [--actor=<u>] [--json]  proposals to tidy the tags in use (a rule: near-duplicates, a side or spine level; the assistant's `tags-review` prompt on the tag list
bin/reporion pages:apply-patient-csv --from=<table.csv> --namespace=<ns> [--loose-names] [--actor=<u>] [--dry-run] [--limit=<n>] [--json]  a site's booking table (DATA, NUME, PRENUME, CNP, DATE CLINICE…) → CNP, birth year, sex, indication into blank fields of the reports it
bin/reporion pacs:link --site=<code> [--actor=<u>] [--dry-run] [--limit=<n>] [--json]  dicom plugin: link a site's unlinked reports to their PACS study (unambiguous CNP/name+day only; signed ones return to draft)
bin/reporion ai:prompts-install --profile=<p> [--target=radiology|generic|research] [--only=<id,id>] [--actor=<u>] [--list] [--dry-run] [--json]  make the shipped example prompts (resources/prompts: radiology 20 pages, with the rail's create, conclusion, diagnostic, gaps-traps,
bin/reporion ai:check [--json]          AI settings, egress verdict, the model each alias (lite/normal/expert) stands for, the server's models (sends no report text)
bin/reporion dicom:header <file.dcm> [--values] [--json]  dicom plugin: which report fields one DICOM file's header could start (no pixel data, nothing kept, no index opened)
bin/reporion templates:lint [--ns=<templates:…>] [--json]  what the report templates do that the `create` prompt reads badly
bin/reporion doctor  config, permissions, sqlite, extensions; fails if `data/reporion.sqlite` is missing beside page files
php tools/migrate-v2.php --from=<1.x data> --to=<new dir> [--dry-run] [--json]  the one-off 1.x → 2.0 conversion (D54, architecture-v2 §11): copy and verify, --from only read
```

## Decisions already made: do not relitigate in code

Each row is a summary; the long ones keep their every amendment verbatim in `docs/decisions-detail.md` (§D15, §D55, §D58…). Read the detail before changing the feature a row names.

| # | Decision |
|---|---|
| D1 | Patient name stays in the path; the path never appears in exports (except as a signed-in export's file name, 2026-10-11), URLs, logs, audit or prompts. The name itself is never in URLs, logs, audit, prompts, public pages or teaching copies; a report's own exports (print, PDF, ODT) do name the patient (amended 2026-09-27) |
| D2 | `current.md` duplicates the newest revision as a read fast path **Amended by D54 (2.0):** the page file `pages/{ns…}/{slug}.md` is that copy of the attic zip's newest entry |
| D3 | Correcting a signed report = new revision, signed again; the old signed revision stays in history. Exports embed `rev` + a `/r/{pid}/{rev}` verification link |
| D4 | Typed columns for the facet set (`modality, region, device, site, accession, study_date, protocol, summary`), `meta_json` for the tail |
| D5 | FTS5 with `remove_diacritics 2`. Romanian text is written both ways |
| D6 | ~~`visibility` replaces ACL entirely. No principals, no inheritance~~: **superseded by D35** (multi-user). Its `_defaults` pages were never built and are dropped (2026-10-13); the only namespace-level default is the starting `visibility` from the namespace description page (`{ns}:_index`) |
| D16 | Public site = public pages + `layout-public.php`; `site:home` is the landing page; publishing a report requires an explicit acknowledgement of what becomes visible, and is audited |
| D17 | PHP for everything, the editor preview included (`POST /api/v1/render`, `assets/js/render-client.js`). Dialect: generic CommonMark + tables + definition lists. **Amended 2026-10-15:** marked.js, its conformance test and the `package.json` dropped at the owner's request; was: marked.js for the preview with a conformance test gating both parsers |
| D18 | Prose only: no structured findings, no measurement macros. `summary` is the indexed escape hatch. Amended 2026-10-08 (phase 34h): RADS categories (BI-RADS etc.) from the conclusion are stored as tags (`rads:birads-4a`), not a structured field: no schema change for one value |
| D19 | Existing templates were imported as-is (the importer is removed, 2026-10-09; templates are now ordinary pages edited in the app). No inheritance; "new report" copies a page under `templates:` **Amended 2026-10-13 (D47):** `/new` offers *Start from*: readable page templates under `templates:pages` (`Support\Templates::PAGES_NS`) plus report templates per modality for a radiologist; a pick is a `?from=` copy (`Service\Duplicates`) |
| D20 | Accessions generated: `{SITE}-{MOD}-{yy}-{seq}`, counter in `data/counters.json` (D54: the `counter` table of `reporion.sqlite`), allocated under a lock just before the create (a crash leaves a gap, never a duplicate), seeded from the numbers already on disk. Editable afterwards. Amended 2026-10-12: internal only: never printed; exports show the PACS's `pacs_accession` per exam, only with the site's letterhead on (`sites.{code}.letterhead`, off by default, 2026-10-15) |
| D21 | App-managed history. No git repository, no auto-commit |
| D22 | Backup = rsync over SSH, `--link-dest` dated snapshots, encrypted volume on the target. Not encrypted at rest by the app |
| D23 | lighttpd + PHP-FPM, PHP 8.1 floor, docroot is `public/` only, rewrite in server config |
| D24 | No dictation. Expansion macros (`;norm`) instead: pages under `templates:snippets:` (shared) and `templates:snippets:{modality ns}:` (2026-09-26); external dictation typing into the textarea must keep working **Amended 2026-10-19:** the editor's slash menu opens only on a trusted `/` typed at the start of an empty line (or after a list marker) with nothing after it; Escape inserts the `/`; items run through the toolbar's undoable path; an on/off toggle is kept in the browser (`reporion.editor.slash`), so external dictation keeps working |
| D25 | Survive connection drops (IndexedDB draft carrying its base revision + 409 flow). A revision is written only on Save (2026-09-26). **Amended 2026-10-12:** besides the local draft, the editor autosaves the form to the server a minute after a change: one disposable `autosave.{username}.json` per user in the page's own directory (docs/FORMATS.md §3l; D54: a `draft` row of `reporion.sqlite`): never a revision, never indexed, no journal line, removed by its author's next Save, offered back with Restore / Discard. No service worker, no offline mode |
| D26 | English chrome, Romanian content. Strings live in `lang/en.php`: no hard-coded strings in templates |
| D27 | Media in `data/media/{year}/{sha256}.{ext}`; clipboard paste and file drag only. No DICOM ingest |
| D28 | Search recall via a query-time synonym table + prefix matching on the last token. No stemmer |
| D29 | `modality` and `region` are **lists**, not scalars: combined studies (CT cerebral + cervical) are common. Index keeps `page_regions` / `page_modalities` child tables; facets count a page once per value |
| D30 | A report's first `#` heading is the patient name (`patient.name`); reports made in the app hold the exam in `exam_title`, and exports, the public layout and duplicates drop the name heading (`Support\ReportName`) so the name never reaches a public page or teaching copy. The first `#` of any page is its title (`Support\PageTitle`, FORMATS §3k); every report body is `# name / ## exam / ### sections` (FORMATS §11). *(Full text: `docs/decisions-detail.md` §D30.)* |
| D31 | `Indicație` text stays in the body; age/sex are *copied* to frontmatter. The importer never deleted a sentence it thought it understood. *Historical: importer removed 2026-10-09; the archive is imported* |
| D32 | `import:commit` wrote through `Storage`: imported pages are structurally identical to native ones (pid, rev, journal, index row, audit). *Historical: importer removed 2026-10-09; the archive is imported* |
| D33 | Public repo, **GPL-3.0-or-later**. `data/`, `conf/local.php`, `uploads/` gitignored from the first commit; fixtures are anonymised |
| D34 | PDF via **dompdf**: see the print-CSS constraint below. No headless browser |
| D7 | `required` blocks **signing**, never **saving**. A half-dictated draft always saves |
| D10 | Media content-addressed in `data/media/`, with a per-page manifest for human names (D54: `media` rows of `reporion.sqlite`) |
| D11 | `patient_key = sha256(cnp)` when present, else `sha256(name|born|sex)`. Store both; timeline prefers the strong key |
| D12 | One instance, all sites, over VPN. The editor must survive a dropped connection |
| D13 | ~~One owner account, argon2id, signed session cookie. No 2FA, no user table~~: **superseded by D35** |
| D14 | ~~No review step; the owner signs their own reports~~: **superseded by D37** |
| D15 | AI provider interface ships with no provider enabled; `Ai\Context::build()` is the only code that may assemble a prompt. Actions apply directly to the text in front; models are aliases (`lite`/`normal`/`expert`, optionally `server:alias`) per server; reserved prompt ids enable features outside the rail (`summary`, `evolution`, `tags`, `presign`, `commit`, the recall, ask and library ones). Profiles and servers are chosen per namespace (`ai.profile_map`, `ai.server_map`); a fallback profile serves the pages a profile lacks. Shipped example prompts live in `resources/prompts/` (targets radiology, generic, research). *(Full text: `docs/decisions-detail.md` §D15.)* |
| D3b | Signed content can be superseded, never deleted. Purging a signed page needs an explicit override + audit entry |
| D8 | Generated text is never silently authoritative. There is no `assistant` actor: the assistant proposes in the editor's rail, the doctor applies and saves, and the revision note says `assisted: {actions}` (also on the `page.save` audit line). The analyses of a library source and Studio's texts are kept automatically as their own private pages beside it (`Service\Library\Analyses`), never written into the page they are about, never read as a source. *(Full text: `docs/decisions-detail.md` §D8.)* |
| D9 | `StorageInterface` and `IndexInterface` are swappable **drivers**, not plugin territory. A plugin that can reach past the service layer into files can corrupt history |
| D35 | Multiple user accounts, admin-created only (no self-service registration). Argon2id, signed session cookie, no 2FA. Roles: `owner` (instance-wide) and per-namespace grants of `editor` (read+write, including flipping `visibility`: D16's acknowledged step applies equally) or `viewer`. Anonymous visitors read `public` only. A user edits their own signature details and makes their own API tokens (`read`/`write`, `/api/v1` and `/export`) on `/profile`; grants may also come from groups (D47). *(Full text: `docs/decisions-detail.md` §D35.)* |
| D36 | Accounts and grants live in `data/users/{username}.json` (disk authoritative); no grants cache in the index: a principal's grants are resolved in PHP and bound as SQL parameters by `Search\Query::visibilityClause()`, so `index:rebuild` never touches accounts. A grant on a namespace is a prefix match. Groups are `data/groups/{name}.json`, resolved on read. Saved views (`views: [{name, query, kind?}]`, at most 20, `Auth\SavedViews`) are named `/search` queries, or collections of the library (`kind: library`); they grant nothing. *(Full text: `docs/decisions-detail.md` §D36.)* |
| D37 | Signing authority follows the write grant, but only an account marked `radiologist` (an owner sets it; may come from a group) signs, owners included: `Service\Signing::sign()` refuses anyone else (`NotRadiologistException`, 403 `not_radiologist` for one who may see and write the page; others 404). No review/handoff step. *(Full text: `docs/decisions-detail.md` §D37.)* |
| D38 | The HIS link is a plugin (`plugins/hipobridge`): read-only FHIR to HippoBridge, one service account. A worklist pick only fills the guided form; HIS reports come in as `status: archived` pages, and the report they are imported for gets one revision that fills blanks and appends `priors`, never overwriting. It also feeds *Ask the archive* (hook `ask.sources`, D53): only content words are sent, never the question or an identifier; a hit's name and CNP only teach the redactor. HIS access is gated by `NewReport::canCreateReports`, not the visibility predicate. *(Full text: `docs/decisions-detail.md` §D38.)* |
| D39 | The PACS link is a plugin (`plugins/dicom`): study-level C-FIND through dcmtk's `findscu`, one PACS per site with its calling AE title; never listens, never retrieves images. The worklist and a report's PACS tab (by PatientID/PatientName, through a query file, never a command line, URL or log) fill the guided form and blanks only. An uploaded DICOM file's header (`Support\DicomHeader`, nothing kept) can start a report; devices are matched by the PACS scanner name, serial or `inst:` text, only when exactly one matches. *(Full text: `docs/decisions-detail.md` §D39.)* |
| D40 | A page body is markdown, or plain text with `format: text` (2026-10-10, FORMATS §3j): shown as typed: escaped, preformatted: everywhere through `Render::body()`, never through the markdown parser. No headings, links or images in text: no exam tabs, TOC, conclusion summary or exam heading checks (signing is not blocked by them). Any page may be text, reports included; Markdown is the key left out |
| D41 | Pseudousers (`system:summarizer`, `tagger`, `importer`, `pacs`, `admin`, `librarian`, `ai` reserved; `Auth\SystemActor`) are **not accounts**: no user file, password, grant, token or session. Maintenance tasks and the HIS import write their revisions and audit lines as the pseudouser, and the audit line carries `on_behalf_of: <username>`. Signing is never a pseudouser (D37); an editor Save with AI assistance stays the doctor's (D8). *(Full text: `docs/decisions-detail.md` §D41.)* |
| D42 | The groff export is a plugin (`plugins/groff`): an ordinary Markdown page as groff ms source or a PDF, for signed-in readers, audited `export`. Never patient data: a report or a page with a `patient` block is the same 404 as an invisible page. groff runs as an argument list (no shell, private temp dir, timeout); the preamble is a `textarea` plugin setting limited to comments and layout requests. *(Full text: `docs/decisions-detail.md` §D42.)* |
| D43 | Web installer (`install.php`, `src/Install/`, loaded without the autoloader): requirements, `composer install --no-dev`, a browser probe that `data/`, `conf/`, `composer.json` and `.git/` are not served **before** anything secret is written, then `conf/local.php`, the first owner, settings and index. Installed = `conf/local.php` exists and no `conf/.install-state.json`. Besides `public/` as docroot there is *root mode* (only `index.php`, `install.php` and `assets/` served). Can also create the proposed prompts (never over an existing page); lists optional software as warnings. *(Full text: `docs/decisions-detail.md` §D43.)* |
| D44 | Backup archive (`backup:create|verify|restore`): one gzip tar of `data/` and `conf/local.php` with a `manifest.json` of sizes and sha256; `reporion.sqlite` goes in as a `VACUUM INTO` copy; caches, `tmp/`, `backups/` and locks are left out. Plain, not encrypted. Restore runs only as the owner of `data/`, checks every file before replacing anything, keeps the old data in `data/.pre-restore-<ts>/`, then replays the journal, rebuilds the index and checks integrity. The archive is for moving an instance; rsync (D49) is the routine backup. *(Full text: `docs/decisions-detail.md` §D44.)* |
| D45 | Template page shape (`Support\TemplateHeadings`, FORMATS §3i-b): a `templates:` page has one `#` (the template's name), one `##` (the exam name, which becomes the report's `## exam`), then `###` sections; frontmatter `region` is the exam's default. "New report" copies the template's metadata and its `##`, not its text. A wrong shape is a warning, never a block (D7). *(Full text: `docs/decisions-detail.md` §D45.)* |
| D46 | Personal namespace: every account is an `editor` of `users:{username}`, **derived, never stored** (`Auth\User::effectiveGrants()`; invariant 6 keeps one predicate). Readable by the account and owners. Pages start `private`; never reports, never signable; nothing on disk until the first page. *(Full text: `docs/decisions-detail.md` §D46.)* |
| D47 | Groups (`data/groups/{name}.json`: grants plus an optional `radiologist` flag) are named in an account's `groups`; effective grants = own ∪ groups' ∪ personal. **Patient pages are a radiologist's:** for a non-radiologist every grant on `reports`/`reports:*` is dropped, so search, tree, sitemap, API and direct lookup hide private reports; report features (create, edit, sign, join, assistant, plugin tabs) need a radiologist with the write grant (`User::canActOnReport()`). Unknown groups resolve to nothing; `owner` never comes from a group. *(Full text: `docs/decisions-detail.md` §D47.)* |
| D48 | Report writes are current-format only; reads accept every format. Every save writes the newest format (`Support\Exams::normalize()`), no older shape and no mix; every reader accepts every format a report was ever stored in (`Exams::of()`/`report()`); signed revisions are never rewritten, so there is no bulk migration. A new format ships with its read-fallback, conversion and a test of every historic shape in the same commit. *(Full text: `docs/decisions-detail.md` §D48.)* |
| D49 | Periodic rsync backup (`backup:rsync`): a dated, differential snapshot of `data/` on another machine, hard-linked to the last one (`--link-dest`), retention a week or a month, `reporion.sqlite` sent as `data/snapshot/reporion.sqlite`. Config in `data/settings.yaml` `backup:` (Admin → Settings → Backup), no free-form rsync/ssh arguments; schedule is one permanent cron line (`--if-due`). Transports: ssh (default, host key pinned on first contact) or an rsync daemon (unencrypted, trusted networks only). rsync's and ssh's stderr go to `/dev/null` (invariant 8). *(Full text: `docs/decisions-detail.md` §D49.)* |
| D50 | Recall while writing: a rail section shows passages of finished reports (signed/archived) that describe the line just written, the doctor's own first, plus *Not yet said* reminders (`POST /api/v1/recall`, `Service\Recall`, under the visibility predicate; never this page or patient; pid links only). It runs on a pause or a keystroke, writes no ghost text (D24); AI only on a click through `recall-expand` and `recall-missing`. No schema change, no storage. *(Full text: `docs/decisions-detail.md` §D50.)* |
| D51 | Writing aids, passive rail sections for a radiologist with the write grant, no AI: *Checks* (`Support\WritingChecks`, `POST /api/v1/checks`, also on the Sign screen: laterality, sex mismatch, blanks, empty sections, not concluded) and *Prior* (`Service\PriorStudy`, `GET /pages/{path}/prior`: the prior's matching passage, *Insert comparison*). Warnings never block (D7); no ghost text. *(Full text: `docs/decisions-detail.md` §D51.)* |
| D52 | Comments live in `comment` rows of `reporion.sqlite` keyed by pid (so they move and trash with the page), not in front matter (the signature digest covers it): not a revision, not indexed, not signed, never in print, PDF, ODT or the API's `meta`. Any signed-in reader comments, resolves and reopens; delete is the author's or an owner's. Audit lines carry the pid and comment id, never the text. *(Full text: `docs/decisions-detail.md` §D52.)* |
| D53 | Ask the archive (`/ask`, `POST /api/v1/ask`): a question answered from finished reports the caller can read (`Service\ArchiveAsk`: keyword and semantic retrieval fused by rank, at most 8 passages, 2 per report, under the visibility predicate; the reserved `ask-archive`/`ask-expand` prompts; de-identified; cited by number and linked by pid and rev). The answer is shown, never written; audited `ai.call`. The HIS passages join through the `ask.sources` hook (D38). Follow-up history is held by the browser only. *(Full text: `docs/decisions-detail.md` §D53.)* |
| D54 | Reporion 2.0 storage (`docs/architecture-v2.md`): a page is a file `data/pages/{ns…}/{slug}.md` (a `pid` key in its frontmatter), its history one zip `data/attic/{ns…}/{slug}.zip` (`NNNN.md` entries + `revlog.json`; rewritten only after every earlier entry is checked); operational state (pid↔path, moves, redirects, media, comments, deliveries, drafts, trash, counters) in the permanent `data/reporion.sqlite`; facets and FTS in a disposable `index.sqlite`, embeddings in a disposable `vectors.sqlite`. Accounts, groups, settings and `tags.yaml` stay files. `tools/migrate-v2.php` converts a 1.x directory once. A page with pages under it moves and deletes alone. *(Full text: `docs/decisions-detail.md` §D54.)* |
| D55 | Notes app (`/notes`): a simple offline-first PWA, part of Reporion. A note is an ordinary private page `users:{username}:notes:{ulid}` (path made on the device); title = frontmatter `title`, text = body, tags = `tags`, `breaks: true`. No new storage. JSON session API (`/api/v1/session`) plus the existing pages API; stale saves merge by `Support\Merge3` (clean → new revision, overlapping → 409 with markers). IndexedDB per account, synced on open, `online`, after typing and every minute; the service worker keeps the shell, never the API, and waits before updating (version and build shown). Install prompt, share target (shares kept on the device, never sent raw), icons, tags and theme as in the detail. A note with text opens **rendered** (server renderer) and is edited as plain text. *(Full text: `docs/decisions-detail.md` §D55.)* |
| D56 | MCP server (`POST /mcp`, Streamable HTTP, bearer token only): tools `search`, `get_page`, `list_namespace` read the wiki as the token's account sees it (invariant 6); an API token may be limited to namespaces (`User::narrowedTo()`). **Patient pages** are left out unless `mcp.reports` is on, then shown by pid only, de-identified (`Service\Mcp\ReportView`): amends invariant 8. OAuth 2.1 for claude.ai/Desktop (`Auth\OAuth`, nothing stored but an ordinary read token), `write` tokens add `create_page`/`update_page` (never a report, a patient page or a signed page). `mcp.access` is `off|local|token|on` (default `local`); hardening: redirect allowlist, throttles (`Http\Throttle`), token expiry, optional web-server header gate. *(Full text: `docs/decisions-detail.md` §D56.)* |
| D57 | EPUB export (`plugins/epub`, `GET /x/epub/{pid}`): a namespace as an EPUB 3 book for signed-in readers; the page named like the namespace is the front matter, each page directly in it a chapter (under the caller's visibility, ordered by `sort`). Never patient data (as D42); metadata from the front page's `author`, `summary`, `language`, `publisher`, `rights`, `date`, `tags`. Writes no page. *(Full text: `docs/decisions-detail.md` §D57.)* |
| D58 | Library: one page per article `library:{key}` (frontmatter `csl` CSL-JSON, `original`; body = the cleaned text), originals in `data/sources/{ab}/{sha256}.{ext}`. Two-step add (upload → proposed record → confirm; `library:add`), BibTeX/RIS/CSL-JSON import and export, `[@key]` citations with a References section (Vancouver, APA, AMA), highlights, web clip (opt-in), the assistant's analyses, Ask the notebook, Studio, flashcards and audio overview, OCR for scans. **Never patient data** (a CNP refuses the text; invariants 8, 10); library pages are always private. Lookups (CrossRef, PubMed) are opt-in, by identifier only (except D60's search). Abstracts are kept as `library:{key}:abstract`; a moved source takes its subnamespace along. *(Full text: `docs/decisions-detail.md` §D58.)* |
| D59 | Library search and ask, apart from the reports: each source's passages are in the disposable `passage_fts`; `Service\Library\SourceAsk` (terms, search by words and meaning fused by rank, pack within the token budget, answer) serves *Ask the notebook* and *Ask the library* (`ask-library`, streamed, editors of the library only, never reports or HippoBridge); `/library?q=` finds articles by their words; `/search` and the palette leave the library out. `index:rebuild` fills `passage_fts`. *(Full text: `docs/decisions-detail.md` §D59.)* |
| D60 | Library: reference-manager features (docs/library.md; amends D36 by one key and D58). NLM journal abbreviations; retraction/correction `notice` from CrossRef and PubMed (`library:notices`, banner and on-screen citation marks only); merge duplicates (`Service\Library\Merge`, redirect on delete so a signed page's old key still resolves, keys stay taken); filters and saved collections; related sources; Find full text via Unpaywall (`library.unpaywall_email`), then OpenAlex's copy (`library.openalex_key`) and Europe PMC's open text, never posing as a browser; a bookmarklet; add by PubMed search (`library.search`, off by default, **sends what was typed**, CNPs refused, audit keeps a count). Detail in `docs/decisions-detail.md` §D60. *(Full text: `docs/decisions-detail.md` §D60.)* |

## Working agreement

- **Ask before**: adding a dependency, changing the on-disk layout, changing the index schema,
  adding an endpoint not in the API doc, touching the signing/revision code, or changing anything
  in the account/grant model (D35–D37).
- **Small commits**, each with its tests. Conventional commit prefixes (`feat:`, `fix:`, `refactor:`,
  `docs:`).
- **When a doc and the code disagree**, fix the doc in the same commit. A stale architecture doc is
  worse than none.
- **Do not** generate screens ahead of the services they need, scaffold "just in case" abstractions,
  or add a plugin hook without a real plugin using it.
- **Performance targets** to keep honest: page view < 50 ms server time, search p95 < 100 ms at
  10 000 reports, full rebuild < 60 s without embeddings.

## Build order

1. `Storage\FileTree` (1.x: `FlatFile`): revisions, atomic writes, journal replay, crash tests.
2. `Index\Sqlite`: schema, upsert, `verify`, `rebuild`. Rebuild correct **before** anything builds
   on it.
3. `Visibility` + its test matrix.
4. `Render`: markdown + macros, server-only, shared by view and export.
5. `GET /pages/{path}` + the SSR page view. Real reports on screen.
6. Print route, then PDF and ODT from the same HTML.
7. `site:home` + the public layout: cheap once the page view exists, and it settles the
   two-audience question before more routes accumulate.
8. `GET /search` + the palette.
9. `PUT/POST /pages` + the editor island (autosave, IndexedDB draft, 409 conflict flow).
10. Revisions, diff, revert, sign.
11. Admin, tags, index management.
12. AI endpoints, behind the disabled-by-default provider interface.
Screens are ported from the mockup in `design/` one at a time. The mockup is the visual contract:
match its markup, class names and tokens rather than reinventing layout.
