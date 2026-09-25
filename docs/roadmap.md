# Roadmap — state as of 2026-09-25

A snapshot of where the build stands against `CLAUDE.md`'s build order, `docs/milestone-1.md` and
the mockup in `design/`, and the plan to close the gaps. Items marked **[ask]** need explicit
approval under the working agreement (dependency, on-disk layout, index schema, new endpoint,
signing/revision code, account model). Update this file as phases land.

## Findings

### Tests

487 tests; 11 failing: 8 in `tests/Cli/ImportCommitCommandTest` / `ImportRollbackCommandTest`
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
3. **CSS values diverged.** Of 120 `.wk-*` selectors shared between `design/mockup/Wiki.dc.html`
   and `assets/css/wiki.css`, 50 have different declarations — e.g. `.wk-edit` lost its
   `minmax(0,1fr) 328px` AI rail, `.wk-cmp` / `.wk-two` collapsed to one column, `.wk-mono` uses
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
4. Remove or wire every piece of mockup placeholder data listed above — real facet counts from
   the index or no facets; no fake ACL, template list or counts.
5. Editor: load the vendored `marked.js`, preview the body only, sanitise the preview output.
6. **Accession sequence vs D20 [ask: decided behaviour + live data].** D20
   (`architecture-storage-index.md`) says `seq` is per-site-per-year; `Import\AccessionAllocator`
   keys the counter by `site:modality` with no year, so numbering runs across years, and
   `AccessionAllocatorTest` additionally asserts per-modality independence. The `real-2026` batch
   already issued accessions this way. `testDifferentYears` fails until this is decided.

### Phase 1 — one shell: Reading room + royal blue / lime / amber (A6)
Decided 2026-09-25 (`design/README.md` §"Chosen direction"): Reading room layout, Workbench
palettes, user-selectable, royal blue default. Replaces the Workbench chrome built so far.
7. Palettes in `assets/css/tokens.css`: royal blue (current values), lime, amber — dark + light
   each, verbatim from the mockup's `data-bpal` rules; a palette cookie + picker beside the theme
   toggle (same no-JS POST-and-redirect pattern as `POST /theme`) **[ask: new endpoint]**.
8. One layout partial: head, slim top bar (☰, path-aware search/⌘K, theme, palette, account),
   reading column, dock. Every signed-in screen renders only its own content into it.
9. Dock replaces `templates/tabs.php` and `templates/rail.php`; the drawer (plain link to
   `/{ns}:` without JS) takes the worklist; `templates/status.php` goes. Screens without a page in
   context (search, `/new`, admin, namespace index) get the dock's global items only.
10. Port every screen into it: page view, edit, history, compare, timeline, `/{ns}:`, `/search`,
   `/new`, `/admin/users`, delete-confirm (login and the public layout stay separate by design).
   Editor and compare may use the full width rather than the 920px reading column — check the
   mockup's panes at `pad="read"`.
11. Re-sync the CSS rule values to the mockup component by component; settle `--w-mono` vs
   `--font-mono`.
12. Browser pass on every screen: 3 palettes × light/dark, desktop and phone width.

### Phase 2 — finish milestone 1
13. `/{path}@{rev}` and `/r/{pid}/{rev}` **[ask: revision code]**.
14. `/{path}/print`, then `/export/{path}.pdf` via dompdf from the same HTML; rendered-PDF check.
15. Append-only audit log in `data/audit/` with `path_hash` — visibility flips, publish, sign,
    delete **[ask: on-disk layout]**.
16. Tick `docs/milestone-1.md` (doctor, rebuild equivalence, against fixtures).

### Phase 3 — missing mockup screens
17. Error-state templates via `Http\ErrorMapper` (`WikiErrors`).
18. ~~Tree sidebar (`WikiTree`)~~ — Console shell, not chosen; namespace navigation lives in the
    phase 1 drawer.
19. Signed-in dashboard at `/` (`WikiWorklist` content in the reading column).
20. Timeline to mockup (`wk-tl*`, stats); decide `/patient/{key}` vs `/{path}/timeline`.
21. Profile (own password) and the remaining admin tabs, index & storage first **[ask: account model]**.

### Phase 4 — API and CLI gaps
22. `GET /pages`, `GET /pages/{path}` — each with a visibility-matrix case.
23. `PATCH /pages/{path}/meta` (visibility flip; needs the audit log and D16 acknowledgement).
24. Move (redirect stub + link fixups, CLI + API), duplicate, restore, `trash:purge`, `page:new`.
25. Sitemap and feed.
26. Plugin loader — only once the PDF letterhead plugin actually uses it.
27. `Secure` cookie flag, guarded date parsing.

### Later (deferred by the milestone doc)
Share tokens, tags admin, integrations/AI, ODT, vectors, media upload, importer against the real
archive (build step 11).
