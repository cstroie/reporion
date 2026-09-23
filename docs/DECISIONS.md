# Decision log

Every architectural decision taken during design, with the reasoning compressed to what a
future maintainer needs. **Do not relitigate these in code.** To change one, change it here
first, note the date, and update the affected spec in the same commit.

Sources: `docs/architecture-storage-index.md`, `docs/architecture-api.md`,
`docs/architecture-import.md`.

## Storage and identity

| # | Decision | Why |
|---|---|---|
| D1 | Patient name stays in the page path (`{yymmdd}-{name}`) | Biggest driver of daily speed. Compensated: the path never appears in an export, external URL, log line, audit entry (`path_hash`) or AI prompt |
| D2 | `current.md` duplicates the newest revision | Reading is the most common operation; it must never require gunzip. ~2× storage on one revision is nothing at 2.4 kB/report |
| D3 | Correcting a signed report = new revision, signed again; old signed revision stays in history | Simpler than addendum rendering. Consequence: exports embed `rev` + a `/r/{pid}/{rev}` verification link, which becomes the citable document |
| D3b | Signed content can be superseded, never deleted | Legal integrity. Purging a signed page needs an explicit override + audit entry |
| D21 | App-managed history, not git | No auto-commit, no 4 000 git directories. `rev/*.md.gz` + `revlog` *is* the history |
| D30 | The DokuWiki H1 (patient name) is lifted to `patient.name`; rendered H1 is the exam title | No identifier inside body text that could be published or sent to a model; also fixes search relevance |

## Index and search

| # | Decision | Why |
|---|---|---|
| D4 | Typed columns for the facet set, `meta_json` for the tail | A generic key/value table makes every faceted query a pile of self-joins |
| D5 | FTS5 with `remove_diacritics 2` | Romanian clinical text is written with and without diacritics by the same person on the same day |
| D28 | Query-time synonym expansion + prefix match on the last token; no stemmer | SQLite has no Romanian stemmer, and the clinical vocabulary is small, closed and yours — synonyms beat a stemmer here |
| D29 | `modality` and `region` are **lists** | A combined CT cerebral + cervical must appear under both. Child tables `page_regions`, `page_modalities`; facets count a page once per value |
| — | Index carries `schema_version`; mismatch triggers rebuild | Lets the schema change fearlessly, which matters over years |

## Access and identity

| # | Decision | Why |
|---|---|---|
| D6 | `visibility` (private/unlisted/public) replaces ACL entirely | Single user. Removes the most bug-prone subsystem in the original design. Reopen this seam only if a second user appears |
| D13 | One owner account, argon2id, signed session cookie. No 2FA, no user table | Personal system. `auth.login` stays a hook so LDAP is a later plugin |
| D14 | No review step — the owner signs their own reports | `page.sign` stays a hook so a veto plugin is possible |
| D16 | Public site = public pages + `layout-public.php`; `site:home` is the landing page | One page set, two audiences. Publishing requires an explicit acknowledgement of what becomes visible, and is audited |
| — | Anonymous requests that are not entitled get **404, never 403** | A 403 confirms the page exists |

## Content and rendering

| # | Decision | Why |
|---|---|---|
| D17 | marked.js for the editor preview, PHP for everything canonical. Dialect: generic CommonMark + tables | Keystroke-latency preview is worth a second parser *only* if bounded: pinned dialect + a conformance test that fails the build on any difference |
| D18 | Prose only — no structured findings | Speed of writing wins. Accepted cost: the timeline and compare screens summarise text, they cannot chart lesion counts. `summary` is the indexed escape hatch |
| D19 | Existing templates imported as-is; no inheritance | Templates are pages under `templates:`; "new report" copies one. Zero template-engine code |
| D7 | `required` blocks **signing**, never **saving** | A half-dictated draft must always save. This single rule prevents the most common way clinical software becomes hated |
| D31 | `Indicație` text stays in the body; age/sex are *copied* to frontmatter | The importer never deletes a sentence it thinks it understood |
| D24 | No dictation | Romanian speech recognition is not good enough to justify the subsystem. Expansion macros (`;norm`) instead; external dictation typing into the textarea must keep working |
| D26 | English chrome, Romanian content; strings in `lang/en.php` | Romanian UI later becomes a translation job, not a refactor |

## Patient linkage

| # | Decision | Why |
|---|---|---|
| D11 | `patient_key = sha256(cnp)` when present, else `sha256(name\|born\|sex)`; store both | CNP is rarely written down. Filling it in on *any one* report retroactively links that patient's history at the next index pass |
| — | Near-key "possible matches" + `conf/patient_merges.json` | Typos and marriage names split a hash key; surface it rather than silently merging or missing |

## Media, export, AI

| # | Decision | Why |
|---|---|---|
| D27 | Media in `data/media/{year}/{sha256}.{ext}`; clipboard paste + file drag | Content-addressed dedupe without a hash fan-out a personal archive will never need. No DICOM ingest in core |
| D34 | PDF via **dompdf** | Installs anywhere, no binary. Cost: no flexbox, no grid, no `color-mix()`, no CSS `rotate()` — print templates are table-based with mm widths, in their own stylesheet |
| — | Exports at launch: PDF with per-site letterhead, ODT, markdown | DICOM SR, bulk result-set export and expiring share links are later plugins |
| D15 | AI provider interface ships with no provider enabled; `Ai\Context::build()` is the only code that may assemble a prompt | Cheapest decision to defer — provided the chokepoint exists from day one, so enabling a provider later cannot bypass identifier stripping |
| D8 | Generated text is never silently authoritative | An AI draft is a normal revision attributed to `assistant`; it can be edited and signed by a human, never *by* the machine |

## Platform and operations

| # | Decision | Why |
|---|---|---|
| D12 | One instance, all sites, over VPN | Federation only pays if sites cannot share a network or a legal basis. Consequence: the editor must survive a dropped connection |
| D23 | lighttpd + PHP-FPM, PHP 8.1 floor, docroot is `public/` only, rewrite in server config | 7.4 is out of security support; lighttpd has no `.htaccess`, so `doctor` asserts the docroot and rewrite rather than documenting them |
| D22 | Backup = rsync over SSH, `--link-dest` dated snapshots, encrypted target volume. Not encrypted at rest by the app | A backup that faithfully mirrors an accidental deletion is not a backup |
| D25 | Survive connection drops; no offline mode | IndexedDB draft + retry + 409 flow. No service worker, no sync engine |
| D33 | Public repository, GPL-3.0-or-later | `data/`, `conf/local.php`, `uploads/` gitignored from the first commit; fixtures anonymised; no real name, CNP or accession anywhere in the repo |

## Architecture shape

| # | Decision | Why |
|---|---|---|
| — | API-first backend, server-rendered documents + JS islands | Not a full SPA: the signed PDF must come from server HTML, shared reports must open in a locked-down browser, and a report opened twenty times a day should paint in one request |
| D9 | `StorageInterface` and `IndexInterface` are swappable **drivers**, not plugin territory | A plugin that can reach past the service layer into files can corrupt history |
| D32 | `import:commit` writes through `Storage` | An imported page must be structurally identical to a native one: pid, rev, journal, index row, audit |
| D20 | Reporion generates accessions `{SITE}-{MOD}-{yy}-{seq}`, counter in `data/counters.json`, allocated inside the journal-protected create | No HL7 in a personal deployment. Editable afterwards when a real accession exists |
| A2 | Revert is a forward operation | Restoring rev 6 writes rev 8 with rev 6's content. History never loses or rewrites a step |
| A3 | AI responses stream (SSE) and never write to the page | Insertion is an ordinary `PUT /pages/{path}` attributed to `assistant` |
| A4 | Public chrome is a different template, not a different app | Same document markup, so the reader view cannot drift from the report view |
