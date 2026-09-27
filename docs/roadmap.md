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

### Phase 0 — stabilise — done
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
    the real server with a real `base_url` (operator — the address is set in Admin → Settings; the
    check passes once the site is public behind its TLS proxy). Sitemap/feed: settled in phase 4.

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

### Phase 5 — links, recovery, media, ODT, tags — done
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

### Phase 7 — guided new-report form — done
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

**Built 2026-09-26** as planned. Found on the way: `--color-error`, used by the editor's error
status, is not a design token (tokens.css has no error colour) — the new form's field errors use
the accent colour until the design gets one.

**Not in this phase:** DICOM prefill (TODO.md idea 1 — the form takes prefill values so it can
plug in); multi-region sections (idea 2 — the regions chosen here seed them later).

### Phase 8 — table of contents in the right margin — done
TODO.md idea 4; decided 2026-09-26: **2+ headings** (a one-entry ToC is noise), **scroll-spy**
(the section in view is marked), **collapsed above the text on narrow screens**, **staff and
public layouts**. The mockup places no ToC, so this is our own layout, from the existing tokens.

**Why there is room.** The reading column (`.wk-panes[data-pad="read"] .wk-panebox`) is 920 px
with 36 px padding — 848 px of content — while the text (`.wk-prose`) stops at 74ch, ≈ 585 px:
about 260 px on the right stay empty. The public column (`.wk-public-doc`) is 780 px, centred, so
there the ToC takes the outer margin on screens wide enough for it.

**Layout.**
- The header (crumbs, title, badges, page tabs) and the metadata panel keep the full width.
  Below them, a two-column body: the text in `minmax(0, 74ch)`, the ToC in the rest
  (≈ 180–220 px), as a real `<nav>` *after* the text in the source order would put it out of
  reach for keyboard and screen-reader users, so it stays before the text and moves with CSS grid
  (`grid-area`), not with `order` tricks that confuse reading order.
- The ToC is `position: sticky`, `top:` the height of the sticky top bar plus a gap, with its own
  scroll when it is taller than the window (`max-height: calc(100vh - …)`, `overflow: auto`).
- Headings get `scroll-margin-top` equal to the top bar — today a ToC link can scroll its heading
  *under* the sticky bar; this fixes that for every in-page link, ToC or not.
- Wide tables in the text (report tables) keep scrolling inside the text column; they never run
  under the ToC.
- Below the width where the ToC fits beside the text (≈ 900 px of content — a breakpoint chosen by
  measuring, not guessed): one column, and the ToC becomes a `<details>` "Contents" block in
  today's place, closed. No JavaScript involved in either layout.
- Levels: h2 flush, h3 indented (as today, `level − 2` em); the entries use the existing ToC
  style (the left rule, 12.5 px), the current entry marked with the accent colour and weight.

**Scroll-spy** — a small island (`assets/js/toc.js`): an `IntersectionObserver` on the headings
marks the entry of the last heading scrolled past (`aria-current="location"`), updates on
`hashchange`, and does nothing if the ToC is absent or the browser lacks the API. Without it the
ToC is plain links.

**Where.** `templates/page-view.php` (and `/{path}@{rev}`, which renders through it) and
`templates/layout-public.php`; the ToC markup becomes one partial both include. Print, PDF and
ODT are untouched — their own templates and stylesheet (D34).

**Threshold.** The ToC is rendered only with two or more headings (the renderer already
collects them, `RenderResult::toc`).

**Built 2026-09-26.** Found on the way: rendered headings carried **no `id`** — every ToC link
(`#tehnica`) pointed at nothing. `Service\Render` now sets each heading's anchor (the ToC slug,
de-duplicated `-2`, `-3`; `section` for a heading with no letters, which used to make
`Slug::normalize()` throw and fail the whole render), and the marked preview sets the same ids
(D17 fixture `heading-anchors.md`). Beside-or-above is a container query on `.wk-docbody` (760 px
of body), so one rule serves the staff column and the public one; the public column widens to
1060 px on screens ≥ 1120 px when it has a ToC. Scroll-spy is a throttled scroll handler (one
update per frame) rather than an `IntersectionObserver` — simpler, same result.

**Tests:** no ToC with one heading, one with two; the partial in both layouts; headings keep
their slug anchors (the ToC links resolve); the `<details>` fallback is in the markup. Visual
checks at desktop, the breakpoint and phone width, light and dark, staff and public, plus a long
report and a page whose ToC is taller than the window.

### Phase 9 — a new exam for the same patient — done
TODO.md idea 8; decided 2026-09-26: carry over **site, device, modality, regions and referrer**
besides the patient; the previous **summary as is** into the indication; **priors = the report it
starts from**; the action on the **report page header** and on the **patient timeline**.

**The flow.** "New exam for this patient" opens the guided new-report form (phase 7) prefilled from
a report: `/new?after={pid}` — the pid, not the path, so the patient's name stays out of one more
URL (and out of the web server's access log). The caller must be able to read that report
(`Index::findByPid()`, 404 otherwise) and create reports under `reports:` (as for `/new`).

**Prefilled, all editable:**
- patient — name, CNP, sex, birth year (from its `patient` block);
- exam — today's date, no time; the same modality (the first, if several), site, device, regions
  and referrer;
- indication — the previous report's `summary`, as it is (empty when it has none);
- template — none selected;
- `priors` — the report it starts from, shown on the form as "After: {exam title}, {date}" with a
  remove button; a hidden field carries its path, re-validated on submit (a readable report —
  `Support\ReportPath` — or it is dropped).

Everything else is the ordinary guided form: the same-day check (a second exam the same day asks
first), the accession allocated at create, `title` and the first heading the patient's name
(D30 as amended), `exam_title` from the template or typed.

**Where the action is.**
- The report page header's ⋯ menu, on reports only (`ReportPath::isReport`), for callers who get
  the guided form.
- The patient timeline (`/{path}/timeline`): a "New exam" button starting from the newest report
  in the timeline the caller can read.

**Pieces:** `NewReport::draft()` learns `priors` (validated paths) and a `prefill(PageRecord)`
that maps a report to the form's fields; `NewPageController::form()` reads `?after=`; the header
menu item and the timeline button; strings.

**Built 2026-09-26** as planned. One change on the way: the backlinks panel counted only links in
the text, so a follow-up would not have shown on the report it follows; it now lists pages that
name this one among their `priors` too (the same `links` rows, `kind` `prior` — no schema change).

**Tests:** every carried field lands in the form; the summary lands in the indication; the new
report's `priors` holds the source and its backlinks list the new one; `?after=` of a page the
caller cannot read is 404, of a non-report is refused; a removed prior is not saved; the pid is in
the URL, never the path; imported reports (no CNP, `born: null`) prefill cleanly.

### Phase 10 — the editor's formatting toolbar — done
TODO.md idea 7; decided 2026-09-26: **no measurement macro** (D18 stands); **snippets later, in
their own phase** (D24 — TODO.md idea 9); **an Insert template button**; **Insert prior study
adds a link and the prior to `priors`**.

**The buttons** — in the mockup's `.wk-tbar` markup and order (`design/mockup/WikiEditor.dc.html`),
each a `<button type="button">` with a `lang/en.php` title. Every one works on the raw document
in the textarea:

| Group | Button | What it does |
|---|---|---|
| text | Heading | cycles the current line through `## ` → `### ` → no heading. Never `# ` — the first heading is the patient's name (D30 as amended) |
| | Bold / Italic | wraps the selection in `**…**` / `*…*`, or unwraps it; with no selection, inserts the pair and puts the cursor inside. **Ctrl+B / Ctrl+I** |
| blocks | Bullet / numbered list | adds or removes `- ` / `1. ` on every selected line (numbered lines renumber) |
| | Table | a GFM table skeleton (header row, delimiter row, one row) on its own lines; a selection of tab-separated lines — a paste from a spreadsheet — becomes that table instead |
| | Code | `` `…` `` inside one line, a fenced block around several |
| insert | Internal link | a small picker over `GET /api/v1/search` (already built — same visibility predicate); inserts the canonical `[text](ns:page)` (phase 5), the selection becoming the text |
| | Attach image | opens a file chooser, then the same upload as paste/drop (phase 5) |
| | Insert prior study | a picker of **this patient's other reports the caller can read**, newest first: exam title, date, modality. Inserts `[{exam title}, {dd.mm.yyyy}]({path})` at the cursor **and** adds the path to the frontmatter `priors` (so the backlinks panel shows it, `links.kind = prior`). Print keeps the text and drops the address (phase 5) |
| | Insert template | a picker of `templates:{modality ns}:*`, the report's own `template` preselected; inserts the template's **body** (no frontmatter) at the cursor |
| view | (char count), Split preview | as today |
| | Copy | copies the text — the body without the frontmatter — to the clipboard |

Left out: **measurement macro** (D18) and **snippets** (D24, TODO.md idea 9). The mockup's AI
buttons wait for D15.

**Rules every button keeps:**
- **Dialect only (D17).** Nothing a button writes is outside CommonMark + tables; every construct
  it can produce is a case in the render conformance corpus, so both parsers are proven to agree
  on it.
- **Undo and dictation (D24).** Edits go through `document.execCommand('insertText')`, which keeps
  the browser's undo stack (Chrome, Firefox, Safari), falling back to `setRangeText`; then an
  `input` event is fired, so the local draft (D25) sees a toolbar edit exactly like typing. The
  toolbar never listens to ordinary keys — only Ctrl+B / Ctrl+I (Ctrl+S already saves; Ctrl+K stays
  the palette's) — so external dictation typing into the textarea is untouched.
- **Frontmatter is off limits** to the text buttons: with the cursor inside the leading `---`
  block they insert after it. Only Insert prior study touches the frontmatter, and only its
  `priors` list: appends `  - {path}` to an existing block list (the shape `Yaml::dump` writes),
  or adds `priors:` before the closing `---`; skips a path already there; and when the frontmatter
  is in a shape it does not recognise, inserts the link only and says so. Nothing is saved until
  Save (D25).
- **No new endpoint.** Link search is `GET /api/v1/search`; a template body is
  `GET /api/v1/pages/{path}` (`body`); the prior-study and template lists come in the editor's
  island config (`#editor-config`), computed server-side through the same predicate as the
  timeline (`Index::findByPatientKey()`) and the new-report form's template list.
- **Works without it.** The toolbar is JavaScript (the editor island); without JS the textarea and
  Save work as today.

**Pieces:**
- `assets/js/editor-format.js` — the text transforms as pure functions (text + selection in, text +
  selection out), no DOM, so node can test them.
- `assets/js/editor.js` wires the buttons, pickers and shortcuts.
- A small picker (a `<dialog>` with a filter box and a list) shared by link, prior and template.
- `Service\PatientStudies` — the timeline's "this patient's readable studies" lookup (strong key,
  else weak), shared by `TimelineController` and the editor.
- `EditorController` adds `priorCandidates` (reports only, not the page itself) and `templates`
  to the island config.
- Strings in `lang/en.php`.
- The toolbar CSS already exists (`.wk-tbar`, `.wk-tbtn`).

**Tests:**
- **Transforms** (node, run from PHPUnit like the conformance test): each button on no selection,
  a word, several lines, and a toggle back; the priors edit on no `priors`, an existing list, a
  duplicate, and unrecognised YAML — the result parsed by PHP's YAML equals the expected list.
- **Conformance**: a corpus fixture with everything the toolbar emits — both parsers, same HTML.
- **HTTP**: the editor config lists only the same patient's reports the caller can read (a private
  report under another namespace, and a non-report page, are absent; the page itself is absent);
  the templates are the modality namespace's; a viewer never gets the editor (unchanged).

**Built 2026-09-26** as planned. On the way: the patient-studies index query now also returns
`exam_title` (from `meta_json`, no schema change), so a prior is offered by its exam, not by the
patient's name; Insert template drops a template's leading `# ` heading (D30); a toolbar edit
never lands inside the frontmatter — the body starts after its blank line.

### Phase 11 — snippets (D24's expansion macros) — done
TODO.md idea 9; decided 2026-09-26: snippets are **pages**, not a settings file (D24 amended);
**shared, plus per modality**; `;name` expands on **space, Enter or Tab**; one **`$0` cursor
mark**, nothing else.

**What a snippet is.** An ordinary page under `templates:snippets:` — history, revert, grants,
search and the editor come for free, and nothing new is stored anywhere. Its **last path segment
is its name**: `templates:snippets:norm` is `;norm`. Its body is the text that goes in (the
frontmatter is not; `title` is what the picker shows). Two levels:
- `templates:snippets:{name}` — shared, in every page's editor;
- `templates:snippets:{modality ns}:{name}` (`…:mri:norm`) — only in that modality's reports,
  where it wins over a shared one with the same name.

Deeper pages are not snippets. Which a caller gets is read through the index, so the ordinary
grants apply (a snippet the caller cannot read is not offered). Snippets are left out of the
toolbar's Insert template picker.

**Expanding.**
- `;name` expands when it starts a word — at the start of a line or after a space or an opening
  bracket, never inside one (`a;b` stays) — and is followed by a **space or Enter**, detected from
  the text just typed (the `input` event), not from key codes. So an external dictation program
  that types `;norm ` triggers it the same way (D24). **Tab** expands too: the key would otherwise
  move the focus out of the textarea, so it is caught on `keydown` only when `;name` sits right
  before the cursor.
- Only at the cursor: a paste with `;norm` inside it is left as it is. Never inside the
  frontmatter. Names match case-insensitively (`;Norm`). An unknown name does nothing.
- The snippet replaces `;name`; the space or line break typed after it stays. A `$0` in the snippet
  is where the cursor lands (the first one; it is removed from the text); without one, the cursor
  ends after the text.
- **Undo:** the replacement goes through `execCommand('insertText')` like the toolbar's edits, so
  one Ctrl+Z gives back the typed `;name`.

**The toolbar's Snippets button** (the mockup's lightning icon, left out of phase 10): a picker of
the same list — name, title, whether it is the modality's — inserting at the cursor the same way.
Shown only when there are snippets.

**Pieces:**
- `Service\Snippets` — the caller's snippets for a page: the index listing of
  `templates:snippets` and of the report's modality namespace under it, bodies read from disk
  (invariant 1), the modality's winning by name. It feeds the editor's island config (a few dozen
  short texts; no new endpoint).
- `assets/js/editor-format.js` gains the pure parts — find a trigger before the cursor, apply
  `$0` — tested in node like the phase 10 transforms; `editor.js` wires the `input`/`keydown`
  listeners and the button.
- The namespace index of `templates:snippets:` needs nothing new: its description page
  (`templates:snippets`, phase-10 follow-up) can explain the convention.
- D24 amended in `docs/DECISIONS.md` and `docs/architecture-storage-index.md` ("stored in
  settings" → pages under `templates:snippets:`).

**Tests:**
- **Transforms** (node): a trigger at the start of a line, after a space and after `(`; none
  inside a word, for an unknown name, or in the frontmatter; `;Norm`; `$0` placement; the typed
  space or line break kept.
- **HTTP:** the editor config holds the shared snippets and, in an MR report, the MR ones
  winning over a shared one of the same name; a CT report gets no MR snippets; a non-report page
  gets the shared ones only; a snippet in a namespace the caller cannot read is absent; deeper
  pages are not snippets; the Insert template list has no snippets.
- **Browser** (headless Chrome, as in phase 10): typing `;norm ` expands, Ctrl+Z gives the
  trigger back, Tab expands without leaving the textarea.

**Built 2026-09-26** as planned. One finding on the way: the browser ignores `execCommand` while
it dispatches an `input` event, and the `setRangeText` fallback has no undo step — so the
expansion runs in a microtask right after the event (before the next typed character), which is
what keeps one Ctrl+Z giving `;name` back.

### Phase 12 — multi-exam reports — done
TODO.md idea 2; decided 2026-09-27: the unit is an **exam**; a **conclusion per exam**; **one
signature per file**; an **accession per exam**; **one PDF** with a section per exam.

**Why "exam", not "region".** `region` is already a frontmatter enum (D29), and both knees are
`msk`. The unit is what the radiologist reports on (right knee, left knee), so it is called
an exam everywhere: frontmatter, URLs, UI strings and docs.

**What the archive already does.** 233 of the 4 952 reports on disk hold several exams in one
body. Each exam has its own `###` exam-title heading. 197 of them have a single shared
conclusion and about 5 have one per exam. They stay as they are: they are ordinary single-body
reports and keep working (the TOC already navigates them). Converting them is **out of scope**.
A `pages:split-exams` command can be planned later if it is wanted.

**The format.** A report becomes multi-exam only when it declares so. Nothing is inferred from
headings, because `##` is already used inside templates (`templates:mri:coloana-totala`) and as
the name heading in imported bodies.

```yaml
exam_title: 'IRM genunchi drept + stâng'   # composite, what exports show (Support\ReportName)
region: [msk]                               # union of the exams' regions
exams:
  - title: 'IRM genunchi drept'
    region: [msk]
    accession: MIO-MR-26-0412
  - title: 'IRM genunchi stâng'
    region: [msk]
    accession: MIO-MR-26-0413
```

```markdown
# {patient name}                 ← D30 name heading, unchanged

Shared text: indication, technique common to all exams (optional).

## IRM genunchi drept            ← exam 1
### Descriere
…
### Concluzii
…

## IRM genunchi stâng            ← exam 2
…
```

- With `exams:` present, the body's **level-2 headings are the exam boundaries, in order**:
  the Nth `##` is `exams[N-1]`. Everything above the first `##` is the shared head. The text of
  the heading is the exam's on-screen title. `exams[].title` is its canonical copy, and the
  editor keeps the two in step.
- Inside an exam, structure uses `###` and deeper. **Insert template** demotes a template's
  headings one level when it inserts into an exam, so its `##` never splits an exam.
- **A conclusion per exam** is a `### Concluzii` heading inside that exam's section, the shape
  3 993 archive reports already use. The exam ends at the next `##`, so a conclusion cannot
  belong to the wrong exam. The signing check looks for it **at `###` directly under the exam
  heading**, matching case- and diacritics-insensitively (`Concluzii`, `Concluzie`, `CONCLUZII`).
  Body text containing the word does not count.
- **No new syntax.** Plain CommonMark headings: the conformance test (D17) covers them
  already. HTML-comment markers were ruled out because `Render` escapes raw HTML
  (`html_input: escape`), so a marker would show as text.
- A mismatch between the list and the body is never a save error (D7). The cases are a count
  that differs, an exam without a `### Concluzii`, or an exam without a title. Each shows as a
  warning in the page view and blocks **signing**. `Signing::missing()` gains these checks
  **[ask — the signing gate]**.
- `exams` goes in `conf/schema/base.json` as a list of objects (`title` text required,
  `region` enum list, `accession` text generated). `Schema\Validator` handles nested objects
  but not a list of them; the per-exam checks live in a small `Support\Exams` (parse body ↔
  list, report problems), shared by the view, the editor config, signing and the index.
- `Support\Canonical` must stay deterministic for a list of maps (signed bytes, D3): tested.

**Addressing.** `@N` stays the revision permalink.
- View: `/{path}#exam-2`. The exam heading gets a stable id `exam-{N}` instead of its text slug.
  `assets/js/markdown-preview.js` gives the preview the same ids, as it already does for the
  TOC slugs.
- Edit: `/{path}/edit?exam=2` opens the editor on that exam. This is a query on the existing
  route, so there is no new endpoint.

**The editor — split on the client.** The server still loads and saves the whole document, so
Storage, `base_rev`/409, the IndexedDB draft and the no-JS form are unchanged.
- In a multi-exam report the island splits the document into a **head pane**
  (frontmatter + shared text) and **one textarea per exam**, with tabs (`Head`, `1 IRM genunchi
  drept`, `2 …`). Alt+PgUp/PgDn moves between tabs. Each exam has its own textarea, so each
  keeps its own native undo stack. The document is reassembled on save, for the draft and for
  the preview.
- Toolbar, snippets, paste/drop upload, character count and Insert template act on the
  **active** textarea. This is the bulk of the JS work, because today they hold one `textarea`.
  External dictation types into whichever textarea has the focus (D24).
- **Add exam**: a new tab and `##` section plus an `exams[]` entry (title, region, accession
  allocated on save, see below), the new tab already holding `### Descriere` and
  `### Concluzii`. **Remove exam** asks first. **Reorder** moves the section and the entry
  together.
- **Single → multi.** Since 2026-09-27 every report already has the shape
  `# name / ## exam / ### sections` (docs/FORMATS.md §11: the new-report form writes it and
  `pages:normalize-headings` brought the archive to it). So the first **Add exam** only writes
  `exams:`, with the current `exam_title`/`region`/`accession` as `exams[0]`, and appends the
  new `##` section. No heading moves. A report that is not in the shape yet (no `##` exam
  heading, or `## Concluzii` shared after several exams) is not split: the editor says so, and
  the exams are set up by hand. Nothing is written until Save, so a mistaken Add exam is undone
  by not saving.
- Without JS: the full document in one textarea, as today.

**Creating.** The new-report form (phase 7) gets **Exams**: one row by default (title,
regions, template), with **+ exam** for more. With one row nothing changes: no `exams:`, a
single-exam report as today. With two or more, the form writes `exams:`, one `##` per exam, a
composite `exam_title` and the union `region`. The template stays metadata-only (decided
2026-09-26) and applies per exam.

**Accessions — one per exam [ask — index schema].**
- Allocated at create, one per exam (D20: under the lock, just before the create). An exam
  added later in the editor gets its number on save. That is the same allocator; the save path
  calls it only for `exams[]` entries without one.
- Top-level `accession` is **not written** for a multi-exam report. `pages.accession` holds
  `exams[0]`'s, so every existing lookup keeps working.
- New child table `page_exams (pid, n, title, accession)`, like `page_regions`: accession
  search and "search by the 2nd exam's number" read it. **D20's seeding
  (`accessionsStartingWith`) must read it too**, or a restart would reuse the 2nd and 3rd
  numbers. That would produce a duplicate, which D20 forbids.
- Facets: `page_regions`/`page_modalities` take the union of the top level and `exams[]`. A
  stale top-level list can never hide an exam.

**Signing — one per file (D3/D37 unchanged).** A multi-exam report is signed as one revision.
Correcting one exam is a new revision, signed again; the old signed revision stays in history.
No change to `Storage::sign()`.
- **`summary` stays one per file** (decided 2026-09-27). It is a written summary covering all
  exams and is still `required_for: sign`. It is not generated from the per-exam conclusions,
  because that would copy body text into frontmatter.

**Print, PDF, ODT — one document.** One letterhead, one patient block, one signature, one
verification link. Each exam is a titled section. `Support\ReportName::withoutNameHeading()`
drops the name heading as today. `templates/print/report.php` lists every exam's accession in
the header table. dompdf rules apply (D34): block layout, `page-break-inside: avoid` on each
exam heading with its first paragraph, and a **rendered-PDF check**.

**Other surfaces.**
- Page view: the metadata panel lists the exams (title, accession). The right-margin TOC
  (phase 8) shows exams as its top level. Each exam heading carries a small **Edit** link
  to `?exam=N`.
- Timeline / same-day warning (phase 9): one entry per file, with the exam titles.
- New report for the same patient (phase 9): copies the patient block only, not the exams.
- Index: FTS gets the whole body, as today (one row per file).

**Tests:**
- **Support\Exams**: head/exam split on the fixture; an `exams:`-less page is never split; a
  `##` inside a demoted template stays inside its exam; each mismatch case gives its warning;
  `Concluzie`/`CONCLUZII` count as a conclusion, a `####` or a body sentence does not.
- **Transforms** (node): single → multi on a body with `## Concluzii` gives exam 1 with
  `### Concluzii` and one exam, not two.
- **Signing**: mismatch and missing-conclusion block signing, saving still works (D7); a
  multi-exam page signs once; `Canonical::bytes()` idempotent with `exams:`.
- **Index**: `page_exams` rows; rebuild ≡ incremental (the cache-is-disposable test); facets are
  the union; the accession allocator seeds from `page_exams` (no reuse after a rebuild).
- **Visibility**: accession search through `page_exams` goes through `visibilityClause()`.
- **Render conformance**: exam ids `exam-N` identical in PHP and in the preview.
- **HTTP**: the new-report form with 1 exam gives today's report byte for byte; with 3 exams,
  three accessions and the composite title; `?exam=2` preselects the tab.
- **Transforms** (node): split/reassemble round-trips byte for byte; template heading demotion.
- **Browser** (headless Chrome): switching tabs keeps each tab's undo; `;norm` expands in the
  active exam; save → reload → same document.
- **Rendered PDF**: a two-exam report, both accessions in the header, the patient's name in the
  patient block (once; the name heading is dropped from the body), each exam a titled section
  with its own conclusion.

**Docs in the same commit:** `docs/FORMATS.md` (the `exams:` format and the heading rule),
`docs/architecture-storage-index.md` (`page_exams`, D20 seeding),
`docs/architecture-api.md` (`?exam=N`, the editor tabs), and D20/D29 notes in
`docs/DECISIONS.md`.

**Built 2026-09-27**, in five commits on `feat/multi-exam`. Where it differs from the plan:
- **Undo across tabs.** The plan assumed each exam's textarea keeps its own undo. Chrome keeps
  one undo history per page: after typing in exam 1, Ctrl+Z in exam 2 did nothing. So Ctrl+Z
  in the tabs walks back through the report's changes, newest first, showing the tab it undoes in,
  and Ctrl+Shift+Z / Ctrl+Y walk forward (checked in headless Chrome, a snippet's one-step undo
  included).
- **The exams list is not text in the editor.** The Head tab shows the frontmatter without
  `exams:`; the tabs are the list, and `assets/js/editor-exams.js` writes it back on every edit
  (titles from the `##` headings). An `exams:` list in a shape the script does not read (flow
  style, an unknown key) keeps the plain single textarea, with a note.
- **Single → multi** needs no YAML from the browser: the editor writes `exams:` with titles only,
  and on save `Service\ExamAccessions` moves the old top-level accession to exam 1 and numbers
  the new ones (also on `PUT /api/v1/pages/{path}`).
- **Edit links** per exam sit in the metadata panel's Exams row (`/edit?exam=N`), not on each
  heading in the text. `?exam=N` is read by the island, so it is tested in the browser, not HTTP.
- **Accession search is new**: FTS never indexed accessions. An accession typed whole finds its
  report first, by any exam, under `visibilityClause()` (a case in the visibility matrix).
- **The on-disk seed** (`AccessionFormat::issuedOnDisk`) reads every `accession:` line in the
  frontmatter, not only the first — without it a lost `counters.json` would reissue exam 2's
  number.
- **Anchors count `##` lines only**, like the split: a setext `---` heading is never an exam, in
  PHP or the preview (conformance fixture `multi-exam.md`).
- **Duplicates** keep a multi-exam report's `exams:` (titles and regions, so the copy's `##` still
  match) but never their accessions (D20).
- The timeline now titles every report by its exam (it showed the patient's name on every row)
  and lists a multi-exam report's numbers; the same-day warning shows the exam title.

### Phase 13 — the API as a full client: tokens, signature details, the missing endpoints
Asked 2026-09-27: "does the API cover all major actions we would perform on a page?" Mostly —
create, save (409), metadata and visibility (with the D16 acknowledgement), sign, revert,
duplicate, move, delete/restore, media, search, render; PDF/ODT through `/export`. The gaps:
no way to authenticate but the HTML login form; creating a *report* is a raw create (no path
from the patient, no accession, no CNP-derived fields, no same-day check); no history, one
revision or diff; no patient timeline; `Idempotency-Key` not implemented although CLAUDE.md
says writes honour it. Decided 2026-09-27: **API tokens, created by each user on their own
profile page**, and **the profile edits its own signature details** (name and title).

**13a — API tokens [account model: asked for 2026-09-27].**
- **What a token is.** A secret the user creates on `/profile` and names ("dictation script",
  "laptop"): `rpn_{base64url(username)}.{id}.{secret}` — `id` 8 base32 characters, `secret` 32
  random bytes base64url (the username is encoded because it may itself contain `_` or `.`). Shown **once**, at creation. `data/users/{username}.json` keeps, per token, `id`,
  `name`, `scope`, `created`, `last_used` (a date, written at most once a day) and
  `sha256(secret)` — never the secret (disk authoritative, D36; nothing in the index).
- **Scope**: `read` (GET only) or `write` (everything the account may do). A token never has more
  than its account: grants, owner and deactivation are read from the account on every request,
  so deactivating a user stops their tokens at once. A password change does not revoke tokens;
  revoking is explicit.
- **Where it works**: `Authorization: Bearer rpn_…` on `/api/v1/*` and `/export/*` only — never on
  the HTML routes, so a token cannot drive a form and the SameSite cookie stays the only way to
  use the screens. A bad, revoked or out-of-scope token is 401 `{error: {code: "invalid_token"}}`
  (a read-scope token writing: 403 `insufficient_scope`), never a silent anonymous fallback.
  `Http\Session::principal()` stays the one place a request becomes a User: cookie first, then
  the bearer token where it applies.
- **Profile page**: a Tokens panel — name, scope, created, last used, Revoke; a form for a new one
  (name, scope) that shows the token once. Later: an owner seeing and revoking anyone's tokens in
  Admin → Users (never creating them for someone else); until then, deactivating the account
  stops them all.
- **Audit**: `token.create`, `token.revoke` (id and name, never the secret); API writes are audited
  as the account, with the token id in `extra`. Tokens compared with `hash_equals()`.
- **Tests**: a token reads and writes as its account; `read` scope cannot write; revoked, unknown,
  malformed and a deactivated account's tokens are 401; a token is ignored on HTML routes; the
  secret is nowhere on disk; the visibility matrix holds for a token as for a cookie.

**13b — signature details on the profile.** `display_name` and `title` (docs/FORMATS.md §10, the
signer block of a printed report) become editable on `/profile` by the account itself — today
only an owner sets them, in Admin → Users. Audited `profile.change`. Already-signed reports keep
what they printed: the signature record stores the signer's name at signing time.

**13c — endpoints [ask — new endpoints, rows in docs/architecture-api.md first].**
- `POST /api/v1/reports` — the guided new-report form as JSON, through `Service\NewReport`
  (`draft()` + `create()`): `{patient: {name, cnp?, sex?, born?}, date, time?, modality, site,
  device?, regions?, referrer?, indication?, template?, title?, priors?, exams?: [{title,
  regions?, template?}], confirm_same_day?}` → 201 `{pid, path, rev, accessions}`; 422 with
  `fields` (the form's own messages); 409 `same_day` with the reports already there unless
  `confirm_same_day: true`.
- `GET /api/v1/pages/{path}/revisions` → `{data: [{rev, ts, by, note, kind, signed}], page}`;
  `GET /api/v1/pages/{path}/revisions/{rev}` → that revision's `meta` and `body`, and its
  signature when it has one (the `/r/{pid}/{rev}` check, as JSON);
  `GET /api/v1/pages/{path}/diff?from=&to=` → the line diff (`Support\Diff`), as the compare view.
- `GET /api/v1/pages/{path}/timeline` → the patient's reports the caller can read
  (`Service\PatientStudies`, the same predicate — invariant 6); a visibility-matrix case first.
- Templates and snippets need nothing new: `GET /api/v1/pages?ns=templates:mri` lists them; say so
  in the API doc.
- **`Idempotency-Key`** (docs/FORMATS.md §7) on `POST /pages`, `POST /reports`, duplicate and sign:
  the response to a repeated key within 24 h is replayed, not redone — kept under
  `data/idempotency/` (disposable: losing it only loses the replay). Or drop the promise from
  CLAUDE.md; either way code and doc agree.

**13d — orphan page files (found 2026-09-27).** `data/pages/reports/current.md` and
`data/pages/reports/mri/mioveni/current.md` — an old welcome text, no `meta.json`, no `rev/`, not
in the journal — made the directory look like a page, so creating the `reports` description
page gave `reports-2`. Storage is right to treat a half-written page as taken; what is missing is
seeing it: `index:verify` (and Admin → Maintenance) should list page files with no `meta.json`
that the journal does not account for, as a report line — never deleting them itself.

Order: 13a and 13b first (asked for); 13c after its rows are agreed; 13d with 13a.

**13a and 13b built 2026-09-27** (`feat/api-tokens`), as above; the owner's view of others'
tokens is left for later. 13c and 13d are still to do.

### Phase 14 — the editor shows the report, the details have their own form
TODO.md idea 11; asked 2026-09-27: the medic editing a report sees **only its text**; the details
(frontmatter) are edited **in a form made for them**; the **raw page** (frontmatter and text as one
file, as today) stays one click away. The file on disk does not change: frontmatter and body stay
together (invariant 2) — this is only how they are shown and edited.

**The text editor (the Edit tab, the default).**
- The textarea holds the **body only**. The form posts `body` + `base_rev` (not `document`), and
  the save keeps the page's current frontmatter — already the rule for a document without a
  block (2026-09-27), made explicit here so a body that happens to start with `---` is never read
  as frontmatter.
- Everything else stays: toolbar, snippets, paste/drop, preview (it already renders the body), the
  local draft (keyed now by path and mode, so a text draft and a raw draft never mix), 409 with
  both texts.
- **Two things the text editor changes in the frontmatter** today, kept with their own fields:
  *Insert prior study* also adds to `priors` → posts `add_priors[]`; the **exam tabs** (phase 12)
  keep `exams:` in step with the `##` headings → post `exam_order` (each tab's original index, or
  `new`), and the server rebuilds `exams:` — titles from the headings, regions and accessions by
  identity, a new exam numbered on save (`Service\ExamAccessions`). The Head tab then holds only
  the shared text. `assets/js/editor-exams.js` loses its YAML writing.
- A new page (the editor opened on a path not written yet, 2026-09-27) starts from an empty text;
  its frontmatter is guessed on the first save (`Service\FrontmatterGuess`), then edited in the form.

**The details form (`GET/POST /{path}/details` — a Details tab in the page header, and the
metadata panel's pencil on the page view) [ask — new SSR route].**
- Generated from `conf/schema` for the page's modalities (`Schema\Loader::fieldsFor()`), one
  control per type: `text` → input (textarea for `indication`, `summary`); `enum` → select, and
  checkboxes when `list`; `datetime` → date + optional time; `ref: sites/devices` → the configured
  sites and their devices; `int`/`float`/`bool` → number/checkbox; `list of tag` → chips; `list of
  page` (`priors`) → the prior-study picker; `object` `patient` → name, CNP (sex and birth year
  derived, as on the new-report form), sex, born; `exams` → a small table (title, regions;
  accession read-only). Fields `required` / `required_for: sign` are marked, never enforced (D7).
- **Not here**: `visibility` (its own screen, with D16's acknowledgement), `status` and
  signatures (signing), `accession` (read-only; editable in raw, D20 "editable afterwards"),
  `imported_from`/`review` (import bookkeeping, read-only).
- **Fields the schema does not know** are listed read-only with "edit in the raw page" — the form
  never drops what it does not show: it saves the page's frontmatter **with only the form's fields
  changed** (`Storage::save()` with the merged array), which is the tension idea 11 names.
- Saves a new revision like any edit (`base_rev`, 409 when the page moved on). Signed report: the
  form saves a new draft revision like the editor does (D3), with the same notice.
- The frontmatter is written through the same YAML path as every save, and signing canonicalises
  bytes itself (`Support\Canonical`), so no serialization concern for signatures.

**The raw page (`/{path}/edit?raw=1`, a "Raw" link in the editor's toolbar) — today's editor.**
The whole file in one textarea, frontmatter included, same save. **Open question for the owner:
every writer, or owners only?** (Recommendation: every writer — it is the escape hatch for a field
the form does not have — but not the default and not in the tab row.)

**API**: nothing new — `PATCH /pages/{path}/meta` and `PUT` with `{meta, body}` already split them.

**Tests**: the text editor never shows `---`/YAML and saving it keeps every frontmatter field; a body
starting with `---` stays body; the details form round-trips every schema type, keeps unknown
fields, marks required ones, refuses nothing on save (D7), 409 on a stale `base_rev`; exam tabs
rebuild `exams:` by identity (a removed exam's accession goes with it, a new one is numbered);
`add_priors[]`; raw mode unchanged (the existing editor tests, pointed at `?raw=1`); drafts per mode;
a browser check that a medic's round trip — edit text, edit details — never shows YAML.

Order: the details form first (it is what lets the text editor drop the frontmatter), then the
text editor, then the raw link and the exam tabs' rework.

### Later (deferred by the milestone doc)
Share tokens, integrations/AI, vectors, importer against the real archive (build step 11).
