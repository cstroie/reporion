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
    **Built 2026-09-29** for the first real plugin, `plugins/hipobridge` (TODO.md idea 1) — only
    the hook, route prefix and slots it uses (docs/architecture-api.md §5). The letterhead
    skeleton still targets classes that do not exist; enabling it fails to load, harmlessly.
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
  `conf/schema/*.json`: MR, CT, US, XR, MG); site and device (from Admin → Sites and
  devices, devices filtered by site); regions (checkboxes, the `region` enum — a list, D29);
  referrer and indication (optional; indication is required later, to sign).
- *Template* — the pages under `templates:{modality-ns}:` (D19), or an empty body. The body and
  exam fields are copied (`Service\Duplicates` — never patient fields); the template's title is
  the report's title unless typed over.
- *Preview*, recomputed server-side on every submit (and live with a small script) — the
  Preview button itself was removed 2026-09-29; the live preview and the re-render stay:
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
   the same series. An optional per-site `accession code` in Admin → Sites (e.g. MV)
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
| | Insert prior study | a picker of **this patient's other reports the caller can read**, newest first: exam title, date, modality. Inserts `[{exam title}, {YYYY-MM-DD}]({path})` at the cursor **and** adds the path to the frontmatter `priors` (so the backlinks panel shows it, `links.kind = prior`). Print keeps the text and drops the address (phase 5) |
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
- ~~**`Idempotency-Key`**~~ — **dropped** (owner, 2026-10-09): the promise is gone from CLAUDE.md,
  the API doc and FORMATS §7; nothing stores keys.

**13d — orphan page files (found 2026-09-27).** `data/pages/reports/current.md` and
`data/pages/reports/mri/mioveni/current.md` — an old welcome text, no `meta.json`, no `rev/`, not
in the journal — made the directory look like a page, so creating the `reports` description
page gave `reports-2`. Storage is right to treat a half-written page as taken; what is missing is
seeing it: `index:verify` (and Admin → Maintenance) should list page files with no `meta.json`
that the journal does not account for, as a report line — never deleting them itself.
**Built 2026-10-09** in `integrity:verify` (so Admin → Maintenance and cron too), not
`index:verify`: `FlatFile::strayPaths()` lists directories with `current.md` or `rev/` but no
`meta.json` that no open journal intent explains; each is a `stray_files` problem by path hash,
left in place.

Order: 13a and 13b first (asked for); 13c after its rows are agreed; 13d with 13a.

**13a and 13b built 2026-09-27** (`feat/api-tokens`), as above; the owner's view of others'
tokens is left for later. 13d built 2026-10-09; 13c's endpoints are still to do (its
`Idempotency-Key` item dropped).

### Phase 14 — one editor, a Details panel instead of raw YAML
TODO.md idea 11; asked 2026-09-27, revised 2026-09-27 (this section replaces an earlier draft of the
same phase, which chose a separate `/{path}/details` route — kept in git history, superseded by the
"Decided" note below after weighing it against the owner): the medic editing a report sees **only
its text**, with the details (frontmatter) edited **in a form made for them**, collapsed above the
body by default; the **raw page** (frontmatter and text as one file, as today) stays one click away.
The file on disk does not change: frontmatter and body stay together (invariant 2) — this is only
how they are shown and edited. **One page, one form, one Save, one revision** — body and details
submit together, never as two separate saves.

**Decided with the owner (2026-09-27):**
- **One form, not a separate `/{path}/details` route.** A collapsible "Details" panel inside the
  same `/{path}/edit` page, above the body textarea (`<details class="wk-panel">`, open unless the
  visitor collapsed it last time — a local, per-browser preference, not a setting). One submit: no
  stale `base_rev` between two saves, no losing typed body text by navigating away, one revision and
  one revlog entry per edit, exactly as today.
- **Visibility moves into this form**, gated by the same acknowledgement `PATCH /pages/{path}/meta`
  already has (`Service\Publishing::needsAcknowledgement()`/`preview()`): choosing "public" without
  ticking the acknowledgement box re-shows the form with the preview (what becomes visible) instead
  of saving. This closes a real gap — today's raw-YAML editor lets anyone flip `visibility: public`
  by typing it, with no preview and no acknowledgement at all; only the API's `PATCH …/meta` has that
  gate. `POST /{path}/edit` gains the same one.
- **A field the schema does not have a picker for stays out of the form entirely**: read-only,
  named, with a link to raw mode. No inline "other fields" YAML box — the Details panel is always
  plain native form controls, never a second YAML parser. An unusual field is still visible (never
  silently dropped, still there in the read-only list), just edited in the one place that already
  parses arbitrary YAML safely.

**The body textarea.**
- Holds the **body only**. `Storage::save()` is called with the composed frontmatter (below) and
  this body text directly — no `DocumentFormat::encode()`/`parseOrBare()` round trip through a
  `document` field for this path, so there is no `---` line to accidentally parse out of the body.
- **Safety net**, since the complaint that started this ("might get corrupted") is exactly this: if
  the posted body starts with `---\n` (a whole document pasted into the textarea — copy-paste from
  raw mode, or from another report), the save is refused with a message ("This looks like a whole
  page, frontmatter included — paste just the text, or use raw mode") rather than silently storing
  the `---` block as body text. A `Support\DocumentFormat`-level check, one line, reused by both this
  route and the create path.
- Everything else about the body editor is unchanged: toolbar, snippets, paste/drop, preview, the
  local IndexedDB draft, 409 handling. The draft's stored shape changes (body + a snapshot of the
  form's field values, not one `document` string) — bump the draft's version key so an old-shape
  draft from before this phase is discarded on load, never restored into the new fields wrongly.
- *Insert prior study* (toolbar) still inserts text; it now also ticks that prior in the Details
  panel's `priors` picker (below) rather than posting a separate `add_priors[]` field — one list,
  one source of truth. *Insert template* (toolbar) still only inserts the template's body text, as
  today — it has never touched the frontmatter's `template:` key, and does not start now.

**The Details panel — schema-driven, native form fields, one merge.**
- Built from `conf/schema` for the page's modalities (`Schema\Loader::fieldsFor()`, the same class
  `Schema\Validator` already uses for signing — this is its first UI-facing use). One control per
  type: `text` → input, or a textarea for `indication`/`summary`/multi-line fields; `enum` → select;
  `enum` + `list: true` → checkboxes; `int`/`float` → number input; `bool` → checkbox; `datetime` →
  a date input (`study_date` has no meaningful time component in this schema); `ref: sites` /
  `ref: devices` → the configured sites and their devices (`Service\NewReport::options()`'s sites
  array, reused, not rebuilt); `ref: templates` → the same picker `EditorController::templates()`
  already builds for the toolbar's Insert Template button; `list of text` (`tags`) → a
  comma-separated input, matching admin screens' convention for short lists (`sites[i][devices]`'s
  `CODE = name` lines are a different case — plain comma list is enough here); `object` `patient` →
  name, sex, born, CNP, laid out like the new-report form's patient block (sex/born derivable from
  CNP client-side, same script). `visibility` → the three radios plus the acknowledgement box,
  shown only when picking "public" from something else (mirrors the admin publishing screen's
  pattern for a conditional field). Fields `required` or `required_for: sign` get a small marker
  (a dot, title="required to sign") — **never enforced here**: D7 still blocks only signing.
- **Not in the form**: `status` and signatures (their own actions — sign, archive, revert),
  `accession` (read-only here; still editable in raw mode, D20 "editable afterwards" is unchanged),
  `imported_from`/`import_batch`/`review` (import bookkeeping, read-only), `pid` (never editable),
  `exams` (its own mechanism below — the exam tabs already solve this at a finer grain than a
  generic list-of-objects control could).
- **The merge, server-side, one rule reused in two places**: extract the shared "apply frontmatter
  changes to a page" logic already in `Service\Publishing::apply()` (null removes a key, `status`
  is always skipped, a signed page is refused) into a small pure step
  (`Publishing::merge(array $current, array $changes): array`, or similar) that both `PATCH
  /pages/{path}/meta` and this route call — one merge behaviour, not two copies of it. Start from
  `$record->frontmatter`, overlay the submitted field values; a field the form did not render is
  never touched, because it is never in `$changes` at all.
- **The empty-vs-absent trap** (a real pitfall: an unticked checkbox, an empty multi-select and a
  cleared text input are all simply *absent* from the POST body — indistinguishable, at the HTTP
  level, from "the browser didn't render this field"). The form posts one hidden marker per field it
  rendered (`fm_shown[]=region`, one per curated field on the page, always present regardless of
  that field's value): a shown-but-empty field means "clear this key" (→ `null` in `$changes`,
  removed); a field with no marker was never rendered (a modality-specific field for a different
  modality, say) and is left alone. This is the one new piece of plumbing this phase adds beyond
  what `Publishing::apply()` already does, and it is exactly what a checkbox/multi-select needs to
  be told apart from "not shown" safely.
- A signed report: editing the Details panel creates a new draft revision exactly as editing the
  body already does (D3) — same notice, no new rule.
- The frontmatter is written through the same YAML path as every save (`Storage::save()`), and
  signing still canonicalises its own bytes (`Support\Canonical`) — no serialization concern for
  signatures either way.

**Exams (phase 12) — the highest-risk piece, scoped narrowly.**
- The exam tabs keep working exactly as they do today for the **body** side: one pane per `##`,
  Add/Remove/Move, the shared Head pane. What changes is what the Head pane *is*: today it is a
  textarea holding the whole frontmatter YAML (minus `exams:`) plus the shared text above the first
  exam; after this phase the Head "pane" is the Details panel (above) plus a small body-only
  textarea for just the shared text (the indication paragraph, say) — no YAML visible there either.
  `assets/js/editor-exams.js` stops parsing/writing frontmatter text (`parseExams()`/`dumpExams()`'s
  job on the `exams:` block goes away with it) — it keeps splitting/joining the **body** into panes,
  which is the part it is already tested for and good at.
- **`exams:` itself is rebuilt server-side from the tabs**, not edited as a list-of-objects form
  control (a generic UI for that would re-invent the tabs badly): each pane posts its identity (the
  exam's original 1-based index, or `new` for one added this save) and, from the pane's own first
  line, its `##` title — the same "title comes from the heading" rule `Support\Exams::split()`
  already applies when reading a saved document, just computed once more server-side from what was
  posted. The server reassembles `exams:` in the panes' current order, keeping each existing entry's
  `region`/`accession` by identity (a moved or renamed exam keeps its number — D20, "a duplicate is
  never issued"), allocating a fresh accession only for a `new` entry (`Service\ExamAccessions`,
  unchanged). A per-exam `region` picker in the UI is **out of scope for this phase** — today's raw
  mode is still where a per-exam region gets corrected, same as today; the page-level `modality`/
  `region` fields (the combined-study lists, D29) are in the Details panel like any other field.
  This keeps the exam-tabs rework to "where do titles/accessions come from", not "rebuild the whole
  per-exam metadata UI" — a fair line given exactly one report in the archive is multi-exam today.
- `EditorExamsTest`'s `open()`/`join()` cases that exercise frontmatter text (`parseExams`,
  `dumpExams`, the `at` splice point) go away with that code; new tests cover the identity-by-index
  reassembly instead (a removed exam's accession goes with it and is never reissued; a reordered
  exam keeps its own accession; a renamed `##` updates that exam's title, not another's).
- A single-exam report or a non-report page needs none of this — the Details panel and a plain body
  textarea are the whole story there, which is the common case (4 951 of 4 952 reports in the
  archive today).

**Raw mode (`/{path}/edit?raw=1`, a link near the Details panel) — today's editor, unchanged.**
The whole file in one textarea, frontmatter included, same `document`/`base_rev` save path as
exists today (`DocumentFormat::parseOrBare()`, the wholesale-replace round trip) — this phase does
not touch it beyond adding the link. Available to every writer, not owners only: it is the escape
hatch for a field the Details panel does not have, same reasoning as the read-only-fields link
above. A local draft made in raw mode is keyed separately from one made in the split editor (the
"draft shape" versioning above already covers this), so the two never overwrite each other's
autosave.

**API**: nothing new. `PATCH /pages/{path}/meta` keeps being the API's own frontmatter-only path;
this phase's `Publishing::merge()` extraction is what the editor's `POST /{path}/edit` starts
sharing with it, not a new endpoint.

**Tests**: the body textarea never shows `---`/YAML, and saving it keeps every frontmatter field the
form did not touch, including keys the schema does not know about; a body pasted with a leading
`---` block is refused with a message, not silently split; the Details panel round-trips every
schema field type; a field for a modality the page does not have is never rendered and never
touched by `fm_shown[]`'s absence; `visibility → public` without the acknowledgement box re-shows
the form with the preview and saves nothing; `required`/`required_for: sign` are marked, never
enforced (a draft with none of them still saves, D7); 409 on a stale `base_rev` shows both the
submitted body and the current one, as today. Exam tabs: identity-by-index reassembly (removed →
its accession is retired, not reissued; reordered → keeps its own; renamed heading → that entry's
title only). Visibility matrix: unaffected (this phase changes how visibility is *edited*, not who
can see what). A browser check: a medic's round trip — collapse Details, edit the body, expand
Details, change one field, Save — produces one revision, and the raw page never shows in that flow
unless asked for.

Order: `Publishing::merge()` extracted and shared first (touches nothing user-facing, is the safety
net everything else leans on), then the Details panel for the common single-exam/non-report case,
then the body-only textarea and its `---`-paste guard, then the exam-tabs rework, then the raw-mode
link and draft-versioning cleanup.

### Phase 15 — the AI assistant, ported from DokuLLM — done
TODO.md idea 12; planned 2026-09-27 from a study of the DokuWiki plugin (`~/work/DokuLLM`) and its
`reports` prompt profile (imported here as `dokullm:profiles:reports*`). D15 reserved this: the
provider interface and `Ai\Context::build()` chokepoint; D8/A3: AI text streams to a rail and a
human inserts it; the editor mockup's Assistant rail (`design/mockup/WikiEditor.dc.html` `.wk-ai*`).
The rule that shapes it: **invariant 8 / D1 — the patient's name, path and identifiers never reach a
prompt**; DokuLLM sends the whole page (name heading included) and example pages by path.

Decided with the owner 2026-09-27: provider **OpenAI-compatible server** only; **all 12 reports
actions + custom**; prompts in **new `ai:` pages converted to Markdown**; style examples by **FTS5
now, vectors later**. New endpoints `POST /api/v1/ai/complete` and `GET /api/v1/ai/providers` and
the Admin → Settings AI section approved with the plan; a **remote** provider still needs the
explicit external opt-in (`ai.external_ack`).


#### 1. Core services — `src/Service/Ai/` (namespace `Reporion\Service\Ai`)
- `ProviderInterface` — `complete(ChatRequest): Generator<string>` (yields text deltas), `name()`.
- `OpenAiCompatibleProvider` — curl (ext present) to `{endpoint}/chat/completions`, `stream: true`,
  parses SSE `data:` lines; model/temperature/top_p/max_tokens from settings; `Authorization:
  Bearer` from `conf/local.php` `ai.api_key` (secret, never in settings.yaml). `models()` via
  `GET {endpoint}/models` for the admin dropdown. Strips `<think>…</think>` across chunk
  boundaries (a small stateful filter, from DokuLLM's `stripThinkTags`/`removeBetweenXmlTags`).
- **Egress guard** in the provider constructor: the endpoint host must be loopback/private *or*
  listed in `ai.allow_egress_to`; otherwise it throws — enforced in code, not docs (storage doc §8).
  A non-local host also needs `ai.external_ack: true` (owner ticks "report text, without
  identifiers, leaves this server"), shown on the rail footer as "provider: external".
- `Context` — **the only prompt builder** (D15). `Context::build(Action, PageRecord, string $text,
  options): Prompt` resolves placeholders from server-side sources, then **de-identifies**:
  - never includes any page path; `examples` cite nothing but "exemplu N";
  - drops the D30 name heading (`Support\ReportName::withoutNameHeading`) from every document;
  - redacts, case- and diacritics-insensitively, each document's `patient.name` (whole and each
    part ≥ 3 letters) → `[pacient]`, CNP-shaped 13 digits → `[CNP]`, accession-shaped numbers
    (`Support\AccessionFormat::regex`) → `[nr]`;
  - keeps age and sex (copied to frontmatter by D31; linter needs them) and study dates (compare);
  - **final guard**: if any known identifier (this page's and every included page's name parts,
    CNP, path segments) still occurs, refuse with `ai_identifier_leak` — never send.
  Returns `Prompt {system, user, contextSet}` where `contextSet` is what the rail footer and the
  audit line list ("this exam", "template", "2 priors", "5 examples", "no patient identifiers").
- `Actions` — reads a profile's action pages (below) through the index (grants apply), returns
  `[{id, label, tooltip, icon, result, scope, order}]` for the island config; loads prompt bodies
  from disk (invariant 1).
- `Examples` — `{snippets}`: FTS5 over **signed** reports the caller can read
  (`visibilityClause`), same modality, excluding this patient (`patient_key`), query = the current
  text's salient terms (DokuLLM's `extractQueryText` idea), top N sections cut by `### ` headings,
  each de-identified by `Context`. Vectors (sqlite-vec + embeddings) are a later phase.

#### 2. Prompt pages — `ai:profiles:{profile}:…` (disk, editable, versioned)
- One page per action, `ai:profiles:reports:{id}`: **frontmatter** carries the table's columns —
  `label, tooltip, icon, result (show|append|replace|insert), scope (selection|exam|body), order,
  enabled` — body = the user prompt. `ai:profiles:reports:system` = base system prompt;
  `ai:profiles:reports:system:{id}` = per-action appendage (a page and namespace may share a name).
  Fallback `ai:profiles:default:{id}` (DokuLLM's rule). No table parsing.
- Which profile: `ai.profiles` setting, longest namespace prefix wins (`reports → reports`,
  `'' → default`).
- **`bin/reporion ai:import-prompts --from dokullm:profiles:reports --to ai:profiles:reports
  --actor=<u> [--dry-run]`**: reads the imported DokuLLM pages (index page table → frontmatter; the
  `system-2` collision → `system`), applies deterministic rewrites (DokuWiki heading instructions
  → Markdown: `======` title → dropped, `=====` sections → `###`; "format DokuWiki" → "Markdown";
  "titlu: numele pacientului" instructions removed), creates the pages through Storage, and prints a
  **review list** of lines still mentioning DokuWiki markup or the patient's name. Also
  `dokullm:profiles:default → ai:profiles:default`. Prompts stay in `data/` (never committed:
  public repo, invariant 10). The owner then reviews them in the editor.

#### 3. Placeholders (resolved only in `Context`)
| Placeholder | Reporion source |
|---|---|
| `{text}` | the text the editor sends: selection, else the active exam pane (phase 12), else the body — de-identified |
| `{template}` | frontmatter `template` page body (`Duplicates::document` shape), else "( fără șablon )" |
| `{previous}`, `{previous_date}` | `priors[0]` (readable by caller), body de-identified; its `study_date` |
| `{current_date}` | this page's `study_date` (DokuLLM used the page id's date) |
| `{snippets}` | `Examples` (FTS) |
| `{examples}` | explicit pages from frontmatter `ai_examples:` (optional) — replaces `~~LLM_EXAMPLES~~` |
| `{exam}`, `{modality}`, `{region}`, `{age}`, `{sex}` | frontmatter (`exam_title` of the active exam) |
| `{prompt}` | the custom action's free text |
| `{current_time}`, `{action}` | as DokuLLM |
DokuLLM's `~~LLM_TEMPLATE/EXAMPLES/PREVIOUS~~` body directives are not carried over — frontmatter
(`template`, `priors`, `ai_examples`) already says it. Tool calls (get_document/get_template) are
not ported: context is assembled deterministically so the chokepoint and audit see all of it.

#### 4. Endpoint (approved with the plan; a row in docs/architecture-api.md when built)
`POST /api/v1/ai/complete {path, action, text, exam?, prompt?}` — caller must `canWrite(path)`
(404 otherwise, invariant 9). `Accept: text/event-stream` → SSE `event: delta` / `event: done
{ms, usage, contextSet}` / `event: error {code}`; otherwise one JSON `{result, ms, usage,
contextSet}`. Needs a small `Http\StreamedResponse` (today `Response::send()` echoes one body),
`set_time_limit(ai.timeout)`, and lighttpd `server.stream-response-body = 2` (docs/deploy-lighttpd.md
+ a `doctor` check); without it the island gets the JSON fallback. `GET /api/v1/ai/providers` →
configured provider status (D15's listed endpoint) for Admin → Integrations. The action list goes in
the editor's island config — no endpoint.

#### 5. The editor rail (the mockup's `.wk-ai`)
- `templates/editor.php` renders `<aside class="wk-ai">` only when a provider is configured and the
  profile has actions (D15: hidden otherwise); `.wk-edit` regains the mockup's two-column grid
  (`minmax(0,1fr) ~328px`, collapsing under the container query), CSS ported from
  `design/mockup/Wiki.dc.html` `.wk-ai*` lines 215–241, sized to the 18/14 scale.
- `assets/js/editor-ai.js` (island part, pure transforms node-tested like `editor-format.js`):
  action buttons (label, icon, tooltip); a *Custom* input; output cards streaming in, header "label ·
  1.9 s · 412 tok", buttons **Insert at cursor / Replace / Apply / Regenerate / Copy**, and the
  *Context sent* chips + "provider · model · audit logged".
- Result modes, applied with `execCommand('insertText')` so Ctrl+Z undoes them (phase 10 pattern):
  `show` → card only; `insert` → at cursor; `replace` → selection, else the active exam's text below
  its `##`; `append` → **section merge**: if the result starts with a `###` heading the active exam
  already has (e.g. `### Concluzii`), replace that section, else append to the exam. Works per exam
  tab (phase 12) and on the Head/plain textarea.
- Nothing is saved by the assistant (A3). When AI text was applied, the Save posts
  `ai_assisted=conclusion,quality…`; the server appends "asistat: …" to the revision note and adds
  `extra.assisted` to the `page.save` audit line. **D8 amended**: the revision is the doctor's; the
  note and audit say which parts the assistant proposed (there is no separate `assistant` actor).

#### 6. Settings, audit, operations
- Admin → Settings gains an **AI** section (`InstanceSettings`/`AdminSettingsController::SECTIONS`):
  `ai.enabled, ai.endpoint, ai.model (dropdown from models()), ai.temperature, ai.top_p,
  ai.max_tokens, ai.timeout, ai.profiles, ai.allow_egress_to, ai.external_ack`; `ai.api_key` stays
  in `conf/local.php`. `bin/reporion ai:check` (and a doctor line): reach the endpoint, list models,
  egress verdict — never sends report text.
- Audit `ai.call`: actor, pid, `path_hash`, action, provider host, model, `contextSet`, ms,
  tokens, outcome — **never** prompt or answer text (invariant 8). A leak refusal is audited
  `ai.refused`.
- Load: a local model can hold a PHP-FPM worker for a minute; one in-flight call per user (a lock
  file per username), and the deploy doc notes `pm.max_children`.

#### Phasing (each its own commit with tests)
- **15a** provider + egress guard + `Context` chokepoint + audit + settings section + `ai:check`.
- **15b** prompt pages: `Actions`, profile resolution, `ai:import-prompts` (owner reviews prompts).
- **15c** `POST /api/v1/ai/complete` (SSE + JSON), `StreamedResponse`, deploy/doctor notes.
- **15d** editor rail + `editor-ai.js` + result modes + `ai_assisted` note.
- **15e** `Examples` (FTS) for `{snippets}`.
- Later: vectors (sqlite-vec, embeddings), "extract findings → metadata"/`summary` fill (after
  phase 14's details form), the compare view's AI delta, rail actions on the page view.

**Tests:**
- **Context (unit)**: for a fixture report with name/CNP/accession, the built prompt contains none
  of them nor any path segment; the name heading is gone; `[pacient]` substitution is
  diacritics-insensitive; a prior and a snippet from *other* patients are de-identified too; the
  final guard refuses a planted leak. Placeholder table resolved per action; missing template/prior
  gives the fallback text.
- **Provider**: against a local fake OpenAI-compatible server (a PHP `-S` script in tests) —
  streaming deltas reassemble, `<think>` split across chunks is stripped, errors map to codes;
  egress guard throws for a public host not allow-listed.
- **HTTP**: `ai/complete` 404 for anonymous / viewer / no grant; JSON and SSE shapes; audit line has
  no text; rail hidden with no provider.
- **Import**: fixture DokuLLM profile → `ai:` pages with frontmatter from the table; review list
  flags leftover `=====`.
- **Examples**: only signed, readable, same-modality, other-patient sections; visibility matrix case.
- **JS (node)**: section-merge for `append` (replace an existing `### Concluzii` in the active exam,
  else append), replace-exam-body, insert-at-cursor.
- **Browser (headless Chrome, fixture data + fake provider)**: click *Conclusion* in an exam tab →
  card streams → Apply replaces that exam's conclusion → Ctrl+Z restores → Save note says
  "asistat: conclusion".
- **Live, by the owner**: `sudo -u www-data bin/reporion ai:check`, then one action on a draft.


**Built 2026-09-27** on `feat/ai-assistant` (15a–15e, one commit each). Where it differs from the plan:
- **No `models()` dropdown** in Admin → Settings: the model is typed; `bin/reporion ai:check` lists the
  server's models and says whether the configured one is among them.
- **Streaming is asked for in the JSON** (`"stream": true`), not by `Accept`; `Response` gained a
  streamed body rather than a separate class. No `doctor` check for lighttpd's
  `stream-response-body` — it is in docs/deploy-lighttpd.md; without it the answer arrives at once.
- **Prompts are the instance's configuration**: `Service\Ai\Actions` reads `ai:` pages whatever the
  caller's grants (an editor under reports: need not read ai:); editing them is the ordinary rule.
- **`{snippets}`** draws from reports that are signed **or archived** (the imported archive is
  archived), of the same modality, never this patient's (`patient_key`), via
  `Index\Sqlite::styleExamples()`; the best-matching `###` sections, at most six, 900 characters each.
- **Audit field `ai_action`** (the audit line's own `action` is `ai.call`).
- Live prompts: after the owner moved the import's `-2` pages into place, `ai:import-prompts` reads
  `dokullm:profiles:reports` and `…:system` directly; ~20 lines of DokuWiki wording are listed for
  review.

### Phase 16 — UI polish and small fixes (TODO.md idea 13) — done except two deferred decisions
TODO.md idea 13's unchecked list (six earlier items already shipped, see there) is one screen or
two of copy/CSS/JS each — no schema change, no new endpoint, most needing no plan beyond "do it" —
so they land as one phase, grouped by surface, rather than 27 one-line commits with no through-line.
Two items touch enough to need a decision first, flagged **[ask]** below; everything else is a
small fix done in whatever order is convenient, each its own commit.

**A — Chrome and visual polish.**
- Site icon: replace the favicon with a Phosphor glyph (matching the tab-icon convention already
  used elsewhere), rendered to the sizes browsers ask for.
- The `Authorization: Bearer rpn_…` token-value styling (`.wk-mono.wk-dim`, profile tokens table)
  renders oversized in at least one context — a CSS bug, not a redesign.
- Status and visibility labels/pills get palette-tinted backgrounds (draft/signed/archived,
  private/unlisted/public) instead of uniform grey, from the existing token shades — no new colours
  (CLAUDE.md: "do not invent new colours").
- Under 925px the `.wk-menu-r` menus (page header ⋯, Export, etc.) stop being reachable — a
  responsive-breakpoint bug, fix the underlying rule rather than adding a second menu.
- `.wk-toc` (page view) and `.wk-toc-narrow` (phase 8) are two rules for one component; unify them
  and drop whichever is now redundant.
- The Namespaces drawer gets a "root / top namespace" entry (today it lists only namespaces that
  exist below the root).

**B — Search.**
- Result sort: by relevance (today's default, unchanged) or by recency — a control, not a setting.
- Restrict a search to one namespace (a facet or a `ns:` query prefix — pick whichever the palette
  and `/search` share already support most cheaply).

**C — Editor: toolbar and typing.**
- Bullet/numbered list button: inserting on an empty line should insert the first marker
  (`- `/`1. `), not remove the line — today's behaviour is backwards from phase 10's spec.
- Enter inside a list continues it (next `- `/`N. `), the one common list interaction phase 10 left
  out.
- Drop the standalone **Preview** button; keep **Split preview** only, laid out side-by-side
  (today's is top/bottom) — one less button, matches the mockup's two-pane intent.
- "advanced: path and raw document" becomes a button (secondary style) next to Preview/Create,
  not a plain link (phase 7's form).
- Each rendered `<code>` fenced block gets a small translucent icon-only **Copy** button, top-right
  corner, copying the block's raw text to the clipboard — view and print-preview screens, JS only
  (`navigator.clipboard`), no server change.

**D — History and compare.**
- Rename the "History & diff" button/tab to **History** (the screen and its diff mode are
  unchanged — copy only).
- Remove the **Revert** page-header menu item: History's own revert action (phase 2) already
  covers it, so this drops a second path to the same write, not a feature.
- **[ask]** Compare's diff render: today's line-level diff becomes a **word-level** diff rendered
  as marked-up prose — red strikethrough for deletions, green (no strikethrough) for insertions,
  inline in reading order, closer to a word processor's tracked changes than a unified diff. This
  changes what `/{path}/compare` computes and renders (`Support\Diff` gains a word-tokenised mode)
  — worth a short plan: tokenising CJK/Romanian diacritics correctly, dompdf-safe markup if compare
  ever prints, and where the line-level view (if any) still applies (e.g. frontmatter changes,
  which are structured, not prose).
- Refactor the compare screen's from/to/compare toolbar — same three controls, tidied layout, no
  behaviour change beyond what the word-diff item above requires.

**E — Namespace index and its listing table.**
- "Pages in this namespace" table gains columns: region (report pages only), status, visibility,
  updated, by — title becomes a link to the page; "by" shows the account's display name everywhere
  a byline appears (page header, history, audit views), never the bare username — one rule, applied
  in the one place bylines are rendered, not per screen.
- "Add description" jumps straight to `/{ns}/edit` — no intermediate `/new` step — matching how
  "Edit description" already works once a description exists.
- Move "Edit description" next to "New page" as a secondary button (today it reads more like a
  passive label than an action — TODO idea already logged this once, done for that button; this is
  its position, not its style).
- The namespace description page's rendered body moves below the pages table, as plain rendered
  HTML (no card/panel chrome) — matches how a page's own body reads elsewhere.
- Subnamespace cards show title (or id, if no description page exists yet), summary and a page
  count — today's cards are sparser.
- Later, not this phase: per-row page actions in "Pages in this namespace" (rename/move/duplicate
  from the table) — noted, not built.

**F — Namespace frontmatter [ask: schema/behaviour].** A namespace's own description page gains
meaning beyond free text: `title` (shown as the namespace's H1 instead of the raw path), `tags`,
`summary` (a styled subtitle) and `visibility` (shown as a pill, and read as the **default**
visibility offered to a new page or subnamespace created underneath — today every new page defaults
to `private` regardless of where it's created). The visibility-as-default part changes create-time
behaviour (`Service\NewReport`/`NewPageController` read the nearest ancestor namespace's default),
so it wants a short plan: how "nearest ancestor" is resolved, whether it's a hint or a hard default,
and how it interacts with a caller who lacks write on the namespace they're defaulting from.
Same pass: namespace description pages and non-report pages drop the `template` field from their
schema/form — it only ever meant something for reports.

**G — New-report form (phase 7 follow-ups).**
- Template search matches on the template's own title *or* on `page:namespace` — today a search for
  `ct` misses `templates:ct:…` because only the title is matched.
- Patient row: CNP | Sex | Birth year | Age as four flex columns (age computed client-side from
  birth year/CNP, not entered).
- The template-picker rows (`.wk-tpl-i`: name, page code) go two-column when there's room, shorter
  rows.
- Reorder the form so Exams precedes Template (exams are entered before picking what fills them).
- Shorten "Create & open editor" to "Create".
- The top-toolbar **+ New** button creates a page in whatever namespace the caller is currently
  viewing, and defaults to a plain page there rather than always routing to the guided report form
  — the guided form stays what `reports:` offers, but `+ New` elsewhere shouldn't force a detour
  through it.

**H — Patient matching on the timeline/patient tab.** Propose other exams that may belong to the
same patient by **name**, not only by the strong CNP key or exact weak-key match (D11) — spelling
variants, a CNP present on one exam and missing on another. Surface candidates for the user to
preview and confirm/allocate, never merge automatically. **Correction while building this phase:**
`conf/patient_merges.json` is named in CLAUDE.md's repo layout as D11's stated escape hatch, but it
was never built — nothing reads or writes it. This phase's "surface candidates" half needs no merge
step at all (a read-only name match); "select to allocate" would need that file's format decided
first, so it stays out of this phase.

**I — Account menu.** Show the signed-in user's full name and title directly (as already shown on
exports and the signature block) instead of "Signed in as {username}" — a small template change,
same data `Auth\User` already carries.

**Built 2026-09-28**, groups A–E and G–I as planned, each its own commit (C and D landed together —
`assets/css/wiki.css`, `lang/en.php` and `docs/architecture-api.md` picked up edits from both
before either was committed, so splitting them by group added no real review value). Where it
differs from the plan:
- **D's word-diff [ask]** was built anyway, since the "should I go ahead" answer came back yes
  mid-phase: `Support\Diff::words()` (same LCS as `lines()`, tokenised on whitespace-preserving
  runs, adjacent same-op runs coalesced so a multi-word change is one `<ins>`/`<del>`, not one per
  token) replaces the side-by-side rendered panes with a single inline track-changes read; the old
  panes stay as the fallback when a revision's frontmatter does not parse (nothing to tokenise).
  CJK tokenising and a print/export path were never a concern — Compare has neither.
- **F's [ask] half was skipped, its non-ask half was not**: title/tags/summary read straight off
  the description page's own frontmatter (already-generic fields on any page, no schema change) and
  `template` moved from `FrontmatterFields::BASE` into `REPORT` — both display-only, no decision
  needed. "Visibility as the default for new pages/subnamespaces underneath" — genuinely a
  create-time behaviour change — was not built; it still wants the plan above.
- **H found `conf/patient_merges.json` was never built** (see H's note) — the phase's "surface
  candidates" (`Index::findPossiblePatientMatches()`, an FTS phrase match against just the `title`
  column, excluding the patient's own confirmed patient_key/patient_key_weak) needed no merge
  format decided, so it shipped; "select to allocate" still does.
- **Byline resolution** (E) turned out cheap for every screen, not only the namespace table: a
  `display_name()` global (`src/lang.php`, mirroring the existing `t()`/`reporion_instance()`
  pattern — one `username => User::signatureName()` map built once per request in `Kernel::boot()`
  from `UserStoreInterface::all()`) needed no controller signature changes, so it also covers
  history's author column, the page header's "edited by" line, the drawer and the dashboard
  worklist — not just the table the TODO line named.
- **G's advanced-path button** turned out to belong to the new-report form (`templates/new-report.php`),
  not the editor toolbar where group C's plan first placed it — the TODO line was about the
  guided form's own Preview/Create row.

**Not in this phase:** TODO.md idea 14 (namespace "importance" levels, brainstorm-stage), the
still-open multi-exam Metadata-panel gap from idea 11, per-row page actions in the namespace
table, and the two deferred decisions above (namespace-visibility-as-default, patient-merge
allocation) — each needs its own plan first.

**Patient-merge allocation, built 2026-09-28.** H's deferred "select to allocate" half: confirming a
possible match now writes an explicit `patient.key` frontmatter override onto the target page, set
to the source page's own patient_key (strong, else weak) — decided as a page-frontmatter write, not
a `conf/patient_merges.json` file, so it stays disk-authoritative (invariant 1) and is undone by
deleting the field. `Index\Sqlite::write()` prefers the override over the cnp-derived key.
`Service\PatientMerge` + `Controller\PatientMergeController` (`POST /{path}/patient-merge`,
audited as `patient.merge`); the timeline template gained per-match Confirm/Dismiss actions. Not
persisted: "Not the same patient" only hides the row in the browser — a dismissed match can
resurface on the next visit, since there is nowhere yet to remember a rejection.

### Phase 16 — plugin loader and the HippoBridge plugin (2026-09-29)

TODO.md idea 1, as the owner framed it: worklist prefill "the way XRayVision does it", over the
FHIR interface of HippoBridge (the Hipocrate HIS bridge), in two directions. Decided with the owner
before building: plugin loader + plugin in this repository; priors under the `reports:` tree
(archived — see D38 for why not draft); a found report gets its blanks filled and its priors
appended; one service account; CT + MR performed in the last 3 days by default.

- **Loader** (`src/Plugin/`, docs/architecture-api.md §5) — only what the plugin uses: one hook
  (`report.prefill`), routes under `/x/{id}`, two ui slots, Admin → Plugins with manifest-typed
  settings. `order_ref` + `Index::findByOrderRefs()` (FORMATS §3e).
- **Direction 1** — `GET /x/hipobridge/worklist`: `/fhir/Schedule` per modality (HippoBridge's
  own notes: an unfiltered query can drop CT rows), performed statuses only, reported orders link
  to their report. "Start" → `/new?prefill=hipobridge&ref={slug}.{id}` → `/fhir/ServiceRequest/{id}`
  fills name, CNP, date/time, modality, region, exam title, referrer, indication, `order_ref`.
- **Direction 2** — a report's ⋯ → "Priors from HIS" (`/x/hipobridge/priors/{pid}`): the patient by
  CNP, else by name (several → the user picks); a CNP that differs from the report's stops
  everything; the patient's exams of the configured types, this report's own exam pre-selected by
  day + modality; the ticked ones' `/fhir/DiagnosticReport/{id}` become archived pages; the report
  gets one revision with the missing CNP/sex/born/referrer/indication/order_ref and the priors.
- **Tests** — `tests/Http/PluginsTest.php` (loader, fixture plugin), `tests/Plugin/HipobridgeTest.php`
  (a fake HippoBridge with made-up patients).

**Not in this phase:** DICOM C-FIND (idea 1's other half); the HIS's own report being linked to an
`accession`; writing anything back to the HIS; the plugin hooks the architecture doc lists but no
plugin uses yet.

### Phase 17 — patient timeline: report-vs-prior compare, AI course summary, export dossier

`templates/timeline.php`'s docblock: "the mockup's AI course summary, 'compare two' and 'export
dossier' are not built." Folds in `WikiCompare`'s other, still-open half (`design/README.md`:
"its report-vs-prior-*report* `?with=` compare only... **Not built, still open**" — not to be
confused with the same-page revision diff, which `Revisions` absorbed in phase 8/9's work). Three
separate features, one phase because they all live on the Patient tab.

- **17a — report-vs-report compare [ask: new route].** `GET /{path}/compare?with={pid}` (or picked
  via two checkboxes on the timeline, same "cap at two, second pick navigates straight there"
  pattern `templates/revisions.php` already uses for same-page diffs — reuse it, don't reinvent
  a picker). Renders the mockup's **side** style only (`.wk-cmp`, two full pages through
  `Render::toHtml()` — `templates/revisions.php`'s `side` style already does exactly this for two
  revisions of *one* page; this is the same rendering, across two *different* pages' `current.md`).
  Word/line diff across two different patients' reports is not meaningful the way it is for two
  revisions of the same text, so **side is the only style** — no from/to toolbar to port.
  Visibility: both pages checked independently (invariant 6); a caller who can read the current
  page but not the prior one gets that page 404'd inline (same "cannot distinguish from absent" as
  everywhere else), not a leaked title.
- **17b — AI course summary.** No new AI plumbing: this is another `Ai\Assistant` action, same
  shape as the ones `ai:import-prompts` already ported from DokuLLM under `ai:profiles:reports`
  (TODO.md's own open note: "review and adapt the prompt... need a button and a display panel").
  Context is the patient's *visible* studies only (`Service\PatientStudies`, the timeline's
  existing predicate) rendered through `Ai\Context::build()` — never raw frontmatter, never the
  patient path (invariant 8). A button on the timeline, a result panel underneath (`.wk-tl`
  sibling), same disabled-by-default gate as every other AI surface (D15) — nothing shows until a
  provider is configured.
- **17c — export dossier [ask: dependency? — zip vs. one merged PDF].** Bundles the patient's
  visible studies into one download. Two shapes, pick one before building: (a) one PDF, each
  study's `templates/print/report.php` rendering concatenated by dompdf (no new dependency, reuses
  the phase-2 export path); (b) a zip of each study's individual PDF (`ZipArchive`, bundled with
  PHP — no Composer addition, but a new "bundle" concept `Service\Export` doesn't have yet). The
  patient's *name*, not path, appears in the dossier (D1's 2026-09-27 amendment — a report's own
  export may name its patient); nothing outside the visible set is included (invariant 6).
- **Tests**: a visibility-matrix case for 17a (compare with a page the caller can't read); an
  AI-off case for 17b (no button, no endpoint reachable) alongside the existing D15 pattern; a
  dossier test asserting an invisible study never appears in the bundle.

**Not in this phase:** word/line-style compare between two different reports (17a note above);
an AI action for anything except the course summary; dossier formats beyond PDF (ODT, e.g.).

### Phase 18 — namespace index: bulk select/move/tag/export, "recent activity" — done

`src/Controller/NamespaceController.php`'s docblock: "Deliberately NOT ported: bulk select/move/
tag/export/visibility (no such service exists) and 'recent activity here' (needs an audit log, not
built yet)." The audit log part of that note is now stale — `src/Audit/AuditLog.php` exists and
has recorded every write since phase 13 — but it is **write-only**: `record()` is its only public
mutator, there is no reader, and every line stores `path_hash` (invariant 8), never the path
itself, so an audit line cannot be resolved back to "which page in this namespace" without
rehashing every candidate path to match. "Recent activity" turns out not to need the audit log at
all: `Index\Sqlite::listWorklist($ns, $principal)` is already namespace-scoped, already sorted
`updated DESC`, and already powers the drawer's "Recently updated here" panel
(`Http\ChromeVars::shell()`) — reuse it as a panel on the namespace index page directly, same rows,
same `templates/dashboard.php`-style row rendering. No new service, no audit reader.

- **18a — bulk select.** Checkboxes per row in the pages table (`templates/namespace.php`'s
  existing `<table class="table">`), a bulk action bar that appears once ≥1 is checked — plain
  form POST + progressive JS (checkbox states in a hidden field), same "works without JS, an
  island only enhances it" rule as the rest of the app (A6).
- **18b — bulk move [ask: new endpoint].** `POST /api/v1/pages/bulk-move { paths[], to_ns }`
  wrapping `Service\PageMoves::move()` per page (already does redirect stubs + link fixups for a
  single page, `bin/reporion page:move`) — one audited `page.move` per page, not one bulk event,
  so the audit trail stays per-page like every other write.
- **18c — bulk tag.** `POST /api/v1/pages/bulk-tag { paths[], add?, remove? }` on top of
  `Service\Tags` (already writes a new revision per unsigned page; signed pages keep their tags,
  D3 — bulk tag follows the same rule, not a bypass).
- **18d — bulk export.** Reuses 17c's dossier decision once made — bulk export here and the
  timeline's export dossier are the same "bundle several pages' exports" primitive; build 17c
  first and this calls it with a caller-picked page set instead of a patient's studies.
  visibility deliberately **excluded again**: D16/D37 already call visibility a "deliberate, noisy
  act", one page at a time, audited individually — a bulk visibility flip is the one bulk action
  that stays out, not an oversight.
- **Tests**: a visibility-matrix case for bulk move/tag (an editor-without-grant on the
  *destination* namespace must not be able to move a page there); the "recent activity" panel gets
  the same leak-prevention shape as `listSubnamespaces()`'s existing tests (an invisible page's
  update never shows).

**Not in this phase:** bulk visibility change (see 18d); a real audit-log reader/viewer (Admin
already has no audit screen at all — a separate, unscoped phase if wanted later).

**Built 2026-09-29**, with three changes to the plan above, decided with the owner before building:
- **Form POSTs, not JSON endpoints.** 18b/18c are `POST /{ns}:` (`NamespaceController::bulk()`:
  a confirm page with the selection and a target, then `step=apply`) and 18d is
  `POST /export/bundle.zip` (`ExportController::bundle()`) — the same plain-form shape as every
  other screen action, so the whole feature works without JavaScript; a small inline script only
  adds select-all, the count and the row tint. No `/api/v1` bulk endpoints.
- **Export is a zip of each page's own PDF** (17c's option (b)), built now rather than waiting for
  17c — 17c can call the same `bundle()` with a patient's studies. Correction to 17c above:
  `ZipArchive` is **not** available here (no ext-zip in CLI or FPM; ODT export uses PhpWord's
  bundled PCLZip), so the archive comes from a small `Support\Zip` (stored entries, `crc32()` —
  no dependency). Draft reports are left out unless `export.allow_draft_export`, counted in a
  `NOT-INCLUDED.txt` (count only, invariant 8); at most 50 pages per request. A 50-report bundle
  on a fixture instance: 8.7 s; `gc_collect_cycles()` after each dompdf render keeps memory under
  FPM's 128 MB (dompdf's frame tree is reference cycles).
- **One link-fixup pass for a bulk move** — `PageMoves::moveMany()`. `move()` rescans every page
  on disk per call; looping it over a selection at archive size would take minutes.

Recent activity is `listWorklist()` as planned (already in the visibility matrix). Tests:
`tests/Http/NamespaceBulkTest.php` (move with one fixup revision, destination grant, collision,
paths outside the namespace dropped, tag add/remove with signed reports left alone, viewer and
anonymous access, the zip read back with an independent reader, drafts-only and over-the-cap
answers, recent activity), `tests/Support/ZipTest.php`, `PageMoveTest::testRewriteMany…`.

### Phase 19 — search: facet sidebar and pagination

`templates/search-results.php`'s docblock: "Deliberately NOT rendered until they are real: the
facet sidebar (the index has no facet query yet)... pagination..." Scoped to just these two —
saved queries, CSV export and the AI answer box stay out (D15 gates the last one; the other two
have no design decision behind them yet).

- **19a — facet counts [ask: new query].** `Index\Sqlite::searchFacets(string $term, ?User
  $principal, string $ns = ''): array` — same FTS5 `MATCH` + `Query::visibilityClause()` +
  namespace-prefix predicate as `search()` (never a looser one; a facet count is a listing and
  invariant 6 applies to it exactly as much as the results themselves — same "inside the aggregate,
  before GROUP BY" shape every other facet/count query in this codebase already uses, e.g.
  `listSubnamespaces()`, `listNamespaceYears()`), grouped separately per facet: `modality` and
  `region` from their child tables (D29 — "a page counted once per value", not per page, so the
  same page with two modalities contributes to both counts, deliberately); `site`, `device`,
  `status` as plain column `GROUP BY`; `tags` from `page_tags`. Five small queries (or one query
  per facet, run alongside the main search — a single giant UNION is not worth the complexity at
  this scale) rather than one; the search page's own facet sidebar renders real counts instead of
  today's invented ones (see "Mockup placeholder data" above — this phase is also what retires
  that specific drift item).
- **19b — pagination.** `?page=` (1-based, plain query param — matches every other filter on this
  page, works without JS) on top of `search()`'s existing `LIMIT`/`OFFSET` gap (it has neither
  today — every result renders on one page). A total-count query (`COUNT(*)` over the same
  FTS5-matched, visibility-filtered set) plus a simple prev/next pager component — the mockup's
  numbered-pages control is more than this needs at current scale (CLAUDE.md's search p95 target
  is 10k reports; a numbered pager is worth adding only once usage shows prev/next isn't enough).
- **Tests**: a visibility-matrix case for facet counts (mirrors 18/`listNamespaceYears()`'s
  shape — an editor-without-grant must never see a private page's tag/modality counted, even
  folded into a public page's count for the same facet value); a pagination test asserting page 2
  never repeats or skips a row relative to page 1 for a stable sort.

**Not in this phase:** saved queries, CSV export, the AI answer box (D15), a numbered pager beyond
prev/next.

### Phase 20 — Admin → Tags: groups, synonyms, ICD-10 codes, suggested merges — done (2026-09-29)

**As built — three corrections to the plan below, decided with the owner:** (1) the dictionary is
**not** stored in the `tags` table — that would be its only copy, lost on `index:rebuild`
(invariant 1); it lives in `data/tags.yaml` (FORMATS §3g), and the table stays unused. (2) D28's
expansion did not exist — no `search-synonyms` plugin, nothing read `conf/synonyms.txt` — so it was
built: `Index\Sqlite::search()` expands a term (whole query or one word) to its group, and
`conf/synonyms.txt` only seeds the file until the first save. (3) Following the mockup, a tag
carries a *list* of synonyms (free terms, not only other tags) rather than one `canonical`.
Suggested merges fold case, diacritics and separators (`PI-RADS`/`pirads`) — not endings: the
`demielinizant`/`demielinizante` pair below is not caught, it is a synonym to add — plus tags that
are another tag's synonym. A merge keeps the merged names as the target's synonyms. Tests:
`tests/Index/SynonymSearchTest`, `tests/Http/AdminTagsTest`, `Slug::fold()` in `SlugTest`.
The mockup's filter/group facets and "New tag" were not built (a tag exists by being on a page).


`templates/admin-tags.php`'s docblock: "its groups, synonyms, ICD-10 codes and suggested merges
have nothing behind them yet and are left out." The interesting find here: the **storage already
exists and has since `migrations/001_init.sql`** — `CREATE TABLE tags (tag TEXT PRIMARY KEY, grp
TEXT, icd10 TEXT, canonical TEXT)` (`docs/architecture-storage-index.md` §"Tags, links,
revisions") — but nothing reads or writes `grp`/`icd10`/`canonical`: `Index\Sqlite::tagCounts()`
only ever queries `page_tags` (the per-page join table), and `index:rebuild` never populates the
`tags` dictionary row at all. This is wiring-up work on an existing schema, not a new one — no
`[ask]` for the table itself, only for what touches it.

- **20a — synonyms, wired to D28.** `tags.canonical` (non-null = "this tag is a synonym of
  canonical") is exactly D28's synonym table, just not populated from it: `conf/synonyms.txt`
  today feeds the `search-synonyms` plugin at query time and nothing else. Admin → Tags gets an
  edit form for `canonical` per tag; on save, regenerate `conf/synonyms.txt`'s D28 groups from the
  `tags` table instead of maintaining the file by hand — one source of truth, the plugin keeps
  reading the same file format unchanged.
- **20b — groups and ICD-10.** Plain per-tag text fields (`grp`, `icd10`) on the same edit form —
  no new validation beyond `Schema\Validator`'s existing free-text rules; shown as a column next to
  each tag's count, matching the mockup's table.
- **20c — suggested merges.** Read-only, computed at render time, not stored: tags whose
  normalized form matches after `Support\Slug`'s ASCII fold (the same fold the importer already
  applies elsewhere) — e.g. "demielinizant"/"demielinizante" — surfaced as one-click prefills into
  the *existing* `POST /admin/tags/merge` form (`Service\Tags::merge()`, already built, phase 6).
  No new write path: suggestions only pre-fill the form a human still submits.
- **Tests**: `Index\Sqlite` gets `tagCounts()` extended to join `grp`/`icd10`/`canonical` (a case
  asserting a tag with no dictionary row still returns null fields, not an error — the join must
  be a LEFT JOIN, the dictionary row is optional metadata, not a prerequisite for a tag to exist);
  a suggested-merge test with a known ASCII-fold collision pair.

**Not in this phase:** an ICD-10 code *picker* (autocomplete against a real ICD-10 dataset — out of
scope until that dataset question is asked separately); auto-applying a suggested merge without a
human submitting the form.

### Phase 21 — the DICOM plugin: PACS worklist and study linking (2026-09-30)

TODO.md idea 1's DICOM half. Decided with the owner: one PACS per site (host, port, AE title) set in
the plugin as a per-site table, with the calling AE title we present to that PACS (it identifies us —
each site its own); we never listen;
dcmtk's `findscu` (3.6.6 on the server; its full path is a setting) is enough — C-FIND at study level
only, no images. The sites' PatientID is the CNP. Region and device are left to the form. Separate
from the HIS plugin; both fill report metadata.

- **Core** — setting type `sites` (a table per configured site, rows for vanished sites dropped),
  `pattern` on text settings/columns; `study_uid` and `pacs_accession` carried by the guided form
  (FORMATS §3f); `Index::findByStudyUids()`.
- **Worklist** — `GET /x/dicom/worklist`: every site with a PACS (or one), a date range (default the
  configured days back, ≤ 31), one C-FIND per modality; the modality is re-checked on the answer (some
  PACS ignore `ModalitiesInStudy`); a site that fails is named, the others still listed; studies with
  a report link to it. "Start" → `/new?prefill=dicom&ref={site}:{mod}:{uid}` (one C-FIND by UID);
  several ticked studies of one patient/site/modality/day → `ref=a,b,…` (2026-10-02): one
  multi-exam report, a study (`study_uid`, `pacs_accession`) per exam.
- **PACS tab** — `GET|POST /x/dicom/study/{pid}`: the report's site and day (both changeable), its
  modalities; the likeliest study first (linked, same CNP, same name); linking fills blanks in one
  revision; a different CNP or another linked study is refused (422, nothing written).
- **Test the PACS** — `GET /x/dicom/echo[?site=]` (owner): echoscu to each PACS or one — a *Test*
  button on each site's row in Admin → Plugins (the `sites` table's `row_link`) and on the Test
  screen; a failure shows the reason and echoscu's verbose log (an echo carries no patient data).
- **Tests** — `tests/Plugin/DicomTest.php` (a fake findscu writing dcmtk-shaped ISO-8859-1 answers),
  `tests/Plugin/DicomScuTest.php` (real findscu/echoscu against dcmqrscp; skipped without dcmtk).

- **Follow-ups (2026-09-29)** — an AE title is any DICOM AE (printable ASCII but `\`, ≤ 16, e.g.
  `AE_STROIE@@`), never starting with `-` (it would read as a findscu option); the worklist filter is
  a toolbar like the revisions bar (site, modality, from → to, Query); a **modality** select lists the
  configured ones ("all (CT, MR)"), one picked means one C-FIND per site; opening the page queries
  nothing — *Query* does; "New report by hand" → **Manual** and *Test the PACS* as buttons; the
  new-report form's plugin button reads **From PACS**, its action row stays right-aligned when it
  wraps, and its *Preview* button is gone (Enter now submits Create, which still validates and
  confirms a same-day report).

**Not in this phase:** series-level queries (body part, station → region, device); C-MOVE/C-GET;
Modality Worklist (MWL) queries; joining the HIS order and the PACS study of one exam in one list.

### Phase 22 — the signed report back to the HIS and the PACS — 22b built, 22a dropped

Asked 2026-10-02: send a signed report to Hipocrate (through HippoBridge) and its DICOM SR to the
PACS. Both reverse a decision — D38 (the HIS link reads only) and D39 (the PACS link queries only) —
so the decisions are amended first, with the owner, before any code.

- **22a — report to the HIS — dropped (owner, 2026-10-09: will not be built; D38 stays read-only).** Kept for the record. HippoBridge already writes: `POST /api/request/{id}/report` (report
  text → Hipocrate's result field, HTML), `/validate`, `/perform`. A *Send to HIS* action on a
  **signed** report that answers a HIS order (`order_ref`), never on a draft; the text sent is the
  signed revision's, rendered the way HippoBridge expects. Open questions:
  - **who signs in Hipocrate** — HippoBridge writes as the account it is given and only for users in
    its `allowed_radiologists`; the plugin's one service account would make every report Hipocrate's
    "validated by" the service account. Writing needs **per-user Hipocrate credentials** (each
    user's own, kept like their API tokens — D35/D36 territory, ask first) or HippoBridge acting on
    behalf of a named user;
  - validate in the same step, or leave validation to Hipocrate's screen;
  - a corrected report (D3: a new signed revision): send again, and say so in Hipocrate?
- **22b — DICOM SR to the PACS.** The SR the dicom plugin already builds (`/x/dicom/sr/{pid}`, TID
  2000) sent by C-STORE with dcmtk's `storescu`, as the site's calling AE, into the study the report
  is linked to (`study_uid`) — never for an unlinked report. Open questions: the PACS must accept
  Basic Text SR from our AE (a per-site setting, tested like C-ECHO); a corrected report = a new SR
  instance in the same series, and whether the PACS shows the newest or all of them.
- **Where "sent" is recorded** — not in the frontmatter: the signed revision's digest would change
  (D3). In the page's own `meta.json`, beside `share_token` (FORMATS §4): `deliveries[]` of
  `{to: his|pacs, rev, at, by, outcome}`; audited `report.deliver` with the pid, never the path.
  The page header shows "sent to HIS · rev N"; a newer signed revision shows "not sent".
- **Never automatic in the first version** — a button, per report; a bulk "send the day's signed
  reports" only after the single one has run for a while.
- **Not in this phase:** HL7; anything back from the HIS beyond the write's own answer.

**22b built 2026-10-02** on `feat/sr-push`; **22a dropped** (the owner, 2026-10-02: "only push DICOM SR, not
HippoBridge"; 2026-10-09: will not be created — D38 stays read-only). D39 amended. Answers to the open questions:
- **The PACS accepts SR from us** — a per-site tick, `send_sr`, on the site's PACS row (off by
  default); no separate test: storing a test SR would leave a document in the PACS, and C-ECHO
  already proves the association. A PACS that does not take Basic Text SR answers on the first send
  (`refused`, with storescu's log for the owner).
- **A corrected report** — a new instance in the same series (series UID from the pid; instance from
  pid and revision); the same revision sent twice is the same instance. Which the PACS shows is the
  PACS's business.
- **Multi-exam reports** — one SR per linked study, each the whole report, with that exam's
  accession and a series and instance of its own.
- **Recorded** — `meta.json` `deliveries` (FORMATS §4b), shown in the PACS tab ("Sent: rev N",
  "rev M not sent"), not in the page header (that is core, and the plugin's tab is where the PACS
  lives). Bulk sending: later, as planned.

### Phase 23 — integrity check of the archive — planned

The twenty-year promise (README) depends on files nobody looks at. One maintenance task, run from
`bin/reporion integrity:verify [--json]` and as a card in Admin → Maintenance (and from cron —
`doctor` reports the age of the last run):

- every `rev/NNNN.md.gz` reads and gunzips; revisions are 1..N without a gap; `current.md` equals
  the newest revision;
- every signature's digest recomputed from its stored revision matches
  (`Service\Revisions::signature()`'s `matches`, for every signed revision, not only the newest);
- every media file's bytes hash to its own name (`data/media/{year}/{sha256}.{ext}`), and every
  `media.json` entry points at a file that exists;
- the journal holds no intent older than the replay age; the index check (`IndexVerifyTask`) runs
  as part of it;
- **backups, optional:** `--backup=<dir>` reads a backup snapshot (a mounted rsync target, D22)
  and checks that every signed revision here exists there with the same bytes — read-only on both.

Output: counts, and the failing **pids** with a fixed reason code (invariant 8: no path, no name).
Nothing is repaired automatically; a failure is the owner's to look at. Saved under
`data/maintenance/` like the other runs.

**Built 2026-10-02** — `Service\Maintenance\IntegrityVerifyTask` (check only), `bin/reporion
integrity:verify [--backup=<dir>] [--json]` (exit 1 on any problem, for cron), the *Archive
integrity* card in Admin → Maintenance (with the backup path field), and a `doctor` line: when it
last ran and whether it found anything (a warning past 8 days, never a failure). Reason codes:
`rev_missing`, `rev_corrupt`, `rev_changed` (bytes ≠ the revlog's sha256), `revlog_gap`,
`rev_extra`, `current_stale`, `signature_mismatch`, `media_missing`, `media_changed`,
`journal_open`, `index_orphan|missing|drifted`, `index_unchecked`, `page_unreadable` (named by
`path_hash`, the page having no readable pid), `backup_missing`, `backup_differs`,
`backup_unreadable`. Revision files are read by the task itself, quietly — a corrupt one is a
finding, not a PHP warning. Trashed pages are not checked (their history is restored, then checked).

### Phase 24 — statistics, and a start page card — planned

Workload and turnaround, from the index only — the caller sees counts over the pages they can see
(invariant 6), so an editor with one site's grant gets that site's numbers.

- **24a — the data [ask: index schema].** The index has the exam date but not the first
  signature's time and signer; a migration adds `signed_at` and `signed_by` to `pages` (rebuilt from
  `meta.json`, as everything in the index is — invariant 1).
- **24b — `/stats` [ask: new route].** Reports per month (12 months), split by modality, site and
  signer; signed vs draft; **turnaround** — exam → first signature, median and 90th percentile, per
  modality and site; drafts older than the stale threshold, oldest first. Filters: period, site,
  modality. Server-rendered tables with simple SVG bars (no chart library); a CSV of the same
  table.
- **24c — a start page card.** Beside the existing tiles (drafts, stale, today, this week): "This
  month" — reports signed, median turnaround, against last month — linking to `/stats`.
- Not in this phase: per-patient or per-referrer statistics (no use found yet).

**Built 2026-10-02** on `feat/stats`, as planned (24a approved by the owner the same day). Notes:
- **24a** — `signed_at`/`signed_by` are `meta.json`'s `signatures[0]`: a re-sign after an edit
  keeps the first. Rows indexed before the migration stay NULL; `index:verify` now reports them as
  drifted (it compares the first signature too), and `index:rebuild` fills them.
- **24b** — exams count by exam date, signatures by the first signature's date; an archived import
  was never signed here, so it counts as an exam but not as signed. A signature dated before the
  exam date is left out of turnaround. The CSV has the count tables only — the stale drafts list
  carries titles (patient names) and stays on the page. No nav entry: the start page card links
  there.
- **24c** — the fifth tile: signed this month, median turnaround, last month's two numbers.

### Phase 25 — reference sidecar for an exam — planned

The `radiology:` namespace holds reference pages — classifications (spine fractures, knee injuries,
BI-RADS…), measurement norms, protocols. While reading or writing a report, the ones that apply to
its exam should be one click away.

- **The match lives in the template** (decided with the owner, 2026-10-02): a template's frontmatter
  lists the reference pages for its kind of exam — `references: [radiology:spine:clasificare-ao,
  radiology:spine:tlics]`. The report already knows its template (`template`, and each exam's own in
  `exams[].template`), so it gets the references through it — nothing is copied into the report,
  nothing is guessed from regions or words, and changing a template's list updates every report
  made from it.
- **Where** — a *References* panel: in the editor's rail (a tab beside the Assistant), and on the
  report view under the table of contents. Titles and summaries of the listed pages the caller can
  read (invariant 6: an unreadable one is left out silently); a click opens the page rendered in the
  panel or in a new tab. Per exam in a multi-exam report.
- **Editing the list** — on the template's page, a *References* field in the Details panel (phase
  14), with a page picker limited to the reference namespaces; a listed page that no longer exists is
  flagged there (moved pages follow `page:move`'s link fixups, which already cover frontmatter page
  lists in unsigned pages).
- **Setting** — the namespaces offered by the picker (`references.namespaces`, default `[radiology]`).
- **Later** — the AI assistant given the opened reference as context (through
  `Ai\Context::build()`, D15).

**Built 2026-10-02** on `feat/references` (on top of phase 26, sharing `Support\ExamTemplates`:
which template each exam was made from). Changed with the owner the same day:
- **One page per template** — `reference:` names a single page (the knee page, the brain page),
  not a list. The template's Metadata view picks it from a dropdown of the pages under the
  reference namespaces (`references.namespaces`, default `radiology`); the current one stays
  selectable, flagged when no longer found, and one outside the namespaces set in raw mode is kept.
- **On demand, from the right** — on the report view, the header's *Reference* button slides the
  page in as a right-hand panel, rendered in it (headings without ids, so the report's anchors stay
  unique); the report stays usable beside it on a wide screen, the panel covers the screen on a
  phone. Exams whose templates name different pages get a switch at the top; a page shared by
  several exams is shown once; each opens in a new tab from its title.
- **In the editor, the rail is an accordion** — the right side already holds the Assistant, so no
  panel over it: Reference, Checklist and Assistant are `<details name="editor-rail">` sections,
  one open at a time (native; `assets/js/editor-rail.js` for older browsers and to remember the
  last one opened). Default: the checklist, else the Assistant, else the reference. The crumbs
  line's *Reference* opens its section.
- **Its look** (owner, 2026-10-02) — the reference page's text is a quiet document: 75 % of the
  report's sizes, no colour, plain headings (no capitals, letter-spacing or accent rules), text
  left-aligned (`.wk-ref-page .wk-prose`).
- **Moves** — `page:move` did not touch frontmatter before; it now rewrites `reference` (unsigned
  pages, like body links; `PageMoves::rewriteReferences()`).
- Format: FORMATS §3i.

### Phase 26 — template checklists — built

A template lists what an exam of its kind must address — for a knee MRI: menisci, cruciate
ligaments, collateral ligaments, cartilage, bone marrow, effusion… — and the report being written
shows that list beside the text.

- **Where it lives** — the template's frontmatter (D19: a template gives metadata, never text):
  `checklist:` a list of items, or sections of items (`Menisci: [medial, lateral]`). A report knows
  its template (`template`, and each exam's own in `exams[].template`), so nothing is copied into
  the report.
- **In the editor** — a *Checklist* tab in the rail, per exam; ticking is the doctor's own aid, kept
  with the local draft (D25), **never saved** — prose stays the report (D18).
- **Optional help** — an item may carry keywords (`menisc`); an item none of whose keywords appears
  in the exam's text is shown as "not mentioned". Plain text matching, no AI.
- **With the assistant** — a `{checklist}` placeholder for the prompt pages (`ai:profiles:…`), so a
  *Check* action can ask the model which items the text does not address (D8: it suggests, the
  doctor edits).
- Not shown in print, PDF, ODT or SR.
- Pairs with TODO 14's "compare to template" (what a report changed from its template's text).

**Built 2026-10-02** on `feat/template-checklists`. Where it differs from the plan:
- **Format** (FORMATS §3h) — a list of lines: `# Section`, `Label | keyword, keyword`, or a plain
  `Label`; the YAML map form `- Label: [keywords]` gives an item its keywords, not a section. A block
  string works too. Bounded to 80 items. `Support\Checklist` parses, folds and matches;
  `Service\Checklists` reads each exam's template now (a template outside `templates:` or one the
  caller cannot read gives nothing).
- **Ticks** live in this browser's `localStorage`, keyed by the report path and the revision being
  edited — not in the D25 draft (that holds text only); a save that makes a newer revision starts
  them over. Never posted.
- **Rail** — the checklist shows in the editor rail even with no AI provider; "not mentioned" is
  recomputed as the doctor types, per exam pane (`assets/js/editor-checklist.js`, node-tested
  against the PHP rule in `tests/Render/EditorChecklistTest`).
- `{checklist}` in the AI context is the edited exam's list as "- item" lines (keywords left out),
  redacted like every other value.

### Phase 27 — report metadata, one shape for one exam or many — built

TODO 14 (2026-10-02): "rethink and refactor report metadata, especially for multi-exam reports".
Today a single-exam report keeps its exam at the top level (`exam_title`, `region`, `accession`,
`template`, `study_uid`…) and a multi-exam report moves the same fields into `exams[]` — two shapes
every reader, form and plugin has to handle. This phase settles **one** model; phases 28 and 29 are
built on it.

- **Report level** — what is true of the whole document: `title` (the patient's name, D30),
  `patient`, `site`, `device`?, `study_date` (the first exam's), `referrer`, `indication`, `priors`,
  `order_ref`, `visibility`, `tags`, `summary`; signing stays one per file (D3/D37).
- **Exam level** — what each exam has of its own: `title`, `modality`, `region`, `template`,
  `accession`, `study_uid`, `pacs_accession`, the time of the exam; the body's `##` heading is its
  title (FORMATS §11).
- **Proposal: every report has `exams:`**, a single-exam report being a list of one; the top-level
  `modality`/`region`/`exam_title` stay as the index's facets, **derived** on save from the exams
  (as `region` already is for a multi-exam report), never edited by hand.
- **Old reports are not rewritten** — a signed report's frontmatter is part of its digest (D3):
  readers accept both shapes for ever (one `Support\Exams::of($frontmatter)` that every caller
  uses), writers write the new one; a report gains `exams:` the next time it is saved.
- **Decide first [ask: format]** — the field split above, which fields an exam may override
  (device? referrer?), and FORMATS §12 rewritten. Then the schema (`conf/schema/base.json`), the
  index (unchanged columns, derived values), the plugins' prefill (hipobridge, dicom) and the
  exports (print, PDF, ODT, SR) move to `Support\Exams` one at a time.

**Built 2026-10-02** on `feat/exams-shape`, as decided with the owner (every report lists its exams;
the field split above; derived keys stored, rewritten on save; old reports converted on their next
save, signed ones never). FORMATS §12 rewritten. Notes:
- `Exams::normalize($fm, $before)` runs in `Storage::create/save` for every report path. A
  top-level value changed since the revision before is an edit and goes into the exam — so raw
  YAML, the plugins filling blanks and the older form keep working; an unchanged one is
  recomputed.
- Readers: the index (`page_exams` for every report; `order_ref` found on exams, as `study_uid`),
  the dicom plugin (SR, PACS link, bulk link), hipobridge, print, page view, duplicates, exam
  accessions (each exam's own modality and year), the META block import.
- Found on the way: a multi-exam report whose exams had no regions of their own lost the report's
  on save — the exams now take them; the frontmatter repair never takes `exams` from a damaged
  revision; a new exam moved first never takes the old exam's number.
- Raw mode's exam tabs read and write any exam key (numbers, booleans, lists kept), tabs for two
  exams or more.

### Phase 28 — editing exams: the guided form and each exam's metadata — built

TODO 14: "refactor the guided new exam page, by default multi-exam, but seamlessly single-exam" and
"in multi-exam reports display and allow the user to edit the metadata of each exam".

- **28a — the guided new-report form** — the report-level block (patient, site, referrer,
  indication), then a list of exams starting with one: each exam a card with modality, title,
  regions, template, time; *+ Exam* adds one, each card can be removed or moved up/down. One exam
  looks exactly like today's form; nothing asks "single or multiple". Works without JavaScript
  (submit buttons, as `more[]` does today).
- **28b — each exam's metadata in the editor** — the Details panel (phase 14) gets the report block
  and one section per exam (the exam tabs phase 12 added become the place for it), the exam's
  heading and its metadata edited together; adding an exam later appends a card and a `##` section
  and allocates its accession on save (D20).
- **Not here** — reordering exams whose report is signed (a new revision, signed again, as any
  edit — D3).

**28b built 2026-10-02** (with phase 27): the Metadata view has the report's fields, then a card
per exam (title, modality, regions, date, device, protocol, template; accession and PACS study
shown); every report opens there, multi-exam ones too. Add, remove and move with JavaScript, the
text's `##` sections kept in step, a new card starting from the first exam's modality, day and
device.

**28a built 2026-10-02** on `feat/new-report-exams`: the guided form has the patient and the report
(day, site, referrer, indication) side by side, then the exams as cards — title, modality, time,
device, template (a list per card, narrowed to its modality), regions. One exam looks like a plain
exam form; *Add exam*, ↑ ↓ and remove are submit buttons, so it works without JavaScript; the first
exam keeps the form's old field names, so the plugins' prefill is unchanged. Each exam is written
with its own modality, time and device (the first's when it has none) and numbered by its own
modality (D20); the path takes the first exam's modality. Titles are asked for on *Create*, not
while exams are added. Found: a new page's top-level values were taken as edits of its exams
(every exam got the earliest time) — on create the exams now win.

### Phase 29 — join reports into one multi-exam report — built

TODO 14: "join two or more reports into one multi-exam report, with a guided interface to select
the exams and their order". Decided with the owner (2026-10-02): **the parents are deleted; only
their latest revision is joined; the user checks the result first.**

- **Where** — the namespace pages table's bulk actions (beside Move, Tag, Export, Delete) and the
  patient timeline: tick two or more reports → *Join*.
- **Allowed** — reports of the same patient (the same patient key, D11), at the same site, each
  readable and writable by the caller; a multi-exam parent brings all its exams. Refused otherwise,
  with the reason.
- **The check screen** — the exams in order (by exam time, reorderable), each with its title,
  regions, template, accession and its text; the report-level fields side by side where the
  parents differ (referrer, indication, device), the user picking one; the path the joined report
  gets (`reports:{modality ns}:{site}:{yymmdd}-{name}` of the first exam; the user may change the
  modality namespace when the exams differ). Nothing is written until *Join*.
- **What is written** — one new report through Storage (invariant 5), draft:
  - `exams:` one entry per joined exam, each keeping its own `accession`, `template`, `study_uid`,
    `pacs_accession` (no new accession is allocated — D20's numbers stay unique);
  - the body: `# name`, then each parent's exam sections under its own `##` heading, in the chosen
    order — each exam keeps its own `### Concluzii` (phase 12);
  - the report fields as chosen; `priors` the union of the parents' (minus the parents);
    `joined_from:` the parents' pids and the revisions joined, so the history says where it came
    from.
- **The parents** — moved to the trash (`Storage::delete`, restorable until purged, their full
  history kept there); a signed parent's signature stays in its own trashed history. Links to a
  parent in unsigned pages (`priors`, internal links) are rewritten to the joined report, as
  `page:move` does; signed pages keep theirs.
- **Signed parents** — the joined report is a draft and must be signed (D7: required fields block
  signing, never saving). The check screen says which parents were signed.
- **Audit** — `page.join` with the new pid and the parents' pids (never paths, invariant 8), plus
  the usual `page.create` and `page.delete` lines.
- **Undo** — restore the parents from the trash and delete the joined report; no automatic split.

**Built 2026-10-02** on `feat/join-reports`, as decided (parents to the trash; latest revisions; a check
screen first). FORMATS §12b. Notes:
- `Service\Joins::plan()` / `apply()`, `POST /join` (`Controller\JoinController`): *Join* on a
  report folder's bulk actions and on the patient timeline (ticks, shown when two of the patient's
  reports are the caller's to edit). At most 8 reports. A parent whose `##` headings do not match
  its exams is refused, with the reason (fix it in raw mode first).
- The check screen orders exams by time, ↑ ↓ as submit buttons, shows each exam's origin, date,
  modality, regions, accession, template and text; referrer, indication and summary are picked
  where the parents differ; the modality folder when the exams have several.
- Apply moves the parents to the trash first, so the joined report can take a parent's path; if
  creating it fails the parents are restored. `PageMoves::relink()` (shared with the bulk move)
  points links at it, `priors` included — a moved page's priors are now rewritten too.
- Not built: undo in one step (restore the parents from the trash, delete the joined report).

### Phase 31 — a form for editing a template's checklist — planned

The owner, 2026-10-02: create a user interface for the checklist. Today a template's `checklist:`
(phase 26, FORMATS §3h) is written by hand as YAML lines (`# Section`, `Label | keyword, keyword`)
in the Metadata view's raw YAML.

- **Where** — the template's Metadata view (phase 14's Details panel), a *Checklist* field in place
  of the raw YAML for that key; reports have none (they read their template's).
- **The form** — rows of *label* and *keywords* (chips, comma or Enter to add), section rows
  between them, add / remove / move up and down; the 80-item bound shown as a count.
- **A preview** — each item marked as the editor would mark it against the template's own text:
  an item whose keywords the template's normal lines already contain is flagged only when those
  lines are deleted, an item whose keywords it lacks is flagged on every new report. That shows
  the template author which items are reminders and which are not.
- **No format change** — it reads and writes the same list of lines, so a hand-written checklist
  opens in the form and the form's output stays readable YAML. Lines it cannot parse are kept
  and shown as such, never dropped.
- Built before phase 30, which stays last.

### Phase 32 — AI model aliases: lite, normal, expert — built (2026-10-07)

Decided with the owner: an action names a **tier**, not a model, so a provider or model can change
in Admin → AI without editing any `ai:` page.

- **Per server** (`ai.servers[i]`): `model` is the `normal` alias (unchanged — no migration),
  `model_lite` and `model_expert` are new; an empty one falls back to `normal`
  (`AiConfig::modelFor()`). A server is configured when `model` is set. Still three slots, one in use.
- **Per action**: an optional sixth column in the profile table, `| ID | Label | Tooltip | Icon |
  Result | Model |`, `lite|normal|expert`; blank or unknown is `normal` (`AiConfig::tier()`).
  Existing pages need no change.
- **Flow**: `Action::$model` → `Prompt::$tier` (set only by `Context::build()`) → the provider's
  request `model`. The audit line's `provider` and the rail footer name the model actually used
  (`ProviderInterface::describe($tier)`).
- **`ai:check`** lists the three aliases and checks that each one the server is asked for is among
  its models. Admin → AI has three model fields per server.
- **A server per action** (2026-10-07): the same `Model` cell may be `{server}:{alias}` — the server
  by the name Admin → AI gives it (`Server N` when unnamed), case aside — e.g. `Cloud:expert`; a bare
  string with no colon is just the alias, on the server in use (`AiConfig::parseModel()`).
  `Assistant` resolves the provider per action; each server keeps its own egress rule and
  `external_ack`. The action list still requires the server in use to be configured, and an unknown
  or empty server name fails the action ("not set up"), never falls back silently.
- Not built: fallback between servers when one is down.

**Reserved prompts (2026-10-07).** The profile table stays the editor rail's list. A prompt page
with a reserved id switches on a feature elsewhere, and its control is not rendered without it
(`Actions::SPECIAL`, `Actions::special()`); listing it in the table too also puts it in the rail,
with that row's `Model` cell.
- `summary` — **built**: *Summarize* in the report tab's metadata panel (writer, current revision,
  unsigned), the saved text via `/ai/complete` `source: "page"`, the answer as one editable line,
  saved as `summary` through `PATCH /pages/{path}/meta`.
  The text asked about is the report's **conclusion** when it has one (`Support\Conclusion`: any
  heading level "conclu…", else the whole body) — shorter, closer to the point, and what makes the bulk run
  affordable.
  The save's stopgap summary (first sentence of the conclusion, FORMATS §12) is refreshed while it is
  still that stopgap, and counts as "no summary" for the bulk run; a typed or assistant's one is never touched.
  **Bulk** (2026-10-08): `pages:summarize` (`Service\Maintenance\SummarizeTask`, also Admin → Maintenance)
  — unsigned reports under `--namespace` with no summary (`--overwrite`: all), at most `--limit`
  per run; dry run by default and sends nothing, `--apply --actor=<u>` asks `Assistant::run()` page by
  page (de-identified, audited `ai.call`), tidies the answer (`Support\SummaryLine`) and writes one
  revision `assisted: summary`. A page whose profile has no `summary` prompt (or no assistant at all)
  gets the conclusion's first sentence instead — the save's rule, nothing sent, not bound by `--limit`,
  note `summary from conclusion` — and a later AI run replaces it; a failing
  page is listed by pid with its reason and left alone; a server that does not answer (timeout,
  refused, busy) or 5 pages failing in a row stops the run, and the next run carries on (done pages
  have a summary). Signed reports are counted, never rewritten (D3).
- `evolution` — **built**: the patient timeline's Evolution panel (the mockup's AI panel), for a
  writer when the patient has two reports or more. *Summarise course* asks `/ai/complete` about this
  report (`source: "page"`); the prompt's new `{history}` placeholder (`Context`) brings the
  patient's other reports the caller can read — the latest 8, oldest first, each de-identified and
  tagged only with its date and exam. The answer is rendered in the panel with Copy; never written.

### Phase 33 — Admin → AI rework: six servers, per-alias parameters, model lists — built (2026-10-08)

Asked 2026-10-08. Decided with the owner: **six server cards** (six, not five, so the cards fill a 2- or 3-column grid) (not more prompt-profile rules), and
**per-alias parameters plus a raw-JSON box** for what a server needs beyond them (not a native
Anthropic provider, which would need the Anthropic PHP SDK as a new dependency).

**On screen**, each card's parameters are a table — one row per parameter (model, temperature,
top_p, top_k, min_p, max_tokens, extra), one column per alias (lite, normal, expert).

**Why the JSON box.** Newer Claude models refuse `temperature`/`top_p`/`top_k` (400) and take their
reasoning depth as an *effort* level in the request — not something a prompt can set. A prompt can
steer wording, length and language; it cannot turn thinking up or down. Other servers have their own
knobs (llama.cpp/vLLM `min_p`, OpenAI `reasoning_effort`, OpenRouter `reasoning: {effort}`). So each
alias sends only what is filled in, plus whatever JSON the owner adds; the server's own error (shown
by *Test*, below) says when a field is refused.

#### 33a — six servers, parameters per alias — built (2026-10-08)
- `AiConfig::SLOTS` 3 → 6; the slot validator and the cards follow. Nothing to migrate.
- Each server keeps `name`, `endpoint`, `api_key`, `timeout`, `external_ack`, and gains a `tiers`
  map — `lite`, `normal`, `expert`, each `{model, temperature, top_p, top_k, min_p, max_tokens,
  extra}`. **Blank is not sent.** `extra` is a JSON object merged into the request body last
  (≤ 2 KB, keys `[a-z_][a-z0-9_]*`, never `model`/`messages`/`stream`/`stream_options`), e.g.
  `{"reasoning_effort": "low"}`.
- Read compatibility: until the next save, the old flat fields stand in — `model` →
  `tiers.normal.model`, `model_lite`/`model_expert` → their tier's model, the server-level
  `temperature`/`top_p`/`max_tokens` → every tier. An empty lite/expert model still means "the
  normal one", and then takes normal's parameters too.
- `OpenAiCompatibleProvider::body()` builds from the alias's settings; a prompt page's
  `max_tokens:` still caps (the smaller wins). `lite` still sends no system prompt.
- `ai:check` prints each alias's model and the parameters it sends (never the key).

#### 33b — instructions per server — dropped
The owner, 2026-10-08: not needed — the prompt pages (`…:system`, each action's own) already hold
any standing instruction, and they are edited there.

#### 33c — model lists: filter, *Get models*, *Test* per card — built (2026-10-08)
- **Model filter** per server: a regular expression (`free`, `/qwen|llama/i`; bare text is matched
  case-insensitively); invalid patterns refused on save. Applied to every listing of that server's
  models (the dropdowns, `ai:check`).
- Each card gets **Get models** and **Test**, owner-only, no report text ever sent:
  - `POST /admin/ai/servers/{slot}/models` → `{data: [model ids, filtered], error?}` from the
    *saved* settings (the key never comes back to the browser, so an unsaved card says "save first").
  - `POST /admin/ai/servers/{slot}/test` → per alias with a model: is it listed, and one fixed
    one-line request ("Reply with OK.") with that alias's parameters — `{tier, model, ok, ms,
    error}`, the server's own error words shown, so a refused `temperature` or a wrong `extra`
    field shows up here and not in a doctor's editor. Egress rule and audit (`ai.test`, no text)
    as for any call.
- `assets/js/admin-ai.js` (island): fills each alias's model field from *Get models* — a
  `<datalist>` on the text field, so the server's models drop down and one the list lacks can
  still be typed (built so, rather than a `<select>` with a "type another…" choice). Without JavaScript
  the fields stay plain text inputs.
- Both endpoints get their rows in docs/architecture-api.md.

#### 33d — the top panel: *Assistant* — built (2026-10-08)
- One panel replacing *Status* and *In use*: on/off; the **default server** (the cards below, each
  with a status chip: ready / not set up / unreachable / egress refused); **prompt profiles** as a
  small routing table — `ai:profiles:X` → its namespaces, then *everything else* → the fallback
  profile or *no assistant*; one Save. Built with the default server's state on top (ready or what is
  wrong, host, model, key, egress) and its *Check* in the panel's header; per-card state comes from
  each card's *Test* (33c) rather than a *Check all*, which would call six servers on one click.

#### 33e — the bottom panel: *Prompts*, and the page's look — built (2026-10-08)
- One card per profile: its address and where it serves (chips: *reports, docs* / *fallback* /
  *unused*), a link to its table page, then three groups:
  - **Rail** — the table's actions in order, `---` sections shown as rules: label, result mode,
    model, and a warning when the row's prompt page is missing or empty;
  - **Reserved** — `summary`, `evolution`, `tags`: present (link) or missing (*create* link to the
    editor at that path, which shows what turns on when it exists);
  - **System** — `system`, `system:{id}`, and whether the profile falls back to `default:system`.
  (`Actions::overview($profile)` — reads the table and the pages, no new storage.)
- Server cards collapse (`<details>`): the summary line names slot, server, host and normal model,
  with *in use* / *outside this network* chips; the default server's card is marked and open, and an
  open card takes the whole row. Three columns from ~1400 px, two from ~900 px, one below; the existing
  tokens only; checked at 390 px and 1400 px (tools/browser).

**Tests**: settings round-trip for six slots and the `tiers` shape, the legacy flat fields read as
tiers, `extra` validation (bad JSON, reserved keys, size), the request body per alias (blank not
sent, `extra` merged last), the model filter, the two endpoints (owner-only 404 otherwise, no key in any answer, the
fake server's refusal shown), the prompts overview (missing page, reserved present/missing).

**Not in this phase**: a native Anthropic Messages provider; fallback to another server when one
is down; more than one profile → namespaces rule beyond the main one and the fallback.

### Phase 34 — more from the assistant: ten ideas, planned — planned

Proposed 2026-10-08 and asked to plan them all; built one sub-phase at a time. **Decided with the
owner, 2026-10-08:** build 34a → 34b → 34c → 34f → 34h → 34e; **skip** 34d (playground), 34g
(follow-ups), 34i (teaching-copy check) and 34j (plain-language version, skipped after 34h); 34e gets its `page_vectors` index table; 34h stores RADS
as tags. Rules that hold for every one: a prompt is made only by
`Context::build()` (D15), de-identified, audited `ai.call` without text; nothing the assistant says
is written without a person's click, except where a bulk task is run on purpose (dry run first);
signed reports are never rewritten (D3); a feature whose reserved prompt page is missing shows no
control. **[ask]** marks a sub-phase that touches something CLAUDE.md says to ask about first.

#### 34a — pre-sign check (reserved `presign`) — built (2026-10-08)
- The Sign screen (`/{path}/sign`, `templates/page-sign.php`) gains a *Check before signing* panel:
  warnings, never a block (D7's spirit: required blocks signing, nothing else does).
- **Without the assistant** — `Support\Laterality`: left/right words (stâng/drept, stânga/dreapta,
  bilateral, and the abbreviations) per section; a side named in the indication or an exam title
  that the conclusion contradicts, or a conclusion side the description never names, is a warning.
  Also: an exam with no conclusion, a conclusion shorter than a sentence. Runs always.
- **With `ai:profiles:{profile}:presign`** — the report (body only, de-identified) asked for a list
  of problems: findings in the conclusion the description lacks and the reverse, laterality,
  inconsistent measurements or units, contradictions. The answer is shown as a list (one line per
  item, `Support\ProblemList` parses `- …` lines); asked when the Sign screen opens, cached for that
  revision in the browser only (re-opening does not ask again), *Again* to re-ask.
- Signing records how many warnings the check showed in its own audit line, `page.presign`
  `{rules, ai?}` after `page.sign` (built so rather than as an extra on `page.sign`, to leave
  `Service\Signing` untouched), never their text. The rules dropped "a conclusion shorter than a
  sentence" — "Fără modificări." is a whole conclusion.
- Tests: laterality rules on anonymised fixtures (both languages' spellings, bilateral, a side only
  in the exam title), the panel without the prompt page, the parsed AI list, the audit extra.

#### 34b — AI usage in Admin → AI — built (2026-10-08)
- A *Usage* panel from the audit files already written (`ai.call`, `ai.refused`, `ai.test`), over a
  chosen period (7 / 30 / 90 days): calls per action, per server and per model; median and p90 time;
  tokens in and out where servers report them; failures by reason; refusals (`identifier_leak`).
- `Service\Ai\Usage` reads `data/audit/*.ndjson` (month files in range), no new storage; owner-only.
- Tests: a fixture audit month → the counts and percentiles; no text ever in the output.

#### 34c — the prior picked for you — built (2026-10-08)
- `{previous}` with no `priors` in the frontmatter: the patient's latest *other* report the caller
  can read with an overlapping modality **and** region (`PatientStudies` + the index's
  `page_modalities`/`page_regions`), before this report's study date. `contextSet` says `prior
  (auto)`; the rail's "Context sent" shows it, so the doctor sees which one was used.
- An explicit `priors` entry always wins. No write: the frontmatter is not filled by this.
- Tests: picks the right one among several (date, modality, region), none when nothing matches,
  an unreadable prior is never taken (grants), `priors` overrides.

#### 34d — prompt playground — skipped (owner, 2026-10-08)
- On a prompt page (`ai:profiles:{p}:{id}`, owner or a writer of `ai:`), a *Try it* panel: choose
  up to 5 reports (a namespace + *latest N*, or paths typed), one or two aliases/servers
  (`normal` vs `Cloud:expert`), run → a table, report × model, each cell the answer, its time and
  tokens. Nothing written; each run audited `ai.call` with `ai_action: "try:{id}"`.
- Built on `Assistant::run()` per cell; a cap on cells per run (10) and the busy lock as for the rail.
- New endpoint `POST /api/v1/ai/try` **[ask]** — (`{prompt_path, paths[], models[]}` →
  `{data: [{path_pid, model, result, ms, usage, error}]}`; pids, never paths, in the answer to the
  browser beyond what the caller already sees).
- Tests: grants (a report the caller cannot read is refused), the cell cap, audit lines, the
  playground hidden on non-prompt pages.

#### 34e — similar reports (embeddings) — `page_vectors` approved — built (2026-10-08)
- A local embedding model through the server's OpenAI-compatible `POST /embeddings` — **one model
  for the instance** (owner, 2026-10-08: not one per server): `ai.embed_server` + `ai.embed_model`
  on Admin → AI's *Assistant* panel (`Service\Ai\Embedder`).
- What is embedded: each report's conclusion(s) (`Support\Conclusion`), else its summary, else the
  description — de-identified like a prompt (D15: through `Context`), never the name heading.
- Stored **in the index** as a rebuildable cache (invariant 1: deleting it loses nothing):
  `page_vectors(pid, model, dim, vec BLOB)` — float32, unit-normalised — filled by
  `index:rebuild --vectors` and a maintenance task in batches, and refreshed on save in the
  background of the next run (never on the save's request path). (Index schema change approved
  2026-10-08.)
- Built as: `migrations/005_page_vectors.sql` (`pid, model, sha, dim, vec`; no foreign key, so a
  rebuild keeps vectors and `sha` says which still match), `index:vectors` (Admin → Maintenance,
  `index:rebuild --vectors`), `GET /api/v1/pages/{path}/similar` and the report footer's panel.
  Search results do not show it yet. Follow-up (2026-10-08): a minimum similarity
  (`ai.embed_min_score`, default 0.5) instead of always ten rows, each row's status, and a note on
  the panel when the report changed since its vector was made.
- *Similar reports* on a report page and in search results: cosine similarity computed in PHP over
  the caller's visible reports (the visibility predicate filters the candidate pids first —
  invariant 6); brute force is fine at ~10 000 × 768 (measured target < 300 ms); the top 10 with
  their exam title, date and summary.
- Not a replacement for search (D28 stays); no sqlite vector extension needed.
- Tests: rebuild from disk gives the same vectors (fake embeddings server, deterministic), a
  private report never appears for a caller without the grant, a deleted index loses nothing.

#### 34f — failover to another server — built (2026-10-08)
- Per server, *If unreachable, use*: another slot (or none). Only `unreachable`, `timeout` and HTTP
  5xx — and only before any text came back (`Service\Ai\FailoverProvider`) — move a request on — never a refusal, a 4xx or `identifier_leak`; one hop, no chains.
- The fallback server's own egress rule applies (a local → external fallback needs that server's
  acknowledgement, as now); the alias is looked up on the fallback server.
- `ai.call` audit records `failover: {from, to, reason}`; the rail's footer says which server answered.
- Tests: the fake server down → the second answers; a 400 does not fail over; egress refused on the
  fallback is reported, not bypassed.

#### 34g — follow-up tracking (reserved `followup`) — skipped (owner, 2026-10-08)
- `ai:profiles:{p}:followup` reads the conclusion/recommendations and answers one line —
  `none`, or `{interval} {modality}` ("6 months MR"); parsed to `follow_up: {due: YYYY-MM-DD,
  modality, note}` in the frontmatter (due = study date + interval).
- Filled on Save **only when the doctor clicks** *Suggest follow-up* in the editor's Metadata view
  (a field there, editable), and by a bulk `pages:followup` task (dry run first) for the archive.
- A *Follow-ups due* list (start page card + `/followups`) — reports whose due date has passed and
  whose patient has no later report of that modality. Needs `follow_up_due` as an indexed column
  for speed (`pages`), or a scan of `meta_json` if the owner prefers no schema change.
- Tests: interval parsing (zile/săptămâni/luni/ani, Romanian and English), due-date arithmetic,
  "done" when a later report exists, visibility of the list.

#### 34h — RADS categories — as tags (owner, 2026-10-08) — built (2026-10-08)
- Extract BI-RADS / PI-RADS / LI-RADS / Lung-RADS / TI-RADS (ACR and EU) / O-RADS categories from
  the conclusion (the whole text when there is none) — first **without the assistant**
  (`Support\Rads`: the systems' fixed spellings and categories, e.g. "BI-RADS 4A", "LI-RADS LR-5",
  "BI-RADS categoria IV"; a clause about history or a negating word right before it is skipped),
  the assistant only for free-text phrasings when the prompt page exists — a model's "BI-RADS 4A"
  is spelled as the tag (`Rads::merge`).
- Stored as **tags** (`rads:birads-4a`) — no schema change, inside D18, the tag facet and search
  work today. (A `rads` field + `page_rads` table for statistics was the alternative; not taken.)
- Filled where the doctor clicks (*Suggest tags* adds them, after the assistant's capped five) and
  by the bulk task `pages:tag` (dry run first): with the assistant's tags, or on their own — no
  server asked — for a report already tagged without them or whose profile has no `tags` prompt
  (counted `rads`, note `tags: rads`, audit reason `rads-tags`); never on a signed report.
- Tests: the regex on anonymised conclusions (each system, sub-categories, "BI-RADS 0"), no
  category in a negated or historical sentence ("anterior BI-RADS 3").

#### 34i — second de-identification pass on teaching copies — skipped (owner, 2026-10-08)
- When a report is duplicated as a teaching copy and before it is made public, the assistant
  (reserved `deid`) lists what in the text could still identify the patient — dates, places,
  institutions, other people's names, rare details. Shown in the publish confirmation (D16's
  acknowledgement screen) as a checklist the person ticks or fixes; never edits the text itself.
- Plus a rule-based pass that needs no assistant: full dates, CNP-shaped and phone-shaped numbers,
  the site names from Admin → Sites.
- Tests: the rules on fixtures; the AI list shown; publishing still requires the existing
  acknowledgement.

#### 34j — plain-language version (reserved `lay`) — skipped (owner, 2026-10-08)
- *Explain for the patient* on a signed report: the assistant writes a plain-language explanation
  of the conclusion (Romanian), shown in a dialog and printable on its own sheet
  (`templates/print/lay.php`, dompdf rules, D34) with a fixed header saying it is not the report and
  carries no signature. Never saved into the report; optionally saved as a separate unsigned page
  under the report's namespace when the doctor asks.
- Tests: never written into the report, the print template renders (rendered-PDF check), the
  button hidden without the prompt page.

**Order and size** (rough): 34a M → 34b S → 34c S → 34f S → 34h S–M → 34e L. All
decisions taken; 34d, 34g, 34i and 34j are kept above for the record, not to be built.

### Phase 30 — the mobile interface, every page — planned

The owner, 2026-10-02: check the mobile interface thoroughly — **all pages, entirely** — and keep it
crisp and functional, even at the cost of showing less.

- **Every page, at phone width** (390 px) and a small tablet (768 px), signed in and anonymous:
  start page, namespace index and bulk actions, report view (header, tabs, metadata, exams, the
  reference panel), editor (toolbar, exam tabs, Metadata view, rail, save bar, AI dialog), new
  report form, sign, revisions and diff, patient timeline, search, recent, statistics, print
  preview, profile, every Admin screen, login, error pages, the public layout. Screenshots of each
  at both widths, before and after (tools/browser).
- **Allowed** — shorten strings with an ellipsis (titles, paths, crumbs, chips, table cells);
  icon-only buttons where the label repeats the icon (with the label as `title`/`aria-label`);
  hide what a phone does not need (secondary columns, long help text, decorative counts) behind a
  menu or not at all; tables scroll inside their panel or collapse to one line per row.
- **Must hold** — no horizontal page scroll; tap targets at least 40 px; nothing hidden behind the
  two-row top bar or the save bar; the on-screen keyboard never covers the field being typed in;
  every action still reachable (if not on the screen, then in a menu).
- **Found already** (phase 25): a `position: fixed` element inside the page column is held by the
  column's CSS container — slide-in panels are moved to `<body>`.

**First pass done 2026-10-02** on `fix/mobile` (owner: "fix them now"): every page at 390 px,
signed in as owner and as an editor, and anonymous, measured (horizontal overflow, elements past the
edge, tap targets) and looked at; then again at 1400 px for regressions. Fixed:
- **Overflow** — a long name in the start page's greeting widened the page (now wraps as a block);
  Index & storage's health list squeezed its values to one letter (`.wk-kv` key column capped at
  45 %).
- **Tables** — `table.table-cards` (each row a card on a phone) extended with `.wk-card-head` (the
  row's title as the card heading), `.table-cards-compact` (page lists: title, then one wrapped line
  of details — recent changes, namespace index) and `.table-cards-form` (rows of inputs: each label
  above its control — Admin → Sites, a plugin's per-site table); also Admin → Users, Admin → Tags and
  the statistics' modality/site/signer tables.
- **Editor** — crumbs line in two rows (path, then badges and icon-only buttons); toolbar in two rows
  without separators, character count, code, copy and side-by-side preview; text box 62 % of the
  screen.
- **Report** — crumbs on one line (root crumb dropped, namespaces cut with an ellipsis, the page's
  segment last); text left-aligned instead of justified.
- **Forms** — stacked search bars (PACS tab, PACS worklist) put each icon or label beside its field;
  Admin → Settings keeps "from conf/local.php" inline with its checkbox label and lets the icon row
  wrap; search hides the relevance score and shortens the namespace placeholder.
- **Touch** — on a coarse pointer, small buttons, switches and icon buttons are at least 36 px.
- **Text** — "+ + exam" on the new-report form (now "Add exam").

Kept as is: the two-row top bar (one row leaves the search box ~90 px; made tighter instead). Left for
the owner: `plugins/export-pdf-letterhead`, a design-era skeleton that does not load ("invalid ui
slot") and targets classes never built — remove, or keep as an example?

### Small — one visibility control, one visibility badge — done (2026-10-02)

The owner: the visibility control in the Metadata view looked wrong, and elsewhere too.
- **Badge** — `Support\Visibility::badge()`: icon (lock, link, globe), capitalised label, the
  level's sentence as tooltip, the existing tag colours. Used by the page header, namespace
  index, recent changes, search results, the start page and the public layout; the drawer shows
  the icon alone, and only for unlisted and public pages (private is the norm).
- **Picker** — `templates/partials/visibility-picker.php`: three option cards; the saved level
  marked "now". The editor's Metadata view has it in place of the tag and "Change…" link (which
  left the editor), saved with the revision; `/{path}/visibility` uses the same control.
- **D16 kept, and closed** — choosing Public opens what everyone will see and the
  acknowledgement in place; the editor's save now requires it and audits `page.publish`. A
  raw-mode save setting `visibility: public` is refused — before, it published silently.

### Small — a button both secondary and danger — done (2026-10-02)

TODO 14: the *Delete* on a namespace's description page is a danger action drawn as a secondary
button. A `btn-secondary btn-danger` pair (secondary's outline, danger's colour, from the palette's
tokens), used wherever a destructive action sits beside ordinary ones. Any time.

**Built 2026-10-02** — `.btn-secondary.btn-danger` in `assets/css/wiki.css`: outline in
`--state-error`, filled only on hover (not while disabled). Used by the namespace table's bulk
*Delete* (beside Move, Tag, Export), Admin → Users *Deactivate* and the profile's token *Revoke*;
a confirm page's own submit (delete page, bulk delete, empty trash) keeps the solid `.btn-danger`.

### Later (deferred by the milestone doc)
Share tokens, integrations/AI, vectors, importer against the real archive (build step 11).
