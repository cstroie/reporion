# Library and notebooks (planned, 2026-10-30)

A reference library for the team, after Mendeley (an article recognised and kept once, a
bibliography built while writing) and NotebookLM (a subject's sources read, asked and condensed by
the assistant). Status: **planned**; to be recorded as D58 when phase 1 starts. Nothing here is built.

## Owner's decisions (2026-10-30)

| Question | Decision |
|---|---|
| Where article records live | **One central library**, the `library:` namespace (setting `library.namespace`), one page per article; notebooks cite and link them |
| Originals | **`data/sources/{ab}/{sha256}.{ext}`**, content-addressed, backed up with `data/` (an on-disk layout change: approved) |
| Who sees the library | **Signed-in only.** Library pages are `private` or `unlisted`, never `public` (republishing a paper is redistribution); a notebook may be published with its bibliography |
| Who adds and runs the assistant | **Any editor** with a grant on `library` (and on the notebook's namespace); not tied to the radiologist flag |
| Online lookups | **Opt-in** (`library.lookup`, off by default): CrossRef and PubMed get a DOI, a PMID or title words only, never page text |
| Citation styles | **Vancouver** (default), **APA**, **AMA**, hand-written (`Support\Citation`), no CSL engine dependency |
| Output language | **Per notebook**: the notebook's frontmatter `language` (as EPUB's, D57), default `ro`; quotes stay in the source's language |
| Scanned PDFs | Rare: a scan is kept with its metadata and flagged *no text*; OCR (tesseract) is a later phase |
| Size | 50 MB per file (`library.max_mb`) |
| Citations a reader may not follow | Printed from the record, no link to the library page |
| Long sources | Adaptive: whole when they fit `library.context_tokens` (32 000), else map-reduced by section |
| Starred / read marks | None |
| Where it is built | `feat/library` branch in a worktree, full suite by the owner before merging |

## Words

- **Source**: one article (or book, guideline, web page), a page `library:{key}`. Its body is the
  extracted, cleaned text; its frontmatter `csl:` the bibliographic record (CSL-JSON, as Zotero,
  Mendeley and pandoc use) and `original: {sha256, name, mime, size, pages, text: true|false}`.
  A source may have no original (a record imported from a `.bib`, or a paper only cited).
- **Key**: the citation key, `{first author}{year}{a,b…}` (`iwata2019`), which is also the source's
  slug: `[@iwata2019]` resolves to the page `library:iwata2019` with no index change.
- **Notebook**: any page about one subject that lists sources in `sources: [key, …]` and/or cites
  them in its body. It is not a new kind of page.

## Why a source is a page

History (attic), search, tags, the visibility predicate (invariant 6), trash, backup, comments
(highlights), Ask (D53) and MCP (D56) come free; no new table. Only the original file is new storage.

## Pipeline

1. **Upload** (drag several files onto the library or a notebook's *Sources* panel; `library:add`):
   the file goes to `data/tmp/` (`TempUploads`), never further, until confirmed.
2. **Extract**, every tool run as an argument list (no shell, private temp dir, timeout: as groff,
   D42): txt/md as is; pdf `pdftotext` (+ `pdftohtml -xml` for font sizes → headings, title);
   docx/odt/rtf/html/epub `pandoc → gfm`; doc/ppt/xls `soffice --convert-to` first. A PDF with no text
   layer is a scan: kept, flagged.
3. **Clean** (`Support\SourceText`, pure, no AI): rejoin hyphenation and wrapped lines, drop repeated
   headers/footers and page numbers, fix ligatures, find headings, the abstract and the references
   section (kept apart for phase 7), insert page anchors (`<a id="p12"></a>`) so a citation opens the
   original at `#page=12`. An AI tidy-up is an optional button later, never automatic.
4. **Identify**, each step only when the previous found nothing: the PDF's own metadata
   (`pdfinfo -meta`, XMP DOI/title); identifiers in the first pages (`Support\ArticleIds`: DOI, PMID,
   PMCID, arXiv, ISBN); the layout guess (largest font on page 1 = title, the lines under it =
   authors); with `library.lookup` on, CrossRef `works/{doi}` or a title query above a score threshold,
   PubMed `efetch` (abstract, **MeSH terms proposed as tags**); with lookups off, the assistant may
   read page 1 into the record.
5. **Confirm**: a pre-filled metadata form (title, authors, journal, year, volume, pages, DOI, PMID,
   type, tags); nothing is authoritative until the writer saves it (D8).
6. **Duplicates**: by DOI, PMID, folded title + year, then sha256. A second upload of a known article
   attaches to it (adds the original if it had none); a notebook upload links the existing record.
7. **Create** through `Storage`: the original into `data/sources/` (`Storage\SourceStore`, atomic),
   then the page `library:{key}`; one journal line covers both.

**Patient guard:** a document whose text holds a valid CNP, or that is a patient file (epicrisis), is
refused with a notice: the library is literature, never patient data (invariants 8 and 10).

## Citations and bibliography

- Syntax: pandoc's `[@iwata2019]`, `[@iwata2019, p. 12]`, `[@a; @b]`, added to `Service\Markdown`
  with fixtures (invariant 4): page view, print, PDF, ODT, EPUB number them alike.
- The editor's `@` picker searches the library (opened like the slash menu, D24 rules: never on
  dictated text).
- A **References** section is appended from the citations used, in the notebook's style
  (`citation_style`: `vancouver|apa|ama`, page or namespace default). Vancouver numbers by first
  citation.
- A reader who may not see a cited library page (an anonymous reader of a public notebook) gets the
  reference printed from its record (bibliographic facts are public; a DOI as a `doi.org` link)
  without a link to the library page: its text and original stay private (owner, 2026-10-30).
- `page:move` fixups learn citations; a key never changes by itself.
- Export a notebook's or the library's bibliography as BibTeX, RIS, CSL-JSON or the formatted list;
  import BibTeX/RIS/CSL-JSON (a Mendeley or Zotero export) as records with no original, matched to a
  later upload by DOI.

## The library view

`/library`: a table of what the caller may see (the index, under the predicate; a new listing, so the
visibility matrix gets its case first): authors, year, title, journal, tags/MeSH, notebooks citing it.
No starred or read/unread marks (owner, 2026-10-30): tags and notebooks are enough. Facets: year, journal, author, tag. A source's page shows the
cleaned text, *Open original at page N* (`GET /library/file/{pid}`, same visibility), its record,
*Cite*, *Copy BibTeX*.

## The assistant (each result proposed in the rail, applied by the writer: D8)

- **Per source** (reserved prompts, D15 pattern, `{source}` placeholder): summary (into `summary`, so
  indexed), key points, definitions, classifications and staging as a table, study design / n / level
  of evidence, limitations, questions it answers.
- **Ask the notebook**: Ask (D53) limited to the notebook and its sources, answers citing `[@key]`
  that link to the source's page anchor.
- **Studio** (map-reduce over the sources, in the notebook's `language`, citing `[@key]`):
  systematization (definition, epidemiology, pathophysiology, imaging by modality, classification,
  differential, pitfalls, follow-up), glossary, FAQ, timeline, a comparison table (one row per source,
  columns the writer's questions), *check this page against its sources* (each claim supported,
  contradicted or not found).
- **Back into reporting**: from a notebook, propose a report template (D45 shape), its `checklist`,
  snippets, and set the notebook as the template's `reference` (phase 25).

## Phases

1. **Ingest + identify (offline)**: `Storage\SourceStore`, `Service\Library\Extractor`,
   `Support\SourceText`, `Support\ArticleIds`, `Service\Library\Identify`, `Service\Library\Library`
   (dedupe, create); `POST /api/v1/library/uploads` (proposal), `POST /api/v1/library` (confirm),
   `GET /library/file/{pid}`; `bin/reporion library:add`; doctor checks the tools; integrity:verify
   checks every `original.sha256`; trash:purge removes an original no page names. Fixtures are
   synthetic articles written for the tests, never real papers.
2. **Library view + metadata form + publishing guard** (library pages never `public`).
3. **Citations + References section** (render fixtures), `@` picker, `Support\Citation`.
4. **Online lookup** (CrossRef, PubMed, opt-in) + BibTeX/RIS/CSL-JSON import and export.
5. **Per-source assistant + Ask the notebook.**
6. **Studio** + the template generator.
7. **Passage vectors** (TODO 19: a `vectors.sqlite` schema change, disposable), highlights from
   comments with *Send to page*, *What this paper cites* (its reference list against the library),
   web clip by URL (opt-in).
8. **Later**: OCR, flashcards / Anki export for teaching, an audio overview.

## Long sources

The context is adaptive (owner, 2026-10-30): `library.context_tokens` (default 32 000). A source
whose text fits is sent whole; a longer one is map-reduced by its sections (each section condensed,
then the condensed parts answered together), whatever the server.

## How it is built

On a `feat/library` branch in a worktree (`~/.claude/worktrees/library`), checked on a throwaway
instance, merged after the owner has run the full suite (it touches storage, rendering and
visibility).
