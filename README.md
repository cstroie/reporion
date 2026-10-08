# Reporion

A flat-file wiki for professional imaging reports. Markdown on disk, SQLite for search,
server-rendered documents, no database server and no build step.

Built for one radiologist with several thousand reports across multiple hospitals, and for the
two things that matter at that scale: **finding a report in a second**, and **still being able
to read it in twenty years** because it is plain text in a plain folder.

## What it is

- **Pages are directories.** `data/pages/reports/mri/mioveni/260922-name/` holds the markdown,
  its revision history and its metadata. `cat` works. `grep` works. `rsync` is a full backup.
- **SQLite is a cache.** Delete `index.sqlite` and rebuild it from the files. Nothing is only
  in the database.
- **History is append-only.** Revisions are never rewritten; signed revisions keep their
  digest, and every export cites the revision it was made from.
- **DokuWiki-style namespaces.** `reports:mri:mioveni:260922-name` — a path you can type.
- **Search that works on clinical Romanian.** FTS5 with diacritic folding plus a synonym table,
  because `coledocolitiaza` must match `coledocolitiază`.
- **Public where you want it.** Private by default; individual pages can be public or unlisted,
  and the landing page is itself just a page.

## Features

### Built to outlive you
- **Plain files, not a database you depend on.** Every report is Markdown in a folder
  (`data/pages/...`); SQLite is a disposable search cache you can delete and rebuild. `grep`,
  `rsync`, `cat` all work. No export step is ever needed to get your data out, because it was
  never locked in.
- **Append-only history.** Nothing is overwritten. Every signed revision keeps its digest forever,
  and any export cites the exact revision it came from — a defensible, auditable record.
- **Archive integrity checks.** One command verifies every revision, every signature's digest,
  every media file's hash, and (optionally) that your backup matches byte for byte — with reasons,
  not guesses, and never a patient name in the output.

### Find anything in a second
- **Full-text search tuned for clinical Romanian** — diacritic folding and a synonym dictionary so
  `coledocolitiaza` matches `coledocolitiază`, with facet counts (modality, region, site, device,
  status, tags) and pagination that scale to 10,000+ reports.
- **A real tag system** — groups, synonyms, ICD-10 codes, and suggested merges for near-duplicate
  tags, all editable from Admin → Tags.
- **Patient-aware.** The patient timeline surfaces every visible report for a patient, proposes
  likely matches even without a CNP (spelling variants, missing identifiers), and lets you confirm
  or dismiss — never merges automatically.

### A guided workflow instead of a blank text box
- **One form creates a correct report.** Patient name, CNP (checksum-validated, derives sex/birth
  date/age automatically), site, modality, regions, referrer — the system builds the file path,
  the frontmatter, and the next accession number for you, and warns if this patient already has a
  report for that date.
- **Multi-exam reports, done right.** Right knee + left knee, or a multi-region exam — one file,
  one shared patient block, each exam its own text, its own conclusion, its own accession number,
  one PDF with a section per exam, one signature for the whole document.
- **Join or split as the day goes.** Start a new exam for a known patient pre-filled from their
  last report; join two or more existing reports into one multi-exam report with a review screen
  before anything is written.
- **A real editor, not a textarea.** Formatting toolbar (headings, lists, tables, links), paste or
  drop images, insert a prior study or a template with one click, and `;snippet` text-expansion for
  standard findings — all working without JavaScript as a fallback, and every edit undoable.
- **Reusable templates and checklists.** A template pre-fills a report's structure; its checklist
  (menisci, ligaments, cartilage…) follows the doctor in the editor as a live aid, flags anything
  seemingly unaddressed, and is never saved into the report itself.

### Connects to what you already use
- **HIS integration (HippoBridge/FHIR).** Pulls a worklist of recently performed exams straight
  into the guided form, and brings a patient's prior studies in from the hospital system.
- **PACS integration (DICOM C-FIND).** Per-site worklist queries against your PACS fill the same
  form; a report's PACS tab links it to its DICOM study; a scanner, once linked to a site/device,
  is remembered for next time. A DICOM file can be dropped in directly to start a report from its
  header.
- **Exports that go where you need them.** Letterheaded PDF and ODT (patient block, signature,
  verification link, one section per exam), plain Markdown, and DICOM Structured Report (TID 2000)
  back out to the PACS.
- **An API with real tokens.** Per-user API tokens (read or write scope), separate from the login
  cookie, for scripts and integrations — never usable on the HTML screens.

### An AI assistant that can't leak a patient's identity
- **De-identification is a hard gate, not a setting.** Before any text reaches a language model,
  names, CNPs, accession numbers and paths are stripped — and the request is refused outright if
  any identifier survives the pass. Nothing about this is configurable away.
- **A dozen+ actions where they're needed**: draft, summarize, check quality, suggest a diagnosis,
  flag urgency, suggest tags (including RADS categories like BI-RADS straight from the
  conclusion), rewrite, translate, and a patient-evolution summary across a whole visible history.
- **Pre-sign safety check.** Before a report is signed, rule-based and AI checks catch
  laterality mismatches and other red flags — a second pair of eyes that costs nothing to run.
- **Similar-reports search** via embeddings — surfaces other patients' comparable reports for
  reference, respecting visibility throughout.
- **Bring your own model.** Any OpenAI-compatible endpoint, local or remote; six configurable
  servers with per-tier (lite/normal/expert) models and automatic failover if one is unreachable;
  usage dashboards (calls, tokens, latency, by user and action) with zero prompt/answer text ever
  stored.

### Privacy and accountability by default
- **Private unless you say otherwise**, page by page — private, unlisted, or public, with an
  explicit acknowledgement before anything is made public.
- **A full audit log** of writes, signatures, exports, and logins — keyed to an anonymous page id,
  never a path or patient name.
- **Signed means signed.** A signature's digest is checked against the stored bytes on every view;
  a correction creates a new signed revision, the old one stays in history untouched.

### Scales from one radiologist to a department
- **Bulk operations** — move, tag, export, or join many reports at once, each still individually
  audited; bulk CLI/Admin tasks for re-tagging, summarizing, or fixing metadata across thousands of
  archived reports.
- **Workload statistics** — reports per month by modality/site/signer, turnaround time (exam to
  signature, median and 90th percentile), stale-draft tracking.
- **Multiple sites, modalities, and devices**, each independently configured, with per-site PACS
  and accession-numbering rules.
- **Themeable, responsive UI** — several palettes (light/dark), down to phone width, with no
  JavaScript required for any core action.

## Requirements

PHP 8.1+ with `pdo_sqlite` (FTS5), `mbstring`, `intl`, `zlib`, `dom`, `ffi` (for real fsync —
`ffi.enable=1` on PHP-FPM in production; locally, `php -d ffi.enable=1 -S ...` — the built-in
dev server does not inherit plain CLI's FFI trust, confirmed empirically, and every write 500s
without it). Any web server that can route everything to `public/index.php`. No Node, no
database server, no queue *in production* — Node is a devDependency used only by
`tests/RenderConformanceTest` to check the PHP and marked.js parsers agree (D17); the server
never touches it.

## Install

Step by step — requirements, configuration, web server, first account, settings, scheduled jobs,
backups, going public — in **`INSTALL.md`**. Web server details: `docs/deploy-lighttpd.md`.
Architecture: `docs/architecture-*.md`. Decisions and their reasoning: `docs/DECISIONS.md`.

## Status

Pre-alpha, built in the open. Not a medical device; it stores and indexes documents you write.
You are responsible for the lawful handling of the data you put in it.

## Licence

GPL-3.0-or-later. See `LICENSE`.
