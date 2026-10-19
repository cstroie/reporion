# Handover to HippoBridge: what Reporion needs for archive-quality search

From: Reporion (the radiology wiki, `cstroie/reporion`), plugin `plugins/hipobridge`.
To: whoever builds HippoBridge (`cstroie/HipoBridge`).
Date: 2026-10-19. Status: proposal, for the owner's approval.

## Why

Reporion's *Ask the archive* answers a radiologist's question ("how do we usually describe
white-matter lesions on FLAIR?") from finished reports, cites them by number, and now also from the HIS's
own texts through HippoBridge. The wiki side finds good passages. The HippoBridge side finds few,
because the search is built for "find this patient", not "find passages about this finding".

What the wiki's own search does that HippoBridge's does not:

| | Reporion archive | HippoBridge today (`/fhir/Patient?content=`, `/api/search/text`) |
|---|---|---|
| Several words | match **any**, rank by how many a passage holds | match **all** (every word, as a prefix): a 4-word question finds nothing |
| Result unit | a **passage** (a paragraph of a `###` section), with its date, modality, exam | one **patient** per hit (max 25), with the matching documents listed; no score, no date |
| Text | in the answer | a 12-word snippet; the full text needs one extra request per document (up to 8 per question) |
| Filters | modality, region, date, status (only finished reports) | none |
| Order | relevance (bm25), then the caller's own | not stated |
| De-identification | the wiki knows each report's patient and masks them | only `patient_name` and `patient_cnp` come back; relatives, phones, addresses in an epicrisis are not known to us |

A real example. A question reduces to the words `meningiom flair`. Both must be in one text, as prefixes. A
discharge summary that says "meningiom ... secvențe T2" is not found. Our side already drops filler words and,
when nothing is found, searches the longest words alone and merges the hits. That is a workaround for what
the search should do itself.

## What we ask for, in order

### P0: one call that returns ranked passages (this is most of the gain)

Please add a **FHIR search on `DocumentReference`** (our client only calls `/fhir/*`; a new `/api/*`
route would need an amendment to our own rules, D38). Proposed:

```
GET /fhir/DocumentReference?content=meningiom flair
        &match=any            # any | all (default all, so nothing existing changes)
        &min-match=2          # with match=any: at least N of the words
        &kind=imaging,epicrisis
        &modality=MR,CT       # imaging only
        &date=ge2025-01-01&date=le2026-10-19
        &_count=30            # most documents returned (max 50)
        &max-chars=900        # the best passage(s) of each document, in total this long
        &context=1            # also the sentence before and after the best match
```

Response: a searchset Bundle of `DocumentReference`, **one per document** (not per patient), best first:

- `id` = the same id we already read with (`CO1`, or the request id), plus `type` (`epicrisis` | `imaging`).
- `date` (the study date, or the discharge date), `category`/modality, a title if there is one (exam name).
- an extension `search-score` (valueDecimal, bm25 or similar; higher is better).
- the **passage text** in `content[0].attachment.data` (plain text or Markdown, with the matched words **not**
  wrapped in `<mark>`; give the marks as a separate `search-snippet` extension if you want them). This replaces
  our N extra `GET /fhir/ImagingStudy/{id}` and `GET /fhir/Encounter/{id}?type=checkout` calls.
- an extension `search-identifiers`, see P1.

Keep the existing `/fhir/Patient?content=` and `/api/search/text` exactly as they are.

### P1: things that make the results usable and safe

1. **Identifiers for de-identification.** For each document return everything HippoBridge knows that names a
   person in it: patient name, CNP, and, if the HIS record has them, address, phone, relatives, employer. Reporion
   masks exactly those words, instead of guessing with patterns. (Or an optional `deidentify=1` that masks them
   in the returned text. We would still mask on our side.)
2. **Only validated text.** An imaging report that is still a draft must not come back (a `status` extension, or a
   filter `status=final`, default final). The wiki also only uses finished reports.
3. **Index passages, not whole documents.** Cut each note into paragraphs or sentence groups (500 to 900
   characters) and match inside one passage, so "meningiom" and "flair" count only when they are near each other,
   and the returned text is the matching part. A discharge summary has one long note; "match in the same document"
   is too loose.
4. **Phrases.** `content="hernie de disc"` in quotes matches the phrase. Make sure user input cannot break the query
   (FTS5 syntax characters).
5. **Prefix on every word** (now the case), diacritics folded (now the case), and a way to send **alternatives**:
   `content=meningiom|meningioame` (OR inside a word slot). We already expand synonyms (`resources/synonyms.txt`);
   with `match=any` we can send the expansions ourselves.

### P2: so we can trust and tune it

6. **A stats endpoint**: how many documents are indexed per kind and modality, the date range, when the last one was
   added, how many are skipped (drafts, empty). `GET /fhir/Metadata` could list the search parameters it supports.
7. **Speed and limits**: a search under 1 s at your largest index; documented rate limits; a 4xx/5xx contract
   (we turn any failure into a "HippoBridge was left out of this answer" notice, and still answer from the wiki).
8. **Sections** if the imaging text has them (Descriere / Concluzii): a `section` on each passage, so we can show
   "Concluzii" and prefer it.

### Later, to decide with the owner (not part of this ask)

- **Semantic search**: either `GET /fhir/DocumentReference?similar=<text>` with embeddings on your side, or a
  read-only bulk export (`?_since=`, paged) that lets Reporion embed the texts itself, as it does for its own reports
  (the `page_vectors` cache). The export copies HIS text into a second system: a privacy decision, not a technical one.

## Proposed plan

1. **Step 1 (smallest, biggest win), about the size of the `content` search you just built:** `DocumentReference`
   search with `match=any`, `min-match`, per-document results, `search-score`, the passage text, `max-chars`,
   `kind`, `date`, `modality`, `_count`. Reuse the existing FTS5 index; for now a passage is the matching note.
2. **Step 2:** `search-identifiers`, validated only, phrases, OR inside a word slot, stats.
3. **Step 3:** passage-level index with `context`, `section`. Reindex.
4. **Step 4 (optional):** semantic search or export, after the owner decides.

We switch `plugins/hipobridge/Ask.php` to the new call as soon as step 1 is up. It already handles the rest
(numbering, de-identification, the answer, the notice when you fail).

## How we will judge it (acceptance)

A list of about 20 real questions (Romanian), each with the documents a radiologist would expect (we pick them
from your index together). For each, measure **recall in the top 8** and the latency. Examples:

- *Ce formulări folosim pentru leziuni de substanță albă?* (modality MR, imaging)
- *Cum descriem o hernie de disc cu compresiune radiculară?*
- *Ce se recomandă la un nodul tiroidian sub 10 mm?* (epicrisis and imaging)

Step 1 is good enough when the question that returns nothing today (all words together) returns the documents a
person would pick, in the top 8, within 1 s.

## What stays on our side

Filler-word and name filtering of the query (nothing identifying leaves Reporion), synonym expansion, merging with
the wiki's passages, de-identification and the final redaction check, the model call, the answer and its citations.
HippoBridge stays read-only for us (GET, one service account, HTTP Basic).
