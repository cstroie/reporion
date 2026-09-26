# CLAUDE.md — Reporion

A document management system for radiology reports, for a small team, not a single person.
Flat-file wiki: markdown on disk, SQLite as a disposable index, PHP 8.1+, no framework, no build
step for the server. Multiple admin-created user accounts (owner/editor/viewer, scoped per
namespace) plus anonymous read-only visitors. No self-service registration, no 2FA. GPL-3.0-or-later,
public repository.

Read `docs/architecture-storage-index.md`, `docs/architecture-api.md` and
`docs/architecture-import.md` before changing anything structural. They are the source of truth;
this file is the working agreement.

## The invariants

These are not preferences. Breaking one is a bug even if tests pass.

1. **Disk is authoritative; `data/index.sqlite` is a cache.** Deleting the index must never lose
   data. No feature may store its only copy of anything in SQLite. If disk and index disagree, the
   index is wrong.
2. **A page is a directory.** Body, revisions, metadata and attachments live and move together.
3. **History is append-only.** `rev/NNNN.md.gz` files are written once, never edited, never deleted.
   Revert writes a *new* revision. Signed revisions keep their signature record forever.
4. **Canonical rendering is server-side.** The page view, print, PDF, ODT and the index all render
   through `Render::toHtml()`. The editor preview may use marked.js in the browser (D17) — but the
   dialect stays **generic CommonMark + tables**, and `tests/RenderConformanceTest` must stay green:
   both parsers, same normalised HTML, on the spec fixtures and on a corpus of real reports. Adding
   syntax means making that test pass in both parsers, or not adding it.
5. **Writes go through `Storage`.** Controllers, plugins, importers and CLI commands call services.
   Nothing outside `src/Storage/` touches `file_put_contents` on a page path.
6. **Visibility and ACL are resolved in the query, not after it.** One predicate,
   `Search\Query::visibilityClause($principal)`, combining `visibility` with the caller's
   namespace grants (D35–D37). A private page must be invisible to a caller not entitled to it — by
   visibility or by grant — in search, tree, sitemap, feed and API alike.
7. **Atomic writes only.** Temp file + `fsync` + `rename()`. Every multi-file write is preceded by a
   journal line so a crash is recoverable.
8. **The patient path never leaves the box.** `{yymmdd}-{name}` is fine on disk and on screen. It
   must not appear in an export, an external URL, a log line, an audit entry (store `path_hash`) or
   an AI prompt — those use the pid.
9. **Two audiences, one page set.** The public site is `visibility: public` pages rendered in
   `templates/layout-public.php` — never a separate CMS, never duplicated content. `site:home` is
   the landing page and is an ordinary editable page. Anonymous requests that are not entitled get
   **404, never 403**.
10. **No patient data in the repository.** Public GPL-3.0 repo (D33): `data/`, `conf/local.php` and
    `uploads/` are gitignored, fixtures in `tests/fixtures/` are anonymised, and no real report,
    name, CNP or accession may appear in code, tests, comments or commit messages.

## Repo layout

```
public/index.php          single entry point
src/
  Kernel.php              boot, container, plugin discovery
  Http/                   Router, Request, Response, ApiResponse, Session
  Controller/             thin: parse, call service, render
  Service/                Pages, Revisions, Render, Search, Export, Patients, Index, Ai
  Storage/                StorageInterface + FlatFile (atomic writes, journal, revisions)
  Index/                  IndexInterface + Sqlite (schema, upsert, verify, rebuild)
  Auth/                   UserStoreInterface + FlatFileUserStore, User, Grant (D35/D36)
  Schema/                 frontmatter validation, conf/schema/*.json loader
  Plugin/                 PluginInterface, Hooks, loader
  Support/                Slug, Ulid, Yaml, Diff, Patient key
templates/                PHP templates for SSR routes
assets/
  js/                     islands: editor, palette, search, worklist, admin
  css/
plugins/<id>/             plugin.json + Plugin.php
conf/                     local.php (paths + secrets only), schema/, patient_merges.json
data/                     pages/, media/, users/, index.sqlite, journal/, audit/, trash/, import/,
                          settings.yaml + site/ (Admin → Settings), maintenance/
bin/reporion              CLI
tests/
docs/
```

## Conventions

**PHP.** 8.1+ (`declare(strict_types=1)` in every file), because the server has both 7.x and 8.x and
7.4 is out of security support. PSR-12, PSR-4 under `Reporion\`. Constructor injection; no service
locators, no static state except `Support\*` pure helpers. Final classes by default. Typed
properties and return types everywhere — including `void` and `never`.

**Deployment.** lighttpd + PHP-FPM, TLS upstream. Document root is `public/` **only**; `data/`,
`conf/` and `plugins/` sit outside it. No `.htaccess` exists on lighttpd, so the rewrite to
`public/index.php` is server config — see `docs/deploy-lighttpd.md`. `bin/reporion doctor` asserts
that `data/` is not fetchable over HTTP; never ship a change that could make it so.

**No framework, but not "no dependencies".** Composer for: markdown parser, YAML, PDF engine, ODT
writer, SQLite extensions. Do not add a framework, an ORM, a DI package, or a frontend build
toolchain without asking.

**Errors.** Domain exceptions in `src/Exception/`, mapped to HTTP status in one place
(`Http\ErrorMapper`). Never `die()`, never a bare `\Exception`, never an error message that leaks a
filesystem path to an anonymous caller.

**JSON API.** Under `/api/v1`. Collections return `{data, page}`; errors return
`{error: {code, message, fields?}}` with a real status. Writes honour `Idempotency-Key`. New
endpoints get a row in `docs/architecture-api.md` in the same commit.

**Frontend.** Server-rendered HTML for documents; islands only where `docs/architecture-api.md`
says so. Vanilla JS or a single small library — no SPA router, no bundler config sprawl. Islands
mount on `[data-island]` and get their config from a `<script type="application/json">` next to the
mount point. CSS uses the design tokens from the mockup; do not invent new colours.

**Print CSS is dompdf CSS (D34).** dompdf supports **no flexbox and no CSS grid**. The print and
export templates (`templates/print/*.php`) must use tables and block layout with absolute widths in
`mm`/`pt`, web-safe or explicitly registered fonts, and no `color-mix()`, `var()` fallback chains or
modern selectors. Keep them in their own stylesheet (`assets/css/print.css`) — never share a
stylesheet between screen and print, because a screen refactor will otherwise silently break the
signed PDF. Every change to a print template requires a rendered-PDF check, not just a browser one.

**Naming.** Storage uses `pid` for the ULID and `path` for the colon path — never reuse either word
for the other thing. `rev` is always an integer. `visibility` ∈ `private|unlisted|public`. `status` ∈
`draft|signed|archived`. `username` identifies an account; `role` ∈ `owner|editor|viewer` — `owner`
is instance-wide, `editor`/`viewer` are always paired with a `namespace` in a grant, never bare.

## Testing

PHPUnit. Three kinds, all expected in a PR:

- **Storage**: write/read round trips, revision immutability, and *crash tests* — kill the process
  between journal write and rename, then assert recovery leaves no partial page.
- **Render conformance**: PHP parser vs marked.js on the CommonMark fixtures and a real-report
  corpus, compared as normalised HTML. This is what makes the two-parser decision (D17) safe.
- **Import conversion**: each `tests/fixtures/*.dokuwiki` → its `*.expected.md`, plus a *round-trip
  text check* (render both sides, compare text content) proving no content was lost.
- **Index**: `rebuild` from a fixture tree produces byte-identical rows to incremental indexing of
  the same operations. This test is the safety net for the whole cache-is-disposable claim.
- **Visibility**: for each of private/unlisted/public × {owner, editor-with-grant,
  editor-without-grant, viewer-with-grant, anonymous} × search/tree/sitemap/API, assert exactly
  what is reachable. Add a case here before adding any new listing endpoint.

`bin/reporion` commands are testable too: prefer a command over a one-off script so the behaviour is
covered and rerunnable.

During development run targeted suites only — `composer test -- tests/Http/` — the full
suite (~3 min) is for pre-merge only.

## Commands

```
bin/reporion serve                      php -S with the right docroot
bin/reporion index:verify [--json]      cheap stat/hash drift pass
bin/reporion index:rebuild [--vectors]  full rebuild from disk
bin/reporion user:create --username=<u> --password-hash=<h> [--owner] [--grant=<ns>:editor|viewer]
bin/reporion page:new <path> [--template=<p>]  create, optionally copying a template page
bin/reporion page:move <from> <to> [--actor=<u>]  redirect stub + link fixups in unsigned pages
bin/reporion trash:purge [--older-than=30d] [--include-signed --operator=<u>] [--dry-run] [--json]
bin/reporion journal:replay [--min-age=60] [--dry-run] [--json]  finish writes a crash left half-done
bin/reporion pages:check-frontmatter [--repair --actor=<u>] [--json]  find/repair frontmatter the old autosave flattened
                                        (these four also run from Admin → Maintenance: Service\Maintenance)
bin/reporion import:scan|convert|meta|commit|rollback --batch <id>
bin/reporion pages:scan|convert|commit --batch <id>  generic (non-report) page import; import:rollback covers it too
bin/reporion templates:import --from <dir> [--dry-run] [--actor=<u>]  DokuWiki report templates → templates:{ns}:* (D19)
bin/reporion doctor                     config, permissions, sqlite, extensions
```

## Decisions already made — do not relitigate in code

| # | Decision |
|---|---|
| D1 | Patient name stays in the path; never in exports, URLs, logs, audit or prompts |
| D2 | `current.md` duplicates the newest revision as a read fast path |
| D3 | Correcting a signed report = new revision, signed again; the old signed revision stays in history. Exports embed `rev` + a `/r/{pid}/{rev}` verification link |
| D4 | Typed columns for the facet set (`modality, region, device, site, accession, study_date, protocol, summary`), `meta_json` for the tail |
| D5 | FTS5 with `remove_diacritics 2` — Romanian text is written both ways |
| D6 | ~~`visibility` replaces ACL entirely. No principals, no inheritance~~ — **superseded by D35** (multi-user) |
| D16 | Public site = public pages + `layout-public.php`; `site:home` is the landing page; publishing a report requires an explicit acknowledgement of what becomes visible, and is audited |
| D17 | marked.js for the editor preview, PHP for everything canonical. Dialect: generic CommonMark + tables. Conformance test gates both |
| D18 | Prose only — no structured findings, no measurement macros. `summary` is the indexed escape hatch |
| D19 | Existing templates imported as-is. No inheritance; "new report" copies a page under `templates:` |
| D20 | Accessions generated: `{SITE}-{MOD}-{yy}-{seq}`, counter in `data/counters.json`, allocated under a lock just before the create (a crash leaves a gap, never a duplicate), seeded from the numbers already on disk. Editable afterwards |
| D21 | App-managed history. No git repository, no auto-commit |
| D22 | Backup = rsync over SSH, `--link-dest` dated snapshots, encrypted volume on the target. Not encrypted at rest by the app |
| D23 | lighttpd + PHP-FPM, PHP 8.1 floor, docroot is `public/` only, rewrite in server config |
| D24 | No dictation. Expansion macros (`;norm`) instead; external dictation typing into the textarea must keep working |
| D25 | Survive connection drops (IndexedDB draft carrying its base revision + 409 flow). Autosave is local only; a revision is written on Save (2026-09-26). No service worker, no offline mode |
| D26 | English chrome, Romanian content. Strings live in `lang/en.php` — no hard-coded strings in templates |
| D27 | Media in `data/media/{year}/{sha256}.{ext}`; clipboard paste and file drag only. No DICOM ingest |
| D28 | Search recall via a query-time synonym table + prefix matching on the last token. No stemmer |
| D29 | `modality` and `region` are **lists**, not scalars — combined studies (CT cerebral + cervical) are common. Index keeps `page_regions` / `page_modalities` child tables; facets count a page once per value |
| D30 | The DokuWiki H1 is the patient name: the importer lifts it to `patient.name` and the rendered H1 becomes the exam title. No identifier stays in the body. **Amended 2026-09-26 for reports created in the app:** `title` and the first `#` heading are the patient's name (how the team finds a report) and `exam_title` holds the exam; exports, the public layout and duplicates use `exam_title` and drop the name heading (`Support\ReportName`), so the name still never leaves in a PDF, a public page or a teaching copy |
| D31 | `Indicație` text stays in the body; age/sex are *copied* to frontmatter. The importer never deletes a sentence it thinks it understood |
| D32 | `import:commit` writes through `Storage` — imported pages are structurally identical to native ones (pid, rev, journal, index row, audit) |
| D33 | Public repo, **GPL-3.0-or-later**. `data/`, `conf/local.php`, `uploads/` gitignored from the first commit; fixtures are anonymised |
| D34 | PDF via **dompdf** — see the print-CSS constraint below. No headless browser |
| D7 | `required` blocks **signing**, never **saving**. A half-dictated draft always saves |
| D10 | Media content-addressed in `data/media/`, with a per-page manifest for human names |
| D11 | `patient_key = sha256(cnp)` when present, else `sha256(name|born|sex)`. Store both; timeline prefers the strong key |
| D12 | One instance, all sites, over VPN. The editor must survive a dropped connection |
| D13 | ~~One owner account, argon2id, signed session cookie. No 2FA, no user table~~ — **superseded by D35** |
| D14 | ~~No review step; the owner signs their own reports~~ — **superseded by D37** |
| D15 | AI provider interface ships with no provider enabled; `Ai\Context::build()` is the only code that may assemble a prompt |
| D35 | Multiple user accounts, admin-created only (no self-service registration). Argon2id, signed session cookie, no 2FA. Roles: `owner` (instance-wide: full read/write everywhere, manages users and grants) and per-namespace grants of `editor` (read+write, **including flipping `visibility` — D16's "deliberate, noisy act" applies equally to an editor and an owner, both audited**) or `viewer` (read) for everyone else. Anonymous visitors are unchanged: read-only, `public` visibility only |
| D36 | Accounts and grants live in `data/users/{username}.json` — disk authoritative (invariant 1), same as pages. No `user_grants` cache table in `index.sqlite`: a principal's grants are already resolved in PHP (`Session::principal()`) before any query runs, so `Search\Query::visibilityClause()` takes them as bound SQL parameters, not a join — `index:rebuild` never touches `data/users/`, because there is nothing about accounts in the index to lose. A namespace grant is a prefix match: a grant on `reports:mri` covers `reports:mri:*`, mirroring how namespaces already nest |
| D37 | Signing authority follows the write grant: any authenticated user with `editor` (or `owner`) on a page's namespace can sign that page as themselves. No review/handoff step — same no-review spirit as the old D14, generalised from "the owner" to "whoever is allowed to write here" |

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

1. `Storage\FlatFile` — revisions, atomic writes, journal replay, crash tests.
2. `Index\Sqlite` — schema, upsert, `verify`, `rebuild`. Rebuild correct **before** anything builds
   on it.
3. `Visibility` + its test matrix.
4. `Render` — markdown + macros, server-only, shared by view and export.
5. `GET /pages/{path}` + the SSR page view. Real reports on screen.
6. Print route, then PDF and ODT from the same HTML.
7. `site:home` + the public layout — cheap once the page view exists, and it settles the
   two-audience question before more routes accumulate.
7. `GET /search` + the palette.
8. `PUT/POST /pages` + the editor island (autosave, IndexedDB draft, 409 conflict flow).
9. History, diff, revert, sign.
10. Admin, tags, index management.
11. **Importer, run against the real archive** — before any further screens. Importing 4 000 real
    reports is the cheapest way to discover the schema is wrong.
12. AI endpoints, behind the disabled-by-default provider interface.
Screens are ported from the mockup in `design/` one at a time. The mockup is the visual contract:
match its markup, class names and tokens rather than reinventing layout.
