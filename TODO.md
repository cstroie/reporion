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
