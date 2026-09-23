# Import & conversion

Turning an existing DokuWiki corpus of imaging reports into Reporion pages, without losing anything and without inventing metadata.

*draft 1 · 22 Sep 2026 · based on three real sample reports · companion to architecture-storage-index.md*

The archive is DokuWiki markup, and its metadata lives in prose rather than in fields. Conversion is therefore two separate jobs with very different risk profiles: a **deterministic syntax transform**, which is safe and testable, and a **metadata extraction**, which is guesswork and must never silently guess.

## 1. What the samples actually show

Three real reports, and each one revealed something the earlier spec had wrong:

| Observation | Consequence |
|---|---|
| Markup is DokuWiki: `====== h1 ======`, `===== h2 =====`, `**bold**`, `//italic//`, `\\` line breaks | a conversion pass is mandatory (§2); it is deterministic and fully testable |
| The **patient name is the H1** | the body carries an identifier — it must be lifted out to `patient.name`, and the H1 replaced by the exam title (§3) |
| One report covers **CT cerebral + CT coloană cervicală** | `region` must be a **list**, not a scalar. Schema and index change (D29) |
| Date appears as `//24.02.2020//` in the body; elsewhere only in the filename | study_date comes from the filename first, body date as corroboration (§3) |
| `~~LLM_TEMPLATE:reports:ct:templates:combinat-cerebral-cervical~~` | you already have a template-reference convention — it maps directly to `template:` frontmatter (§4) |
| An `Indicație:` line, sometimes with age and sex | a real section, and the only place age/sex exist — extract, but keep the text too |
| Some reports have `===== Concluzii =====`, some end without one | section presence varies; the importer must not normalise structure it does not understand |

*Table 1 — observations from the sample corpus, and their consequences.*

> **D29 — `region` becomes a list** — `region: [neuro, spine]`. The index stores regions in a child table (`page_regions`) and the facet counts a page once per region — so a combined cerebral + cervical CT appears correctly under both. A scalar would have forced a wrong choice on every combined study, and combined studies are common in trauma. Same treatment for `modality`, which can legitimately be `[CT]` today and `[MR, MRA]` tomorrow.

## 2. Syntax conversion, DokuWiki → markdown

Deterministic, reversible where possible, and covered by a table-driven test. Nothing here requires judgement.

| DokuWiki | Markdown | Note |
|---|---|---|
| `====== X ======` | dropped, becomes `title`/`patient` | H1 is the patient name (§3) |
| `===== X =====` | `## X` | section level |
| `==== X ====` | `### X` |  |
| `**bold**` | `**bold**` | identical |
| `//italic//` | `*italic*` | careful: not inside URLs |
| `__underline__` | `**bold**` | markdown has no underline; flagged in the report |
| `\\` at line end | two trailing spaces | hard break preserved |
| `[[page|label]]` | `[label](/page)` | colon paths kept; resolved after all pages exist |
| `{{image.jpg}}` | `![](media/…)` | file copied into `data/media/`, hashed |
| `  * item` / `  - item` | `- item` / `1. item` | DokuWiki's two-space indent per level → markdown nesting |
| `^ th ^ th ^` / `| td |` | markdown table | only if the whole block parses; otherwise verbatim + flag |
| `<code>…</code>` | fenced block |  |
| `~~MACRO:…~~` | removed → frontmatter | §4 |

*Table 2 — conversion rules. Anything not in this table is passed through verbatim and reported.*

> ⚠︎ **Round-trip test, not just a forward test.** For every sample report, convert DokuWiki → markdown, render both through their respective renderers, and compare the *text content* of the resulting HTML. Identical text means no content was lost even where formatting was reinterpreted. This is a much stronger check than diffing the markup, and it is the test that makes a 4 000-report import trustworthy.

## 3. Metadata extraction, and the confidence rule

Extraction has one hard rule: **a field is either extracted with confidence or left empty and queued for review.** A wrong `study_date` or a mis-parsed patient name is worse than a blank one, because the blank is visible and the wrong value is not.

| Field | Source | Confidence |
|---|---|---|
| `study_date` | filename `{yymmdd}`, corroborated by a `dd.mm.yyyy` in the body | high; disagreement → review |
| `patient.name` | the H1 line, title-cased | high (every sample has it) |
| `patient.born`, `sex` | `Indicație:` line — "16 ani, sex masculin" → birth year from study year | medium; derived year flagged as approximate |
| `title` | first `=====` section, or the bold line under H1 | high; "IRM Coloană lombară", "Abcese hepatice…" |
| `modality` | title keywords: IRM/RM→MR, CT→CT, Eco/US→US, RX→XR, MG→MG | high |
| `region` (list) | title + section headings keyword map | medium; multi-region studies detected from multiple `=====` sections |
| `site`, `device` | source folder, or a mapping file the importer takes as input | only from the mapping; never guessed |
| `template` | `~~LLM_TEMPLATE:…~~` | exact |
| `accession` | not present in the corpus → generated (D20), marked `generated: true` | n/a |
| `summary` | the `Concluzii` section if present, truncated; otherwise empty | low — never AI-generated at import (D15) |
| `status` | always `archived` | exact; imported pages are never `signed` |

*Table 3 — extraction sources, in priority order.*

> **D30 — the patient name leaves the body** — The H1 is removed and becomes `patient.name` in frontmatter; the page's H1 at render time is the exam title. Two reasons: the name must not be inside text that could be published or sent to a model, and search relevance collapses if every document's most prominent heading is a name. The name still shows in the page header UI, from frontmatter — where publishing and export can strip it mechanically.

> **D31 — `Indicație` stays prose** — It is extracted *from* and left *in* the body. Age and sex get copied to frontmatter for search; the clinical text stays where a radiologist expects to read it. The importer never deletes a sentence it thinks it has understood.

## 4. Macros already in the corpus

```
~~LLM_TEMPLATE:reports:ct:templates:combinat-cerebral-cervical~~
  → template: reports:ct:templates:combinat-cerebral-cervical   (frontmatter)
  → removed from the body
```

Any other `~~…~~` macro is **preserved verbatim in the body and reported**, never silently dropped — the import report lists every distinct macro found with a count, so you decide what each becomes. Since the dialect is plain CommonMark (D17), a surviving macro renders as literal text, which is visible and harmless rather than invisible and lossy.

## 5. The pipeline

```
reporion import:scan   --from /srv/dokuwiki/data/pages --dry-run
   inventory: files, sizes, hashes, detected syntax, duplicate detection
   → data/import/<batch>/manifest.json

reporion import:convert --batch 2020-2026 [--only reports/ct]
   DokuWiki → markdown, per Table 2; unknown constructs flagged
   → data/import/<batch>/converted/*.md + conversion-report.html

reporion import:meta    --batch 2020-2026 --map conf/import-map.json
   extraction per Table 3; low-confidence fields left empty
   → review queue: data/import/<batch>/review.json

reporion import:commit  --batch 2020-2026
   writes real pages through Storage: pid, rev 1, meta.json, index row, audit
   frontmatter: imported_from, import_batch, status: archived

reporion import:rollback --batch 2020-2026     # only while all pages are unsigned
```

Every imported page keeps `imported_from` (original path + sha256) and the untouched source file stays in `data/import/originals/`. That is what makes the import reversible in practice as well as in principle: if the conversion rules improve in three months, re-running on the originals is a supported operation.

> **D32 — commit writes through `Storage`, never to disk directly** — An imported page is indistinguishable in structure from one written today: same pid, same rev files, same journal protection, same index row, same audit entry. The temptation to bulk-write files and reindex afterwards is exactly how a corpus ends up with pages the app cannot quite handle.

## 6. Fixtures

`tests/fixtures/` holds the anonymised set: the DokuWiki source and its expected markdown output, side by side, for each sample shape — lumbar MRI, an abdominal CT follow-up with dates and comparisons, and a combined cerebral + cervical CT with a template macro and multiple regions. Those three cover every rule in Table 2 and every ambiguity in Table 3.

> ⚠︎ **Because the repository is public and GPL-3.0 (D33)** , no real report may ever be committed. Fixtures are anonymised: names replaced, dates shifted, and the clinical text kept because it is what makes the conversion tests meaningful. `data/`, `conf/local.php` and `uploads/` are in `.gitignore` from the first commit, and `bin/reporion doctor` warns if a page directory exists inside the repo tree.

## 7. Order of work

- `import:scan` and the manifest — inventory first, so the scale and the variety are known numbers rather than an estimate.

- The converter plus its table-driven tests and the round-trip text check.

- `import:meta` with the confidence rule and the review queue.

- `import:commit` through `Storage`, on a copy of `data/`, and then a full `index:rebuild` — the first honest performance measurement of the whole system.

- Only then the screens, against 4 000 real pages instead of eight fixtures.
