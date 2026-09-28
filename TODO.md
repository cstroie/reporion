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
- **Built** — docs/roadmap.md, phase 12 (decided 2026-09-27: the unit is an **exam**, declared
  in an `exams:` frontmatter list, delimited by `##` headings; a conclusion per exam; one
  signature per file; an accession per exam; one PDF with a section per exam). The "to settle"
  questions this idea originally listed (the `@N` tail, how regions delimit in the body, signing
  granularity, index/search shape, print layout) are all answered by that same decision — a plain
  line rule shared by PHP and the editor, one signature per file (D3/D37), `page_exams` alongside
  the existing `region`/`modality` lists (D29), one PDF with a section per exam.

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

**Built** — docs/roadmap.md, phase 10 (decided 2026-09-26: no measurement macro, snippets become
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

**Built** — docs/roadmap.md, phase 11 (decided 2026-09-26: pages under `templates:snippets:`,
shared plus per modality, space/Enter/Tab, a `$0` cursor mark).

Split out of idea 7 (2026-09-26). Typing `;norm` (or picking it from the toolbar's Snippets button)
expands to a stored paragraph — the normal findings for an exam, a standard recommendation.

To settle when planning:
- Where snippets live: editable pages (e.g. under `templates:snippets:`, with history and
  visibility for free) or a settings file; per user, per modality, or shared.
- The trigger: `;name` + space/Enter in the textarea — it must work when an external dictation
  program types the characters (D24), and never fire inside a word.
- Undo: one Ctrl+Z restores the typed `;name`.
- The toolbar's Snippets button (the mockup's lightning icon) is the picker for the same list.

## 10. Parse and apply the imported `~~META: … ~~` blocks

**Built** (2026-09-28) — `Support\MetaBlock` (parsing), `Service\Maintenance\MetaBlockTask` (what
the keys mean), `bin/reporion pages:apply-meta-block [--apply --actor=<u>] [--limit=<n>] [--json]`
(also runs from Admin → Maintenance, same as `pages:normalize-headings`).

1 938 imported reports still carry the original DokuWiki META block in their body, kept verbatim
by the importer (docs/architecture-import.md: unknown macros are preserved and reported):

```
~~META:
nr       = …
&date    = …
&name    = …
&age     = …
&sex     = …
&section = …
&medic   = …
&fo      = …
&diag    = …
&exam    = …
&secv    = …
~~
```

(`nr`, the record number, is the one key the archive writes with no leading `&`.)

**Shipped:** each key maps to a frontmatter field — `patient.name`/`born`/`sex`, `study_date`,
`referrer`, `indication`, `exam_title`, `sequences` — filling it when empty. `&date` and `&exam` win
outright on a disagreement (the block is the original metadata, more reliable than the importer's
filename/heading guess); every other key sends the page to review instead of guessing which value
is right. `&section`, `&fo` and `nr` have no frontmatter field and are dropped with the rest of the
block. `&age` reads years, months, weeks and days, singular or plural, combined ("13 ani 10 luni",
comma or not) or shorthand ("7M"), rolling a count past its own year into the year total. Apply
strips the block from the body and writes one new revision through Storage; a malformed block is
left as it is; a signed report is listed, never rewritten (D3).

**Not code, just running it:** the actual pass over the 1,938 archive reports, and reading what
lands in `review`/`unparseable` by hand — some of those will be genuine importer-vs-archive
disagreements (a wrong site-derived exam_title, say) worth a closer look before deciding which side
was right.

## 11. Frontmatter editing — the user should not see raw YAML

**Built for the common case, 2026-09-27–28** — docs/roadmap.md, phase 14 (asked 2026-09-27: "even if frontmatter is
stored in the same file as the page body, the user — a medic, not tech-savvy — should not see it:
when he edits the page, he should only see the body; frontmatter should be edited separately, in a
more adequate edit interface. The user should be able to access and edit the raw page also";
revised 2026-09-27 after "using the same textarea is not safe (might get corrupted) and cumbersome
since it can take a lot of space" — a plain client-side text splice on the frontmatter YAML was
ruled out for the reason the multi-exam bug earlier that day demonstrated first-hand).

**Shipped:** one `/{path}/edit` form, not a separate `/details` route — a collapsible Metadata panel
(native form fields, schema-driven, `Service\FrontmatterFields`, collapsed by default and above the
toolbar) over a body-only textarea, one Save, one revision. `Service\Publishing::merge()` (extracted
from `apply()`) is the shared write rule: a key not shown is never touched, null clears it; a
`fm_shown[]` marker per rendered field tells "cleared" apart from "never rendered" for a checkbox or
empty multi-select. A body that starts with `---` (a whole document pasted in) is refused. Visibility
and accession show read only, each linking out — visibility to the existing `/{path}/visibility`
screen (its D16 acknowledgement reused as-is, not rebuilt inline as first sketched), accession to raw
mode. A field with no picker is listed read only too, same link. The raw/curated toggle lives in the
page header next to Sign, a button, not a form-embedded link (asked 2026-09-28).

**Still to do:** this covers a single-exam report or any non-report page — the common case, 4 951 of
4 952 archive reports. A **multi-exam report stays in raw mode always** (`exams:` present forces it):
the exam-tabs rework from identity-by-index reassembly to native fields never happened, so the
Metadata panel does not appear there yet. Idea 2's per-exam metadata (title/region/accession as form
fields alongside the tabs) is the remaining piece of this idea.

## 12. AI assistant (DokuLLM in Reporion)

**Built** — docs/roadmap.md, phase 15 (2026-09-27). The editor's Assistant rail, with the
DokuLLM `reports` actions (create, summarize, conclusion, compare, diagnostic, urgent, quality,
linter, rewrite, rapno, normal, translate, custom) as `ai:profiles:reports:*` pages, an
OpenAI-compatible provider, and a de-identifying chokepoint (`Service\Ai\Context`): no name, CNP,
accession or path ever reaches a prompt.

## 13. Minor issues:

The unchecked items below are grouped and planned as docs/roadmap.md, phase 16.

- [x] The description page should not be listed in "Pages in this namespace" — it's already the
  sub-namespace's own row, called by its title
- [x] Find a better name for "All namespaces" — now "Browse"
- [x] "Edit description" should look more like a button (it's not clear to the user it can perform
  an action) — now a secondary button with a pencil icon
- [x] the "H" button on edit toolbar inserts a "##" -- it should start with one '#' — cycles
  plain → # → ## → ### → plain now
- [x] the user homepage should not list everything: under drafts, it should present only the
  recently changed pages — "My drafts" now follows the caller's own hand edits (`pages.edited`/
  `edited_by`, `Support\Revlog`), never revisions an import or a maintenance run wrote under their
  name; a heading-normalizer run no longer floods it
- [x] remove the "indexed in sqlite" text
- [x] the history page displays one blank line between two line in diff mode — a stray newline
  between the `<span>` blocks was rendered as a blank line in the `<pre>`
- [x] site icon as phosphor icon
- [x] search resulsts sorted by age or by relevance
- [x] search in specific namespace
- [x] in edit: pressing list button should insert first list marker ('- ' or '1. '), at the moment it removes current line if empty
- [x] edit: enter pressed in a list creates the next list item
- [x] add a "Copy" button (icon only) in each 'code' block and the javascript to copy the raw content to clipboard; make it small, top-right corner, translucent
- [x] edit: remove the 'Preview' button (near save) and keep only the "split preview button"; split preview side-by-side, not top-bottom
- [x] rename "History & diff" to just "History" (just the button, we keep the diff functionality)
- [x] change the 'compare' functionality: choose the 2 versions of the page, compare old to new word-by-word and print a msword-like render, marking with red and strikethrough deletions and immediately with green additions
- [x] use monospace font for 'compare' body text
- [x] compare tab: improve the design of the from|to|compare toolbar
- [x] in 'patient' tab try to identify other exams of the same patient, even if we do/don't have the CNP (by name): propose to allocate those exams to the same patient, let the user preview them and select — done end to end (2026-09-28): `Index::findPossiblePatientMatches()` proposes by title/name excluding the exact-key set, and the timeline's "Confirm same patient" (`Service\PatientMerge`, `POST /{path}/patient-merge`) writes an explicit `patient.key` override onto the target page — the D11 escape hatch, on the page itself rather than a separate `conf/patient_merges.json`, reversible by deleting the field. "Not the same patient" only hides the suggestion in the browser for now; it is not remembered, so a dismissed match can resurface — persisting that decision is a separate call
- refactor the 'edit raw frontmatter' button
- [x] we can get rid of the 'Revert' menu, since we have the History
- [x] under 925px the wk-menu-r menus are no more visible
- [x] reduce the width of all the wk-menu-r (you can reduce them to 66% of what they are now)
- [x] remove wk-toc-narrow, keep only wk-toc
- [x] in 'Pages in this namespace' table: show title, region (if exam report), status, visibility, updated, by -- with link on title to respective page; 'by' shows the full name, not only the user name -- this should be a rule everywhere
- [x] color code statuses and visibility labels, in tone with color palette
- [x] "Add description" in namespace should go directly to edit page, no 'new page' step: /reports:ct:medicline/edit
- for later - page actions for each page in 'pages in this namespace'
- [x] the frontmatter of a namespace should contain title, tags, summary and visibility: title will be printed in namespace title (h1), tags might be shown along with "3 page(s) directly here", summary may be displayed as a styled subtitle, and visibility (add a pill for it too) controls how will be the visibility of new pages and subnamespaces by default — the display half is built (all already-generic page fields, no schema change); "controls the default for new pages/subnamespaces underneath" is not — a create-time behaviour change, its own decision
- [x] the frontmatter of a namespace and of a non-report page should have no 'template' field
- [x] subnamespace cards should show Title/Id (if none yet), summary, number of pages...
- [x] the content of the namespace description page is displayed below the table as rendered html (no card or panel); move the button 'Edit description' up, near 'New page' as a secondary button (like Add description)
- [x] the template search in 'new' page should match the template name or page name with namespace ('ct' matches 'templates:ct:...')
- [x] 'new page': use 4 flex columns for CNP|Sex|Birth year|Age (add the age, compute it); the CNP should be 33%, the remaining 3 22% each
- [x] 'new page': if the page is wide enough, use 2 columns for wk-tpl-i (Name and page code), to make the items in the list a little bit less tall; for example "Empty page | no template", "Abdomen: CT Normal | templates:ct:..." where '|' symbolises the two colums separator
- [x] 'new page' exam should come before template
- [x] shorten 'Create & open editor' to 'Create'
- [x] make "advanced: path and raw document" a button, near preview | create (secondary style)
- [x] the 'New' button on very top toolbar should create a new page in the namespace the user is currently viewing, and not necessarily a report page -- it may also be a simple (or other future type) page
- [x] in account menu, instead of 'Signed in as cstroie' present directly the full name, title, etc; create a adequate style
- [x] some fonts are very large, like this one: <span class="wk-mono wk-dim">Authorization: Bearer rpn_…</span>
- [x] in 'Namespaces' wk-drawer add also a 'root/home/top namespace' button
- [x] for the code blocks where the language is not specified, add class="nohighlight" to <code>
- [x] try to identify the initials of the user properly, if the name has a prefix, skip over: "Dr. Costin Stroie" -> "CS"
- [x] shorten 'advanced: path and raw document' -> 'Basic'
- [x] shorten 'Edit raw (frontmatter and text as one file)' -> 'Raw edit'
- [x] in "Pages in this namespace" table, under the page title (patient name), show the summary (first integer words, no more than 30 chars, then elipsis), in a muted color, smaller font
- [x] in "Pages in this namespace" table, if a page has no printable title, use the last part from the page namespace name (llm:skills:clinicgen -> clinicgen) to create a clickable text


## 14. Proposals
- let's add something like a 'important' levels (brainstorm on this) to namespaces, in their frontmatter, with several levels (low/med/high, grade 1-3, something - think about it) and colorize the background of the subnamespaces cards according to it (use shades of the palette's highlight color)
- Rename page -- a variation of Move
- Lock a page
- 'Visibility' menu item: show current visibility right aligned ('private') and opens a sub-menu to select new visibility
- View raw page? Export Markdown?
- add a 'Minor edit' checkmark when saving a page -- a minor edit does not create a new revision, updates the current one
- alternate layout for edit page: full screen width, see the mockup
- when i enter the patient name for a new exam, do a real time search to check the same patient name is already known



Also done in this pass, not asked but a fair extension of "the header doesn't say signed clearly
enough": the "signed · rev N" tag (page header and the namespace drawer's "Recently updated here")
now carries a seal-check icon and an accent colour, instead of reading the same grey as a draft.

And: h1/h2 in the report body now pick up the same accent thread as h3 (a short accent tab on
their rule), so the three heading levels read as one family.

