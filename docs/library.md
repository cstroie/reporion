# Library and notebooks (planned, 2026-10-30)

A reference library for the team, after Mendeley (an article recognised and kept once, a
bibliography built while writing) and NotebookLM (a subject's sources read, asked and condensed by
the assistant). Status: **phases 1–3 built** (2026-10-30, D58, `feat/library`): ingest and identify, offline; the
library view, the upload form and a source's card; `[@key]` citations and the References section. The rest is planned.

## Owner's decisions (2026-10-30)

| Question | Decision |
|---|---|
| Where article records live | **One central library**, the `library:` namespace (setting `library.namespace`), one page per article; notebooks cite and link them |
| Originals | **`data/sources/{ab}/{sha256}.{ext}`**, content-addressed, backed up with `data/` (an on-disk layout change: approved) |
| Who sees the library | **Signed-in only.** Library pages are `private` only (republishing a paper is redistribution; `unlisted`, readable by anyone with the link, ruled out by the owner the same day); a notebook may be published with its bibliography |
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
   section (kept apart for phase 7), insert page markers (`*— p. 12 —*`, plain markdown; an anchor syntax comes with phase 3) so a citation opens the
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
7. **Create** through `Storage`: the original into `data/sources/` (`Storage::storeOriginal()`, temp
   file + link, outside the journal like media: an original written before a crash and named by no
   page is harmless), then the page `library:{key}` (`create()`, its own journal line).

**Patient guard:** a document whose text holds a valid CNP is refused with a notice, nothing kept: the
library is literature, never patient data (invariants 8 and 10). A file with no text (a scan) cannot be
checked, so it is refused too until OCR gives it text that goes through the same check (owner,
2026-10-30). A title is never taken from the file's name (a file may be named after a person): the
text's first line, else *Untitled document*. Recognising an epicrisis without a CNP is not built.

**Always private:** a page carrying `csl` or `original` (`Support\LibrarySource`) is refused as `public`
or `unlisted` (anyone with the link could read the article's text; owner, 2026-10-30) by `Storage::create()`/`save()` (`SourceVisibilityException`), wherever it is moved; the editor and the
visibility screen say so first.

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

1. **Ingest + identify (offline)**, **built 2026-10-30**: `Storage::storeOriginal()`, `Service\Library\Extractor`,
   `Support\SourceText`, `Support\ArticleIds`, `Service\Library\Identify`, `Service\Library\Library`
   (dedupe, create); `POST /api/v1/library/uploads` (proposal), `POST /api/v1/library` (confirm),
   `GET /library/file/{pid}`; `bin/reporion library:add`; doctor checks the tools; integrity:verify
   checks every `original.sha256`. Fixtures are synthetic articles written for the tests, never real
   papers. *As built:* an original no page names is not swept (as media); PHP-FPM's `post_max_size`
   and `upload_max_filesize` must allow `library.max_mb`; the source page's title is frontmatter
   `title` (the article's text has no `#` of its own); the never-public guard came forward from
   phase 2. For phase 2's form: `confirm()` uses the token up before the duplicate check in
   `create()`, so a 409 there means uploading again; check first when the form is built.
2. **Library view + metadata form**, **built 2026-10-30** (the publishing guard came with phase 1):
   `GET /library` (`templates/library.php`), the upload's proposed records corrected in a form
   (`partials/library-record-form.php`, `assets/js/library.js`), a source's card on its page
   (`partials/library-source.php`: the reference in Vancouver, `Support\Citation`; open the original;
   copy the reference; Edit record, `PUT /api/v1/library/{pid}/record`), the quick-nav's *Library*.
   *As built:* sorted by author, year or title and filtered by one tag (links) or by text (in the
   browser); no year/journal/author facets yet, no *notebooks citing it* column (that needs phase 3's
   citations). `/library` is the view's address (owner, 2026-10-30: fine), so a page named `library` is reached only by its
   edit and history URLs; the quick-nav link shows to a reader of the `library` namespace (not of a
   renamed `library.namespace`). A confirm that is refused (a duplicate, a taken key) keeps the
   upload for another try (`TempUploads::peek()`).
3. **Citations + References section**, **built 2026-10-30** (no schema change): `[@key]`, `[@key, p. 12]`,
   `[@a; @b]` parsed by `Service\Markdown\CitationParser` (a `CitationNode`), resolved by `Service\Render`
   against a `Markdown\CitationSource` (`Service\Library\Citations`: the page `{library}:{key}`, its `csl`
   only) and shown numbered by first citation (Vancouver, AMA) or author-date (APA), with a **References**
   section appended (`Support\Citation::vancouver|ama|apa|apaLabel`); the page's `citation_style` (default
   Vancouver) picks the style. A key no source has is marked and a render warning. A source links to its
   library page only for a reader of the library (`Render::body(citeLinks:)`), never in print or an export;
   an anonymous reader of a public notebook gets the reference printed from the record, no link. The
   editor's **`@` picker** (typed `@` at the start of a word; `GET /api/v1/search?ns=library`; no new
   endpoint) inserts `[@key]`. *As built:* the reference list heading is `library.references` (English;
   the notebook's `language` is not read yet); the editor's preview styles with Vancouver unless
   `POST /render` is sent `citation_style`; reference text is plain (no italics); the render check is
   `tests/Render/RenderCitationsTest` (a fixture file cannot carry a source). **Not built (next, with
   the index):** the *cited by* column and *notebooks citing it* (backlinks need `[@key]` in the links
   table: an index change, to ask first), `page:move` fixups for a renamed key.
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
