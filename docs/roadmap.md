# Roadmap — state as of 2026-09-25

A snapshot of where the build stands against `CLAUDE.md`'s build order, `docs/milestone-1.md` and
the mockup in `design/`, and the plan to close the gaps. Items marked **[ask]** need explicit
approval under the working agreement (dependency, on-disk layout, index schema, new endpoint,
signing/revision code, account model). Update this file as phases land.

## Findings

### Tests

487 tests; 11 failing (all fixed in phase 0): 8 in `tests/Cli/ImportCommitCommandTest` / `ImportRollbackCommandTest`
and 3 in `tests/Import/AccessionAllocatorTest` (a nonexistent `assertStringContains()`, hiding a
real D20 mismatch — see phase 0 item 6).

### Drift from the mockup

1. **Blank icons.** Templates use ~57 Phosphor icons (`ph ph-*`) but no Phosphor font/CSS is
   loaded; only `templates/rail.php` renders icons, via Font Awesome. The mockup is Phosphor
   throughout.
2. **No shared layout.** Every screen template carries its own `<!doctype>`, `<head>`, search form
   and palette config. Only page view, edit, history, compare and timeline have the Workbench
   shell; `/{ns}:`, `/search`, `/new`, `/admin/users`, delete-confirm still use the flat `.wk-top`
   chrome, and theme only applies where the rail is. In the mockup every pane lives inside the shell.
3. **CSS values diverged.** Of 119 `.wk-*` selectors shared between `design/mockup/Wiki.dc.html`
   and `assets/css/wiki.css`, 51 have different declarations (media-query overrides excluded) — e.g. `.wk-edit` lost its
   `minmax(0,1fr) 328px` AI rail, `.wk-two` lost its 262px facet column, `.wk-mono` uses
   `--font-mono` instead of `--w-mono`. 150 mockup selectors are absent (some belong to the
   unchosen shells / phone preview; the rest are real gaps: tree, timeline `wk-tl*`, errors
   `wk-err*`, stats, admin grid, sign panel).
4. **Screens not built:** error states, profile, tags, integrations, four of five
   admin tabs, the signed-in dashboard (`/` serves `site:home` to everyone). Timeline, admin and
   print are missing most of their mockup classes.
5. **Never checked in a browser** — `docs/BUILD_LOG.md` says so after every chrome slice.

### Mockup placeholder data rendered as fact

Porting screens verbatim copied the mockup's sample data into live templates, so users see numbers
and settings that are not true — worse than an inert button:

- `templates/search-results.php`: every facet (modality, region, site, device, status, tags,
  saved queries) is hard-coded with invented counts — including an `amended` status that does not
  exist.
- `templates/new.php`: template list with fabricated usage counts; an ACL field pre-filled with
  `@radiology:rw @mioveni:rw @referrers:r` (the group syntax `design/README.md` rules out); a
  visibility radio (`name="nv"`) and "notify referrer" checkbox the server never reads;
  `lang/en.php` `new.path_free` hard-codes a sample patient path.
- `templates/compare.php`: "static mockup content only" by its own docblock.
- `templates/editor.php`: AI rail and toolbar present but inert.

### Functional gaps

- **Milestone 1 incomplete** (build step 6 skipped while 8–9 shipped): `templates/print/report.php`
  has no route, dompdf is unused; `/{path}/print`, `/export/{path}.pdf`, `/{path}@{rev}`,
  `/r/{pid}/{rev}` do not exist.
- **No audit log** — no `data/audit/`, although D16/D35 require visibility flips and publishing to
  be audited and invariant 8 specifies `path_hash` there.
- **No plugin loader** — `src/Plugin/` absent while `plugins/export-pdf-letterhead` declares hooks.
- **API:** `GET /pages`, `GET /pages/{path}`, `PATCH /pages/{path}/meta`, move, duplicate, restore,
  share, `/s/{token}`, sitemap, feed, media.
- **CLI:** `page:new`, `page:move`, `trash:purge`.
- **Import:** `import:commit` "priors resolution" never resolves a pid (both branches produce the
  same `['path' => …]`).
- **Editor:** marked.js loaded from `cdn.jsdelivr.net` although `public/assets/marked.js` is
  vendored (breaks on the VPN-only deployment, D12); the preview feeds the whole document,
  frontmatter included, through `marked.parse()` into `innerHTML` unsanitised.
- **Small:** session cookie lacks `Secure` (TODO in `Http\Session`); `page-view.php` constructs
  `DateTimeImmutable` from raw frontmatter unguarded.

### Docs vs code

`docs/architecture-api.md` still described the editor as SSR-only, `/new` without the segmented
builder and history without multiselect compare, although all three shipped; the timeline shipped
at `/{path}/timeline` (doc: `/patient/{key}`) and the palette uses `/api/v1/search` (doc:
`/search/suggest`). Reconciled in phase 0.

## Plan

Verification against a fixture data directory only — this checkout is the live install; never run
CLI commands against the real `data/` as a non-web user.

### Phase 0 — stabilise
1. ~~Fix the 8 failing import CLI tests.~~ Done. Root causes: tests read the live
   `data/import-map.json` and built `FlatFile` on the wrong root; `import:rollback` deleted
   edited imported pages (status stays `archived` through an edit — now protects rev > 1 and pid
   mismatches); both commit commands logged the whole `PageRecord` as `pid` and the requested
   rather than allocated path (fixed; rollback reads legacy logs).
2. ~~Icons: one icon set, matching the mockup.~~ Done: Phosphor regular 2.1.2 (MIT) self-hosted as
   `assets/css/phosphor.css` + `assets/fonts/phosphor-regular.woff2`, linked from every page
   template; rail switched to the mockup's glyphs; Font Awesome removed.
3. ~~Reconcile `docs/architecture-api.md` with shipped code.~~ Done (editor, `/new`, history,
   compare, timeline, palette rows).
4. ~~Remove every piece of mockup placeholder data.~~ Done. Removed rather than wired: search
   facets/saved queries/AI answer/"N of M"/fake timing; `/new` template list, ACL, visibility,
   notify and HL7 metadata panels; compare's canned clinical text (now renders the two real
   revisions via `Render::toHtml()` with a no-JS rev picker); editor AI rail, inert toolbar,
   ignored "minor" and "sign on save" checkboxes; login's false session/audit claims, "trust
   device" and literal `%d` stats; public page's invented CC BY-NC licence and dead export /
   copy-link; page ⋯ dead links (Revert now points at history); history's unwired diff-mode toggle;
   "Revert to last signed" and "default for new reports here" labels that described no real feature.
   `/new`'s path builder now works without JS (server assembles the segments) and no longer
   re-roots non-report namespaces under `reports:`.
5. ~~Editor preview.~~ Done: vendored the marked build the conformance test runs (14.1.4 — the
   repo had 17.0.1 and the editor loaded the CDN's latest); one shared config
   (`assets/js/markdown-preview.js`) used by the editor and `tools/render-with-marked.js`;
   body-only preview; raw HTML escaped and unsafe URLs dropped exactly as `Service\Render` does,
   pinned by a new conformance fixture.
6. ~~Accession sequence vs D20.~~ Decided: per site + modality + year (D20 amended).
   `AccessionAllocator` now keys `site:MOD:yy` and seeds from accessions already on disk, so a
   new batch never reissues a number (the per-batch counters used to start at zero). Still open:
   native `create()` allocates no accession at all (D20's `data/counters.json`); 803 SCUC pages
   (plus a few at other sites) were imported with modality `other`, so their accessions read
   `SCUC-other-…` — decide whether to map them to a real modality before more batches land.

**Surfaced by the cleanup, now tracked:** no UI can sign a report (only
`POST /api/v1/pages/{path}/sign`) — a Sign action belongs in the phase 1 page header
**[ask: signing code]**; real search facets need an index facet query (phase 4); the removed
rename/move/duplicate/visibility actions return with their routes (phase 4).

### Phase 1 — one shell: Reading room + royal blue / lime / amber (A6) — done
Decided 2026-09-25 (`design/README.md` §"Chosen direction"), built on branch
`feat/reading-room-shell` and verified in headless Chrome against a fixture data directory.
7. ~~Palettes + `POST /palette`.~~ Royal blue / lime / amber, dark + light, body classes from the
   mockup's `data-bpal` values; allowlisted cookie; `return_to` guard shared with `POST /theme`.
8. ~~One layout.~~ `templates/layout.php` via `Http\View::page()`: sticky top nav (☰ drawer,
   search with ⌘K/Ctrl K, + New, namespace index, Admin, theme, palette, account / Sign in).
9. ~~One page header.~~ `templates/page-header.php` on view, edit, history, compare, patient,
   delete: crumbs, title, badges, tab row, ⋯ (revert, delete). Assistant / Export / rename-move-
   duplicate join it with their backends. The drawer (`templates/drawer.php`, `assets/js/
   shell.js`) replaces the worklist; rail, tab strip, worklist sidebar and status bar are deleted,
   `Index::namespaceStats()` with them.
10. ~~Port every screen.~~ All 11 signed-in screens; `tests/Http/ShellTest.php` walks them under a
    sub-path (one top nav, page header where expected, every link prefixed).
11. ~~CSS re-sync.~~ Drifted values restored from the mockup, missing rules ported (namespace
    cards, `.wk-menu-r`), dead rules for removed UI dropped; `--font-mono` kept as the token name.
12. ~~Browser pass.~~ Page view + editor in all 6 palette/theme combinations, every screen at
    390px, palette/theme/typeahead interactions; no JS errors. Fixed from it: phone table
    overflow, platform-correct shortcut hint, light-theme tags, native control colours.

**Surfaced by phase 1:** the anonymous namespace index and search render in the signed-in shell
(with Sign in) rather than `layout-public.php`; the public layout still ignores theme/palette;
Timeline still carries an unused `$storage` (phpstan).

### Phase 2 — finish milestone 1 — done except the operator checks
13. ~~`/{path}@{rev}` and `/r/{pid}/{rev}`.~~ Read-only (`Service\Revisions`); a signed
    revision shows signer, parafa, digest and whether it still matches the stored bytes;
    `Index::findByPid()` covered by the visibility matrix.
14. ~~Print and PDF.~~ `/{path}/print` + `/export/{path}.pdf`, one template (D34), Export ▾ in the
    page header; signer block from account display name/title (new, `/admin/users`); drafts
    refused as PDF; anonymous public exports without the patient block; no QR yet (text link).
15. ~~Audit log.~~ `data/audit/YYYY-MM.ndjson`: writes, signs, exports, logins (reads not yet);
    best-effort by design, `doctor` checks the directory.
16. `docs/milestone-1.md` updated: 7 of 10 met at the end of phase 2; the dashboard landed in phase 3 — open now: `doctor` on
    the real server with a real `base_url` (operator), sitemap/feed (phase 4).

**Surfaced by phase 2:** PHPStan reports 14 errors that predate this work (import commands,
`AccessionAllocator`, `SyntaxConverter`, unused `TimelineController::$storage`); a QR code on
exports needs a dependency decision; the router does not escape literal characters in route
patterns (harmless so far).

### Phase 3 — missing mockup screens — done
17. ~~Error states.~~ `Http\ErrorMapper::render()`: signed-in 404 in the shell (path, search,
    "Create this page" for writers via `/new?path=`), one bare 404 for anonymous (private and
    missing indistinguishable), generic 500, JSON on `/api/…`.
18. ~~Tree sidebar~~ — Console shell, not chosen; the ☰ drawer covers it.
19. ~~Signed-in dashboard.~~ `/`: worklist across visible namespaces (`Index::listRecent()`,
    visibility matrix), filter chips as links, My drafts.
20. ~~Timeline to mockup.~~ Counted stats + `.wk-tl`; `/patient/{key}` decided against (a
    brute-forceable CNP hash must not be in URLs).
21. ~~Profile and admin tabs.~~ `/profile` (own password, current required, ≥ 10 chars),
    owner password reset, Admin → Index & storage (status, drift, rebuild as the web user;
    `Service\IndexMaintenance` shared with the CLI). Site settings and plugins tabs not built.

**Surfaced by phase 3:** changing a password does not end other sessions (no session
versioning); pages named `profile`, `search`, `new` or `admin` at the root would be shadowed
by those routes.

### Phase 4 — API and CLI gaps — done
22. ~~`GET /pages`, `GET /pages/{path}`.~~ Filters, paging; listing predicate; anonymous never
    gets the patient block.
23. ~~`PATCH /pages/{path}/meta` and visibility.~~ `Service\Publishing`: D16 confirmation +
    acknowledgement + `page.publish` audit; **signed reports refused** (visibility is inside the
    signed digest — publish a duplicate or correct-and-re-sign).
24. ~~Move, duplicate, restore, purge, `page:new`.~~ Redirect stubs (chains collapse at write
    time), links rewritten in unsigned pages only; duplicate carries exam fields, never patient
    fields; Admin → Trash + API restore; `DELETE ?purge=1` and `trash:purge` with the D3b
    override; `page:new --template=`. Journal intents for rev-keeping ops are keyed by op.
25. ~~Sitemap and feed.~~ Feeds for allowlisted non-report namespaces only (`feeds.namespaces`),
    public and patient-free pages; sitemap decided against.
26. ~~Plugin loader~~ — not built: no plugin needs it (PDF export lives in core). The
    `plugins/export-pdf-letterhead/` skeleton targets classes that were never built.
27. ~~`Secure` cookie flag, guarded date parsing.~~ Secure from the request, not config; dates
    print without a time when none was given.

### Phase 5 — links, recovery, media, ODT, tags
Decided 2026-09-26.

28. ~~**Journal replay that runs.**~~ Boot replay of intents older than 60 s (incremental journal
    read, non-blocking lock; starts from the journal's end the first time, so an existing backlog
    is left to `journal:replay --dry-run`), plus `journal:replay`. Fixed on the way: a superseded
    intent rolled `current.md` back; a discarded intent was never closed.
29. ~~**Internal links.**~~ `[text](ns:page)` canonical, the imported `ns/page` resolved at render
    time too (signed reports untouched), both parsers + conformance under a mounted base path;
    print/export unlink; body links indexed, backlinks fill; forward references resolve. Importer
    writes the canonical form and no longer mangles URLs. **Live needs `index:rebuild`** for
    backlinks to appear.
30. ~~**Media upload.**~~ Paste/drag in the editor → `POST /api/v1/media` (raw body), content
    addressed in `data/media/{year}/`, `media.json` per page, `GET /media/…` by visibility
    (`links.kind = media`), print/PDF embed, D16 preview counts images.
31. ~~**ODT export**~~ via PHPWord from the print HTML (PclZip: this server has no zip extension).
32. ~~**Admin → Tags**~~: counts, rename, merge; unsigned pages only.

**Found and fixed in phase 5: the editor autosave flattened frontmatter** (since the island
landed): nested `patient` → '', lists emptied, quotes doubled — on every autosave. Fixed and
deployed ahead of the phase; `pages:check-frontmatter [--repair --actor=]` finds and repairs the
damage from the last intact revision (signed pages listed, not repaired).

**Still open after phase 5:**
- Import link remapping: a DokuWiki id whose page landed at a different path (PathMap) still
  links to the old id. Belongs with the real-archive import (build step 11).
- Links to a moved page from signed reports resolve through the stub but do not count as
  backlinks (the `redirects` table is never filled).
- Unreferenced media is never swept; `paths.media` in the config is not used (media lives under
  `paths.data`).
- D17: emphasis inside image alt text (`![a *b*](…)`) renders differently in the two parsers —
  not in the fixtures; worth a decision (strip it, or fix one side).
- ODT: line breaks inside a table cell collapse (the letterhead's right column).

### Phase 6 — admin tools — done
Asked for 2026-09-26.

33. ~~**Admin → Maintenance.**~~ The maintenance commands (`journal:replay`,
    `pages:check-frontmatter`, `index:verify`, `trash:purge`) as `Service\Maintenance` tasks run
    from the browser and the CLI alike: check first, confirmed apply, Post/Redirect/Get, one lock
    shared with `index:rebuild`, stored run reports (pid-only JSON, also `--json` on the CLI).
34. ~~**Admin → Settings.**~~ This instance's settings — site name, tagline, public address, home
    page, time zone, icon, sites and devices, feeds, export switches, limits — in
    `data/settings.yaml`, edited from the admin screen; `conf/local.php` keeps paths and secrets
    (and fallbacks until the first save). Not moved: default theme/palette (a per-browser cookie
    today) and the planned-but-unread sections of the example config (accession, signature,
    search, ai, index, plugins).

More ideas to study: `TODO.md`.

### Phase 7 — guided new-report form — planned
TODO.md idea 3; decided 2026-09-26: **one name field**, **CNP optional** (checksum-validated,
fills sex / birth year / age), **accession generated at create** (D20, native — not built until
now), **`reports:` only** (other namespaces keep the path field). The screen is the mockup's
`WikiCreate.dc.html` (path builder, template picker, metadata panel), with the "HL7 prefill"
panel entered by hand — the place a DICOM prefill (TODO.md idea 1) plugs in later.

**The form** (`/new` under `reports:`, and the + New button when the caller can write there):
- *Patient* — name, one field, typed as `POPESCU Ana Maria`; CNP (optional). With a CNP, sex,
  birth date and age at the exam date are shown and stored; without one, sex (M/F) and birth year
  by hand. A CNP that fails its checksum, or disagrees with a hand-entered sex, is refused.
- *Exam* — date (`<input type="date">`, today by default) and optional time; modality (from
  `conf/schema/*.json`: MR, CT, US, XR, MG); site and device (from Admin → Settings → Sites and
  devices, devices filtered by site); regions (checkboxes, the `region` enum — a list, D29);
  referrer and indication (optional; indication is required later, to sign).
- *Template* — the pages under `templates:{modality-ns}:` (D19), or an empty body. The body and
  exam fields are copied (`Service\Duplicates` — never patient fields); the template's title is
  the report's title unless typed over.
- *Preview*, recomputed server-side on every submit (and live with a small script):
  `reports:mri:mioveni:260926-popescu-ana-maria`, `next accession: MV-MR-26-0042`, and — if the
  index already has a report for this patient (strong or weak key, D11) on this date — "a report
  for this patient and date exists: … create another?" with an explicit confirm (FORMATS §1).
- *Create & open editor* → one `Storage::create()` (private, draft), audited `page.create`, then
  the editor. Everything works without JavaScript.

**Pieces, in build order:**
1. `Support\Cnp` — checksum, sex, birth date (century from the first digit: 1/2 → 1900s,
   3/4 → 1800s, 5/6 → 2000s, 7/8/9 residents/foreigners → century from the date), age at a date.
   Tests use CNPs *generated* by the checksum rule, never real ones (invariant 10).
2. `Service\NewReport` — validates the form into a path and a frontmatter; the path is D1's
   `reports:{modality-ns}:{site}:{yymmdd}-{slug(name)}` (`Support\Slug`, 64 chars), with a
   modality → namespace map (MR → mri, CT → ct, US → us, XR → xr, MG → mg) in Admin → Settings.
   `patient: {name, sex, born, cnp?}` (born = year, as the schema has it), `study_date`,
   `modality: [..]`, `region: [..]`, `site`, `device`, `referrer`, `indication`, `template`,
   `visibility: private`.
3. `Service\Accessions` — the live `data/counters.json` (D20): per site + modality + year,
   seeded from the highest number already on disk the first time a key is used (the same rule
   `Import\AccessionAllocator` follows — shared, not copied), incremented under a lock. Allocated
   immediately before the create, so a crash between the two leaves a gap, never a duplicate —
   the docs say "inside the journal-protected create"; they get corrected to this. Checked
   against live: imported accessions read `SCUC-MR-23-1764` — `{SITE}` is the site code upper-cased,
   `{MOD}` the schema's modality code (MR, not RM), a 4-digit sequence — so new reports continue
   the same series. An optional per-site `accession code` in Admin → Settings → Sites (e.g. MV)
   overrides the upper-cased site code; left empty, nothing changes.
4. `NewPageController` — the guided form for `reports:`, the raw-document form everywhere else
   and behind an "advanced: path and raw document" link; `?from=` duplicates keep working.
5. The duplicate-day check — `Index::findByPatientKey()` + study date, through the listing
   predicate (invariant 6), shown by title and date only.
6. Docs: architecture-api (`/new`), FORMATS §1 (the collision prompt) and §3 (counters.json),
   D20 wording, CLAUDE.md.

**Tests:** CNP rules (valid/invalid checksums, each century digit, derived age at a date); path
and slug (diacritics, compound names, the `-2` collision suffix); accession sequence per key,
seeding from disk, concurrent allocation, never reissued; the form creates exactly the expected
frontmatter; the patient never reaches the audit line (path_hash only); a viewer or a caller
without a grant on `reports:` gets 404; the duplicate-day confirm; the template copy carries no
patient fields; the raw form still works for other namespaces.

**Not in this phase:** DICOM prefill (TODO.md idea 1 — the form takes prefill values so it can
plug in); multi-region sections (idea 2 — the regions chosen here seed them later).

### Later (deferred by the milestone doc)
Share tokens, integrations/AI, vectors, importer against the real archive (build step 11).
