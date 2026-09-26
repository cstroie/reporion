# TODO — ideas to study and plan

Ideas, not decisions. Each needs a plan (and, where it touches a decision in CLAUDE.md, a new
decision) before any code. Planned work lives in `docs/roadmap.md`.

## 1. DICOM integration for the worklist

Query/retrieve against one or more DICOM servers (PACS / modality worklist), each with its own
configuration (AE title, host, port, TLS, which query levels it answers), to fill the worklist and
new reports with patient and exam data instead of typing it:

- patient name, sex, age;
- CNP → sex, birth date and age (the CNP encodes all three: first digit = sex and century,
  then YYMMDD);
- site, exam date, modality, exam segment (head, knee, spine…), accession number.

To settle when planning:
- It runs against **D27** ("no PACS C-GET, no DICOM ingest — those become plugins"): this is the
  first real case for the plugin loader, which was deliberately never built without one.
- Which operations: C-FIND only (metadata), or also C-MOVE/C-GET (images — then where do they go?).
- Where the server configuration lives: Admin → Settings / `data/settings.yaml`, with credentials
  kept out of the repository.
- Invariant 8: nothing identifying may reach logs or the audit trail from these queries.
- Mapping DICOM body-part / study description to our `region` values (D29, lists).

## 2. Multi-region reports

One report file holding several exam reports done the same day for the same patient — both knees,
three spine regions, abdomen + pelvis — sharing one frontmatter, each with its own text and its
own conclusion.

- Edit each region on its own in the browser, and move between them easily, even though they stay
  in one file.
- Address them with a tail identifier (region 1, 2…).
- To be planned.

To settle when planning:
- **The `@N` tail is taken:** `/{path}@{rev}` is already the revision permalink (phase 2). Regions
  need a different marker (e.g. `#r1`, `/r/1`, `~1`).
- How regions are delimited in the markdown body so that both parsers agree (D17 conformance).
- Signing: one signature for the file, or per region? (D3/D37 sign a revision of the whole page.)
- Index and search: one row per file (today) or per region; `region`/`modality` are already lists
  (D29), which covers the facets.
- Print/PDF/ODT: one document with sections, or one per region.

## 3. A guided "new report" page for restricted namespaces

**Built** — docs/roadmap.md, phase 7 (the DICOM prefill and multi-region parts stay here, ideas 1 and 2).

Creating a page under `reports:` (and similar namespaces) gets its own form instead of a free path:

- patient name (maybe from DICOM, idea 1);
- exam date with a calendar picker;
- CNP (maybe from DICOM);
- site (Mioveni, …) and modality (MR, CT, …), from the configured sites and schemas;
- the app then builds the page path, `reports:{modality}:{site}:{yymmdd}-{lastname}-{firstnames}`
  (D1 — `pages.path_pattern`), and fills the frontmatter (patient, study_date, site, modality);
- optionally the number of anatomical segments up front, or add a segment later while editing
  (ties in with idea 2).

To settle when planning:
- Name → slug rules (diacritics, multiple first names, collisions: `Support\Slug` + the D1 collision
  suffixes in docs/FORMATS.md §1).
- CNP validation (checksum) and deriving sex / birth year from it (D11 patient key).
- Accession numbers are generated at creation (D20) — show it on the form.

## 4. Table of contents in the right margin

**Built** — docs/roadmap.md, phase 8.

The page text (`.wk-prose`, `max-width: 74ch`) does not use the full article width (`.wk-doc`),
so the table of contents (`.wk-toc`, today above the text) could sit in that right-hand space,
fixed (`position: sticky`) while scrolling.

To settle when planning:
- Narrow screens: fall back to the current place above the text (the layout must work at phone
  width, 16 px gutter).
- Highlight the section in view as you scroll (a small script), or keep it static.
- Screen only — print and PDF (dompdf, D34) keep their own stylesheet untouched.

## 5. A plain template for exporting non-report pages

**Built** (2026-09-26): `templates/print/page.php`, chosen by `Support\ReportPath`.

PDF and ODT export always use the report print template (`templates/print/report.php`): letterhead,
patient block, signature, verification link. A page that is not a report (a protocol, a
guide, a teaching case) should export with a simple template — title, text, revision and date.

**What counts as a report** (decided 2026-09-26, shared with idea 6): a page under `reports:` whose
last path segment is a report name, `YYMMDD-name-name` — six digits, then the name
(`reports:mri:mioveni:260926-popescu-ana-maria`, and its collision form `…-maria-2`). The
namespace is what protects reports and the rules already work per namespace, so no frontmatter
field decides it. The other pages in `reports:` — each sub-namespace's default page describing
the site, the modality overview — are *not* reports. One helper (`Support\ReportPath::isReport()`)
answers it for the export, the metadata panel and Publishing's "the path looks like a patient
path" warning, which today checks only the leaf (`^\d{6}-[a-z]`), anywhere.

To settle when planning:
- The same dompdf constraints (D34: tables and block layout, no flex/grid) and the same
  rendered-PDF check for the new template.
- ODT follows automatically: it is built from whatever print HTML the page gets.

## 6. Metadata panel collapsed on non-report pages

**Built** (2026-09-26): a `<details>` panel, open on reports only.

The metadata panel (`.wk-meta`) at the top of the page view carries little of use on non-report
pages (title, visibility, tags), so it should start collapsed there and stay open on reports.

To settle when planning:
- Use `<details>`/`<summary>`, so it works without JavaScript; remember the reader's choice per
  browser, or not.
- The same "is this a report" rule as idea 5 (decided — see there).

## 7. The editor's formatting toolbar

**Planned** — docs/roadmap.md, phase 10 (decided 2026-09-26: no measurement macro, snippets become
idea 9, an Insert template button, Insert prior study also fills `priors`).

The edit page has the toolbar row (`.wk-tbar`) but only its preview toggle works. The mockup
(`design/mockup/WikiEditor.dc.html`) shows: heading, bold, italic, bullet list, numbered list,
table, code, internal link, attach image, measurement macro, insert prior study, snippets
(dictation macros), split preview, copy. Build the buttons and their JavaScript: each wraps the
selection or inserts at the cursor in the raw-document textarea, with keyboard shortcuts.

To settle when planning:
- Only syntax inside the dialect (D17: CommonMark + tables) — bold, italic, headings, lists,
  tables, code, links. No button may produce anything the conformance test does not cover.
- **Measurement macro** conflicts with D18 ("prose only — no measurement macros") and
  **snippets** are D24's expansion macros (`;norm`), which are not built yet: decide whether
  those two buttons exist at all, or wait for D24.
- **Internal link** inserts the canonical `[text](ns:page)` (phase 5); a small picker using the
  search endpoint. **Attach image** reuses the paste/drop upload (phase 5). **Insert prior
  study** needs the patient's other reports (the timeline data).
- Editing through the toolbar must leave external dictation typing into the textarea working
  (D24), undo (Ctrl+Z) intact where the browser allows it (`setRangeText` / `execCommand`), and
  the autosave firing as for typed text.

## 8. Create a new report for the same patient

**Built** — docs/roadmap.md, phase 9.

Start from a report, add option "New report", copy patient data, prefill study date (today), 
copy and tranform significant metadata (for example summary can go to indication), and the 
previous report can be included as "Prior".

## 9. Snippets — D24's expansion macros

Split out of idea 7 (2026-09-26). Typing `;norm` (or picking it from the toolbar's Snippets button)
expands to a stored paragraph — the normal findings for an exam, a standard recommendation.

To settle when planning:
- Where snippets live: editable pages (e.g. under `templates:snippets:`, with history and
  visibility for free) or a settings file; per user, per modality, or shared.
- The trigger: `;name` + space/Enter in the textarea — it must work when an external dictation
  program types the characters (D24), and never fire inside a word.
- Undo: one Ctrl+Z restores the typed `;name`.
- The toolbar's Snippets button (the mockup's lightning icon) is the picker for the same list.
