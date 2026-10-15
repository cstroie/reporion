# CLAUDE.md: Reporion

A document management system for radiology reports, for a small team, not a single person.
Flat-file wiki: markdown on disk, SQLite as a disposable index, PHP 8.1+, no framework, no build
step for the server. Multiple admin-created user accounts (owner/editor/viewer, scoped per
namespace) plus anonymous read-only visitors. No self-service registration, no 2FA. GPL-3.0-or-later,
public repository.

Read `docs/architecture-storage-index.md` and `docs/architecture-api.md`
before changing anything structural. They are the source of truth;
this file is the working agreement.

## The invariants

These are not preferences. Breaking one is a bug even if tests pass.

1. **Disk is authoritative; `data/index.sqlite` is a cache.** Deleting the index must never lose
   data. No feature may store its only copy of anything in SQLite. If disk and index disagree, the
   index is wrong.
2. **A page is a directory.** Body, revisions, metadata and attachments live and move together.
3. **History is append-only.** `rev/NNNN.md.gz` files are written once, never edited, never deleted. (An `autosave.{username}.json` beside them is a disposable draft, not history.)
   Revert writes a *new* revision. Signed revisions keep their signature record forever. **One
   deliberate exception:** a minor edit (`Storage\FlatFile::saveMinor()`) overwrites its own
   still-unsigned revision's `rev/NNNN.md.gz` in place instead of writing a new one: refused
   (a normal new revision follows instead) the moment that revision carries a signature.
4. **Canonical rendering is server-side.** The page view, print, PDF, ODT and the index all render
   through `Render::toHtml()` (via `Render::body()`, which shows a D40 text page as typed). The editor's split preview, the assistant's `show` answers and the AI Evolution panel
   render through the same PHP renderer (`POST /api/v1/render`, D17 amended 2026-10-15: marked.js is gone), so there is one parser. The dialect
   is **generic CommonMark + tables + definition lists**; `tests/Render/RenderFixturesTest` renders the spec fixtures and real-report
   corpus through it. Adding syntax means adding it to `Service\Render` and a fixture.
5. **Writes go through `Storage`.** Controllers, plugins and CLI commands call services.
   Nothing outside `src/Storage/` touches `file_put_contents` on a page path.
6. **Visibility and ACL are resolved in the query, not after it.** One predicate,
   `Search\Query::visibilityClause($principal)`, combining `visibility` with the caller's
   namespace grants (D35–D37). A private page must be invisible to a caller not entitled to it: by
   visibility or by grant: in search, tree, sitemap, feed and API alike.
7. **Atomic writes only.** Temp file + `fsync` + `rename()`. Every multi-file write is preceded by a
   journal line so a crash is recoverable.
8. **The patient path never leaves the box.** `{yymmdd}-{name}` is fine on disk and on screen. It
   must not appear in an export, an external URL, a log line, an audit entry (store `path_hash`) or
   an AI prompt: those use the pid. The patient's *name* in a report's own export (print, PDF,
   ODT patient block) is intended: a report belongs to its patient (2026-09-27). The export's
   **file name** is the page path with `-` for `:` (`reports-mri-polimed-261008-name.pdf`) for a signed-in caller
   the reports go to the imaging center and must never be mixed up (2026-10-11,
   `PrintView::fileName()`); an anonymous export of a public page is `{pid}-rev{N}` (2026-10-12; was `{accession or pid}-rev{N}`).
   The path is still not in the document's content.
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
  Storage/                StorageInterface + FlatFile (atomic writes, journal, revisions)
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
data/                     pages/, media/, users/, groups/, index.sqlite, journal/, audit/, trash/, tmp/ (uploads kept until used),
                          backups/ (archives, D44), settings.yaml + site/ (Admin → Settings), tags.yaml (Admin → Tags), maintenance/
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
`tests/Http tests/Service …` as a check. **Never run the full suite yourself** (~3 min+, past
`composer`'s 300 s limit): say when it is due: before a merge, after a change to storage, index,
visibility, rendering or a shared `Support\*` helper, and let the owner run it.

## Commands

Every command answers `--help` (what it does and every option, from its static `help()`) and prints what it is about to do as its first stdout line, not with `--json`, whose stdout is the JSON document.

```
bin/reporion serve                      php -S with the right docroot
bin/reporion index:verify [--json]      cheap stat/hash drift pass
bin/reporion integrity:verify [--backup=<dir>] [--json]  revisions, signatures, media, stray page files, journal, index; exit 1 on a problem
bin/reporion index:rebuild [--vectors]  full rebuild from disk; --vectors then runs index:vectors
bin/reporion index:vectors [--dry-run] [--limit=<n>] [--force] [--json]  Similar reports: each report's de-identified conclusion embedded by the one model of Admin → AI into page_vectors (a cache; unchanged ones not asked again; --force asks for all again); writes no page
bin/reporion user:create --username=<u> --password-hash=<h> [--owner] [--radiologist] [--grant=<ns>:editor|viewer]... [--group=<g>]...  an unknown group is refused
bin/reporion group:create --name=<g> [--radiologist] [--grant=<ns>:editor|viewer]... [--display-name=<text>]  a named set of grants for several accounts (D47)
bin/reporion page:new <path> [--template=<p>]  create, optionally copying a template page
bin/reporion page:move <from> <to> [--actor=<u>]  redirect stub + fixups in unsigned pages: body links, `reference`, `priors`, `template` / `exams[].template` (not settings, grants or pins: docs/namespaces.md)
bin/reporion pages:repair-paths [--dry-run] [--json]  pages whose meta.json `path` is not the directory they are in (moved by hand): meta.json made to say where the page is (one atomic write, no revision, a `moves` entry) and the page indexed there; one an open journal intent names is left for journal:replay; also Admin → Maintenance; a signed-in lookup of a page on disk but not indexed does the same (FlatFile::adopt)
bin/reporion trash:purge [--older-than=30d] [--include-signed --operator=<u>] [--dry-run] [--json]
bin/reporion journal:replay [--min-age=60] [--dry-run] [--json]  finish writes a crash left half-done
bin/reporion pages:summarize [--actor=<u>] [--dry-run] [--namespace=<ns>] [--limit=<n>] [--overwrite] [--json]  the assistant's one-line summary for unsigned reports with none (no assistant: the conclusion's first sentence); dry run sends nothing (TODO idea 12); `--actor` (optional, default `cli`) is who it runs on behalf of: the revisions are `system:summarizer` (D41)
bin/reporion pages:tag [--actor=<u>] [--dry-run] [--namespace=<ns>] [--limit=<n>] [--overwrite] [--json]  the assistant's 3–5 tags (the `tags` prompt, whole report, Support\TagList) for unsigned reports with none, plus the RADS categories the conclusion states (`rads:birads-4a`, Support\Rads: no assistant, also on tagged reports); dry run sends nothing; `--actor` (optional, default `cli`) is who it runs on behalf of: the revisions are `system:tagger` (D41)
                                        (journal:replay, pages:repair-paths, index:verify, integrity:verify, trash:purge, pages:summarize and index:vectors also run from Admin → Maintenance, pages:tag, tags:prune and tags:review from Admin → Tags: Service\Maintenance)
bin/reporion backup:create [--dry-run] [--json]  an archive of data/ + conf/local.php in data/backups/ (D44: for moving an instance and a copy to keep; rsync stays the routine backup); also Admin → Maintenance
bin/reporion backup:verify <archive> [--json]  reads the archive end to end against its manifest; writes nothing
bin/reporion backup:restore <archive> [--replace] [--with-conf] [--actor=<u>]  data/ replaced by the archive's (the old kept in data/.pre-restore-<ts>/), then journal:replay, index rebuild, integrity check; only as the owner of data/ (`sudo -u www-data`); CLI only
bin/reporion tags:prune [--max-uses=<1-5>] [--actor=<u>] [--dry-run] [--json]  remove tags carried by at most `--max-uses` reports (default 1) from the reports' frontmatter: one new revision per page, signed reports keep theirs, `rads:*` and the Admin → Tags dictionary are never pruned, nothing is pruned if `data/tags.yaml` does not parse; `--actor` (optional, default `cli`) is on whose behalf: the revisions are `system:admin` (D41)
bin/reporion tags:review [--no-ai] [--actor=<u>] [--json]  proposals to tidy the tags in use (a rule: near-duplicates, a side or spine level; the assistant's `tag-review` prompt on the tag list only, never a report, no tag that may name a patient): written to the run report only, applied by ticking them in Admin → Tags (as `system:admin`, signed reports keep theirs); "never suggest" goes to `data/tags.yaml` `_rejected`
bin/reporion pages:apply-patient-csv --from=<table.csv> --namespace=<ns> [--loose-names] [--actor=<u>] [--dry-run] [--limit=<n>] [--json]  a site's booking table (DATA, NUME, PRENUME, CNP, DATE CLINICE…) → CNP, birth year, sex, indication into blank fields of the reports it names without doubt (name + exact day, one report); the doubtful go to review, an invalid CNP is never written; not in Admin → Maintenance (needs a file); output names pids and table lines only; `--actor` (optional, default `cli`) is on whose behalf: the revisions are `system:importer` (D41)
bin/reporion pacs:link --site=<code> [--actor=<u>] [--dry-run] [--limit=<n>] [--json]  dicom plugin: link a site's unlinked reports to their PACS study (unambiguous CNP/name+day only; signed ones return to draft); also a local pass that fills a blank `device` from the site's `inst:` texts and the kept `pacs_institution` (no PACS asked; signed ones skipped); `--actor` (optional, default `cli`) is on whose behalf: the revisions are `system:pacs` (D41)
bin/reporion ai:check [--json]          AI settings, egress verdict, the model each alias (lite/normal/expert) stands for, the server's models (sends no report text)
bin/reporion dicom:header <file.dcm> [--values] [--json]  dicom plugin: which report fields one DICOM file's header could start (no pixel data, nothing kept, no index opened); values hidden unless --values
bin/reporion doctor                     config, permissions, sqlite, extensions; fails if `data/settings.yaml` or `data/tags.yaml` does not parse (both read as empty then, and Admin refuses to save over them); warns if an account names a group that does not exist, or AI settings are not in the current shape (old flat `ai.*` keys / `ai.profiles` left in `conf/local.php` or `data/settings.yaml`)
```

## Decisions already made: do not relitigate in code

| # | Decision |
|---|---|
| D1 | Patient name stays in the path; the path never appears in exports (except as a signed-in export's file name, 2026-10-11), URLs, logs, audit or prompts. The name itself is never in URLs, logs, audit, prompts, public pages or teaching copies; a report's own exports (print, PDF, ODT) do name the patient (amended 2026-09-27) |
| D2 | `current.md` duplicates the newest revision as a read fast path |
| D3 | Correcting a signed report = new revision, signed again; the old signed revision stays in history. Exports embed `rev` + a `/r/{pid}/{rev}` verification link |
| D4 | Typed columns for the facet set (`modality, region, device, site, accession, study_date, protocol, summary`), `meta_json` for the tail |
| D5 | FTS5 with `remove_diacritics 2`. Romanian text is written both ways |
| D6 | ~~`visibility` replaces ACL entirely. No principals, no inheritance~~: **superseded by D35** (multi-user). Its `_defaults` pages were never built and are dropped (2026-10-13); the only namespace-level default is the starting `visibility` from the namespace description page (`{ns}:_index`) |
| D16 | Public site = public pages + `layout-public.php`; `site:home` is the landing page; publishing a report requires an explicit acknowledgement of what becomes visible, and is audited |
| D17 | PHP for everything, the editor preview included (`POST /api/v1/render`, `assets/js/render-client.js`). Dialect: generic CommonMark + tables + definition lists. **Amended 2026-10-15:** marked.js, its conformance test and the `package.json` dropped at the owner's request; was: marked.js for the preview with a conformance test gating both parsers |
| D18 | Prose only: no structured findings, no measurement macros. `summary` is the indexed escape hatch. Amended 2026-10-08 (phase 34h): RADS categories (BI-RADS etc.) from the conclusion are stored as tags (`rads:birads-4a`), not a structured field: no schema change for one value |
| D19 | Existing templates were imported as-is (the importer is removed, 2026-10-09; templates are now ordinary pages edited in the app). No inheritance; "new report" copies a page under `templates:` **Amended 2026-10-13 (D47):** `/new` offers *Start from*: readable page templates under `templates:pages` (`Support\Templates::PAGES_NS`) plus report templates per modality for a radiologist; a pick is a `?from=` copy (`Service\Duplicates`) |
| D20 | Accessions generated: `{SITE}-{MOD}-{yy}-{seq}`, counter in `data/counters.json`, allocated under a lock just before the create (a crash leaves a gap, never a duplicate), seeded from the numbers already on disk. Editable afterwards. Amended 2026-10-12: internal only: never printed; exports show the PACS's `pacs_accession` per exam, only with the site's letterhead on (`sites.{code}.letterhead`, off by default, 2026-10-15) |
| D21 | App-managed history. No git repository, no auto-commit |
| D22 | Backup = rsync over SSH, `--link-dest` dated snapshots, encrypted volume on the target. Not encrypted at rest by the app |
| D23 | lighttpd + PHP-FPM, PHP 8.1 floor, docroot is `public/` only, rewrite in server config |
| D24 | No dictation. Expansion macros (`;norm`) instead: pages under `templates:snippets:` (shared) and `templates:snippets:{modality ns}:` (2026-09-26); external dictation typing into the textarea must keep working |
| D25 | Survive connection drops (IndexedDB draft carrying its base revision + 409 flow). A revision is written only on Save (2026-09-26). **Amended 2026-10-12:** besides the local draft, the editor autosaves the form to the server a minute after a change: one disposable `autosave.{username}.json` per user in the page's own directory (docs/FORMATS.md §3l): never a revision, never indexed, no journal line, removed by its author's next Save, offered back with Restore / Discard. No service worker, no offline mode |
| D26 | English chrome, Romanian content. Strings live in `lang/en.php`: no hard-coded strings in templates |
| D27 | Media in `data/media/{year}/{sha256}.{ext}`; clipboard paste and file drag only. No DICOM ingest |
| D28 | Search recall via a query-time synonym table + prefix matching on the last token. No stemmer |
| D29 | `modality` and `region` are **lists**, not scalars: combined studies (CT cerebral + cervical) are common. Index keeps `page_regions` / `page_modalities` child tables; facets count a page once per value |
| D30 | A report's `#` heading is the patient name (`patient.name`; the importer, removed 2026-10-09, lifted the DokuWiki H1 to it). Reports made in the app: `exam_title` holds the exam, exports, the public layout and duplicates use it and drop the name heading (`Support\ReportName`), so the name never reaches a public page or a teaching copy; a report's own exports name the patient in the patient block (2026-09-27). Every report body is `# name / ## exam / ### sections` (docs/FORMATS.md §11). **2026-10-13:** the first `#` heading *is* the title of any page (`Support\PageTitle`, FORMATS §3k); frontmatter `title` is an optional override that nothing writes for the author, so a report made in the app has none. In an app-shape report (`patient` + `exam_title`) the first `#` is the name whatever its spelling |
| D31 | `Indicație` text stays in the body; age/sex are *copied* to frontmatter. The importer never deleted a sentence it thought it understood. *Historical: importer removed 2026-10-09; the archive is imported* |
| D32 | `import:commit` wrote through `Storage`: imported pages are structurally identical to native ones (pid, rev, journal, index row, audit). *Historical: importer removed 2026-10-09; the archive is imported* |
| D33 | Public repo, **GPL-3.0-or-later**. `data/`, `conf/local.php`, `uploads/` gitignored from the first commit; fixtures are anonymised |
| D34 | PDF via **dompdf**: see the print-CSS constraint below. No headless browser |
| D7 | `required` blocks **signing**, never **saving**. A half-dictated draft always saves |
| D10 | Media content-addressed in `data/media/`, with a per-page manifest for human names |
| D11 | `patient_key = sha256(cnp)` when present, else `sha256(name|born|sex)`. Store both; timeline prefers the strong key |
| D12 | One instance, all sites, over VPN. The editor must survive a dropped connection |
| D13 | ~~One owner account, argon2id, signed session cookie. No 2FA, no user table~~: **superseded by D35** |
| D14 | ~~No review step; the owner signs their own reports~~: **superseded by D37** |
| D15 | AI provider interface ships with no provider enabled; `Ai\Context::build()` is the only code that may assemble a prompt. Actions apply directly to the text in front; models are aliases (`lite`/`normal`/`expert`, optionally `server:alias`) per server, chosen by the profile table's `Model` column; reserved prompt ids (`summary`, `evolution`, `tags`, `presign`, `commit`: the last added 2026-10-10: the editor's Save writes the revision note when the doctor left it empty, marked `assisted: commit`) enable features outside the rail. Extended 2026-10-08: six server slots, each with per-alias parameters and a model filter/Get models/Test in Admin → AI; a server may name a failover slot (tried once, only on unreachable/timeout/5xx, never a refusal or 4xx); a Usage panel reads the audit, no new storage; Similar reports adds one embedding model for the instance (`ai.embed_server`/`ai.embed_model`), vectors cached in `page_vectors` (schema change approved 2026-10-08, rebuildable per invariant 1) Amended 2026-10-13 (D47): `ai.profile_map` `{namespace: profile}` (Admin → AI *Which prompts serve which pages*: a table of namespace + profile rows, saved as `ai.namespaces`, `ai.prompt_profile`, `ai.profile_map` and `ai.fallback_profile`); `AiConfig::profileFor()` takes the most specific namespace among the map and `ai.namespaces` (map wins a tie), else `ai.fallback_profile` Amended 2026-10-15 (owner's decision): a reserved prompt may be about no report: `tag-review` gets the tag vocabulary only (`Context::vocabulary()`, `Assistant::runVocabulary()`, still audited `ai.call`), tags that may name a patient withheld |
| D3b | Signed content can be superseded, never deleted. Purging a signed page needs an explicit override + audit entry |
| D8 | Generated text is never silently authoritative. There is no `assistant` actor (amended 2026-09-27, phase 15d): the assistant proposes in the editor's rail, the doctor applies and saves; the revision's note says `assisted: {actions}`, carried onto the `page.save` audit line |
| D9 | `StorageInterface` and `IndexInterface` are swappable **drivers**, not plugin territory. A plugin that can reach past the service layer into files can corrupt history |
| D35 | Multiple user accounts, admin-created only (no self-service registration). Argon2id, signed session cookie, no 2FA. Roles: `owner` (instance-wide: full read/write everywhere, manages users and grants) and per-namespace grants of `editor` (read+write, **including flipping `visibility`. D16's "deliberate, noisy act" applies equally to an editor and an owner, both audited**) or `viewer` (read) for everyone else. Anonymous visitors are unchanged: read-only, `public` visibility only Since phase 13 (2026-09-27) a user edits their own signature details and creates their own API tokens (Bearer, `read`/`write` scope, `/api/v1` and `/export` only) on `/profile` **Amended 2026-10-13 (D47):** grants may also come from groups; a non-radiologist has no grant on report namespaces (an owner reads them, never writes) |
| D36 | Accounts and grants live in `data/users/{username}.json`: disk authoritative (invariant 1), same as pages. No `user_grants` cache table in `index.sqlite`: a principal's grants are already resolved in PHP (`Session::principal()`) before any query runs, so `Search\Query::visibilityClause()` takes them as bound SQL parameters, not a join:`index:rebuild` never touches `data/users/`, because there is nothing about accounts in the index to lose. A namespace grant is a prefix match: a grant on `reports:mri` covers `reports:mri:*`, mirroring how namespaces already nest **Amended 2026-10-13 (D47):** groups are `data/groups/{name}.json`, resolved on read by `FlatFileUserStore`, never stored back, nothing in the index; a non-radiologist's grants on `reports`/`reports:*` are dropped in `User::effectiveGrants()` |
| D37 | Signing authority follows the write grant: any authenticated user with `editor` (or `owner`) on a page's namespace can sign that page as themselves. No review/handoff step: same no-review spirit as the old D14, generalised from "the owner" to "whoever is allowed to write here". **Amended 2026-10-13:** the write grant is necessary but no longer sufficient: only an account marked `radiologist` (a flag an owner sets in Admin → Users, stored in `data/users/{username}.json`, never self-service) signs, owners included. `Service\Signing::sign()` refuses anyone else (`NotRadiologistException`); the browser answers 403 and the API `403 not_radiologist`, for a caller who may see and write the page (one who may not still gets the 404); the Sign button is shown only to a radiologist with the grant. Still no review/handoff step. Existing accounts start without the flag: an owner sets it before anyone can sign **Amended 2026-10-13 (D47):** the `radiologist` flag may come from a group; an owner who is not a radiologist gets 403 `not_radiologist` on sign and cannot write reports (`User::canActOnReport()`) |
| D38 | The HIS link is a plugin (`plugins/hipobridge`): read-only FHIR to HippoBridge, one service account. A worklist pick only fills the guided form; HIS reports come in as `status: archived` pages (`imported_from`, `radiologist`, `order_ref`); the report they are imported for gets one revision that fills blanks (never contradicting the day/modality/site in its path) and appends `priors`, never overwriting; its screen is the report's *HippoBridge* tab |
| D39 | The PACS link is a plugin (`plugins/dicom`): study-level C-FIND through dcmtk's `findscu` (full path set by the owner), one PACS per site, each with the calling AE title it identifies us by; never listens, never retrieves images. The worklist queries by date/modality/UID; a report's PACS tab also asks the site's PACS by PatientID (CNP) or PatientName, through a findscu query file: never a command line, a URL or a log (amended 2026-09-30, owner's decision; setting `query_by_patient` turns it off). A worklist pick fills the guided form; the report's *PACS* tab links a study and fills blanks only (`study_uid`, `pacs_accession`, patient, time, exam title, referrer, and `device` once an owner has linked the study's scanner to one of the site's devices: 2026-10-07). Amended 2026-10-08: the plugin also starts a report from an **uploaded DICOM file** (no PACS query):`Support\DicomHeader` reads the header only, the file is removed either way, and the guided form is prefilled the same as from a worklist pick, with the site/device filled too when exactly one site's linked scanner matches (`bin/reporion dicom:header` does the same header read standalone) Amended 2026-10-12: a device in Admin → Sites may also carry `inst:` texts (`CODE = name | pacs: a; b | inst: Somatom16`); when the exact PACS-scanner link finds nothing, the device is the one whose `inst` text the study's DICOM InstitutionName contains (case/spacing-folded), only if exactly one device of the site does (`Support\Devices::forInstitution`): never a guess; a DICOM file upload fills site+device when exactly one site claims the text; `pacs:link` also fills a blank `device` locally from the kept `pacs_institution` (unsigned reports only). **Amended 2026-10-14:** a DICOM file's DeviceSerialNumber (0018,1000) is read too: the report's `pacs_device` then ends ` · SN {serial}`, a link adds both the name and `SN {serial}` to the device's `pacs` list, and either finds the device (a renamed station is still the scanner; PACS-query names have no serial and match as before); the new-report form says when the file's scanner is linked to no device (an owner links or adds it in the PACS tab's Scanner box).The exam title is the order's own words when a file has them (RequestedProcedureDescription, then the scheduled step's, read from RequestAttributesSequence), else StudyDescription, else the BodyPartExamined, else the series description; BodyPartExamined also suggests the region (`Study::regions`). |
| D40 | A page body is markdown, or plain text with `format: text` (2026-10-10, FORMATS §3j): shown as typed: escaped, preformatted: everywhere through `Render::body()`, never through the markdown parser. No headings, links or images in text: no exam tabs, TOC, conclusion summary or exam heading checks (signing is not blocked by them). Any page may be text, reports included; Markdown is the key left out |
| D41 | Pseudousers: `system:summarizer`, `system:tagger`, `system:importer`, `system:pacs`, plus `system:ai` reserved (no caller yet), `system:admin` is `tags:prune`'s, in `Auth\SystemActor` (2026-10-12). **Not accounts**: no `data/users/` file, password, grant, token or session; the colon cannot occur in a username. Maintenance tasks (`pages:summarize`, `pages:tag`, `tags:prune`, `pages:apply-patient-csv`, `pacs:link`) and the HippoBridge import write their revisions (revlog `by`, `created_by`) and `page.save`/`page.create` audit lines as the pseudouser; the audit line also carries `on_behalf_of: <username>` (the CLI `--actor`, or whoever clicked in Admin → Maintenance / the HippoBridge tab). Refines D8 and D35, supersedes neither. Signing is never a pseudouser (D37); an editor Save with AI assistance stays the doctor's (D8). `maintenance.run` and the maintenance reports still name the human |
| D42 | The groff export is a plugin (`plugins/groff`, 2026-10-12): an ordinary Markdown page as groff ms source (`/x/groff/ms/{pid}`) or a PDF from `groff -T pdf` (`/x/groff/pdf/{pid}`), for signed-in readers, audited `export`. It never handles patient data: a report (reports namespace) and a page with a `patient` block are refused with the same 404 as an invisible page; the current revision is exported, no signature, accession or verification link. groff is run as an argument list (no shell, private temp dir, timeout kill); the owner's preamble is a new plugin-setting type `textarea` (multi-line, optional `line_pattern`) limited to comments and layout/font requests, so no file read or command can be smuggled in. A `ui` slot entry may carry `"for": "report"` (default) or `"for": "page"`, so the Export ▾ menu can offer a plugin export on any page. *Amended 2026-10-12: first built for signed reports, then changed to ordinary pages at the owner's request.* |
| D43 | **Web installer** (`install.php`, 2026-10-12, owner's request): the archive may be unpacked into a web root or a subfolder of one and set up from the browser: requirements (checked in the web SAPI, so FPM's `ffi.enable` and limits are seen), `composer install --no-dev` (the server's composer or a SHA-256-verified composer.phar, as the web user), a browser-side probe that `data/`, `conf/`, `composer.json` and `.git/` are not served: **before** anything secret is written, the steps refuse to run out of order, then `conf/local.php` from the example (fresh session secret, mode 0640), the first owner (`FlatFileUserStore`, D35 unchanged), `data/settings.yaml` site values and the index, then the probe again plus `/login`, a real fsync and `doctor`, in-process. Code in `src/Install/` (loaded by hand: runs before `vendor/` exists), `public/install.php` + root `install.php`, `templates/install.php`, `assets/js/install.js`; log `data/install.log` (0640, never a password, hash or secret). **Installed = `conf/local.php` exists and no `conf/.install-state.json`**: a hand-made install, this box's included, is installed and `install.php` only redirects to `/` (it may be deleted). The first browser to begin holds the install (random cookie, stored hashed); an abandoned one may be taken over after an hour, never once its owner exists. **Amends D23:** besides `public/` as docroot (still recommended and what `install.php` proposes), *root mode*: the URL maps to the repository root, whose `index.php` runs `public/index.php` and where only `index.php`, `install.php` and `assets/` may be served: `.htaccess` for Apache (shipped), printed server config for lighttpd/nginx; the installer will not finish while the probe sees a leak |
| D44 | **Backup archive** (`backup:create`, `backup:verify`, `backup:restore`, 2026-10-12, owner's request: staging → production and a copy to keep): one gzip tar of `data/` and, when present, `conf/local.php`, with a `manifest.json` (last entry: format, every file's size and sha256, the source's data/audit paths). Left out: `index.sqlite*` (a cache, rebuilt on restore), `data/tmp/`, `data/import/`, `data/backups/` (where archives are kept), lock files, links. Plain, not encrypted (as D22). It holds patient data and the session secret: keep it as carefully as `data/`. Written by `Service\Backup\BackupService` through `ArchiveWriter` (streamed, no second copy), run by `Service\Maintenance\BackupTask` (CLI and Admin → Maintenance, the same code). **Restore** runs only as the owner of `data/` (the 2026-09-24 incident), refuses an instance that holds pages unless `--replace`, extracts and checks every file against the manifest in `data/tmp/restore-<ts>/` **before** anything is replaced, then moves the current content of `data/` into `data/.pre-restore-<ts>/` (never deleted; a `MOVED.log` is kept there) and the restored content up: all inside `data/`, because the web user does not own its parent. `ArchiveReader` treats the archive as untrusted: only `data/…` and `conf/local.php`, no `..`, absolute or link entries. Afterwards: `journal:replay`, index rebuild, `integrity:verify` (`index:vectors` is left to the owner: it asks the embedding server). `conf/local.php` belongs to its machine: installed over the current one only with `--with-conf` and only when it names the same data directory, else left as `conf/local.php.from-backup`. **Amends D22:** the archive is for moving an instance and for a copy to keep; rsync with `--link-dest` stays the routine backup. Names carry the time only, audit lines (`backup.restore`, `backup.download`) counts and sizes: never a page path (invariant 8) |
| D45 | **Template page shape** (2026-10-13, `Support\TemplateHeadings`, FORMATS §3i-b): a `templates:` page has one `#` heading (the template's name, shown in pickers, may differ from the exam: `Art: Genunchi`), then one `##` (the exam name, which becomes the report's `## exam` and `exams[].title`), then its `###` sections. Frontmatter `region` is the exam's default region (the new-report form pre-selects it, the user may change it). `checklist` and `reference` stay live on the template, for the writer only. A wrong shape is a warning (doctor, Metadata view), never a block (D7). Old-shape templates (no `#`) keep working: their title is the exam. Amends D19: "new report" copies the template's metadata and its `##`, not its text |
| D46 | **Personal namespace** (2026-10-13, owner's request): every account is an `editor` of `users:{username}`, **derived, never stored**:`Auth\User::effectiveGrants()` adds it to the explicit grants (D36: disk holds only what an owner granted), and `roleOn()`, `hasAnyWriteAccess()` and `Search\Query::clause()` read that list, so invariant 6 still has one predicate. Readable by the account and by instance owners; anyone else only through an explicit grant on `users:{username}` (a grant on bare `users` covers everyone's: an owner's explicit choice). Pages start `private` like any new page; publishing keeps D16's acknowledged step. No personal namespace for a pseudouser or a username that is a reserved path segment (`rev`, `redirect`, …). Nothing on disk until the first page; deactivating an account leaves its pages. Every account can therefore write somewhere, so `hasAnyWriteAccess()` is true for all and the top nav's *New* starts in the caller's own namespace where they only read; *My pages* sits in the quick-nav. Personal pages are ordinary pages: never reports, never signable. Amends D35/D36, supersedes neither **Amended 2026-10-13 (D47):** `effectiveGrants()` is own ∪ groups' ∪ personal |
| D47 | **Groups; patient pages are a radiologist's** (2026-10-13, owner's request). A group is a named set of grants plus an optional `radiologist` flag, `data/groups/{name}.json` (FORMATS §10b). An account names its groups in `data/users/{u}.json` `groups: [..]`; `FlatFileUserStore` resolves them on read. Effective grants = own ∪ groups' ∪ personal `users:{u}` (D46). Unknown group names resolve to nothing (`doctor` warns); `owner` never comes from a group; nothing in the index. For an account that is not a radiologist (own flag or a group's) `User::effectiveGrants()` drops every grant on `reports`/`reports:*`: search, tree, sitemap, worklist, API and direct lookup hide private reports (public stay public, unlisted reachable by link). A non-radiologist owner reads reports, never writes them (`roleOn()` gives `viewer`). Report features (create, edit, sign, join/merge, assistant, plugin tabs) need a radiologist with the write grant (`User::canActOnReport()`, `NewReport::canCreateReports()`); sign by a non-radiologist reader is 403 `not_radiologist`, others 404. Admin → Users / Groups, audit `user.create|access|deactivate|reactivate`, `group.create|update|delete`; `/profile` shows what the account reaches (`Auth\AccessSummary`). Amends D35, D36, D37, D46 |

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

1. `Storage\FlatFile`: revisions, atomic writes, journal replay, crash tests.
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
