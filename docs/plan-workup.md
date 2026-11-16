# Plan: Workup, a rail section that gathers the evidence for a report's conclusion

Status: **approved 2026-11-16** (decisions below); to build on `feat/guide` after D62's steps 1–2. Decision row to add: **D63**.
Builds on D62 (the imaging guide, steps 1–2 built on `feat/guide`), which becomes one of its sources.

## Context

Inspiration: the "AI agents" figure of RYAI 250651 (fig. 1). A radiologist asks about a case; the
model calls a reference (STATdx), the EHR, guidelines (ACR) and institutional protocols in turn,
each step showing its result, then gives a ranked diagnosis with its reasons and the next steps.
What makes it useful is not the agent but the **visible, sourced steps**.

Reporion already has each step's source, apart, for other features:

| The figure | Reporion |
|---|---|
| Reference (STATdx) | the library (D58–D60): `PassageSearch` over `passage_fts` + `passage_vectors` |
| EHR | this patient's own pages: prior reports and HIS reports imported as `archived` (D38), `PriorStudy::studies()` |
| Similar cases | finished reports of other patients: `ArchiveAsk::sources()` (D53), `Recall` (D50) |
| Guidelines (ACR) | **the Romanian imaging guide** (D62, `Service\Guide\Guide`) + guideline articles in the library |
| Institutional protocols | pages under `protocols:` (new namespace, ordinary pages; none needed to start) |
| The image | out of scope (D27, D39) |

The owner's direction (2026-11-16): the guide is **one part** of this, not a feature on its own.
D62's step 3 (a rail section of its own) is folded into Workup; its step 4 (the Checks warning) and
step 5 (MCP, `{guide}`) stay as small follow-ups.

## Design

### 1. What the doctor sees
A rail section **Workup** in the editor (a radiologist with the write grant, as D50/D51's
sections), closed by default, with one button *Gather*. It does not run by itself: it costs a
model call. A click shows, as each is ready:

1. **Differential**: up to 4 library passages about the findings, each with its `[@key]` and a
   link to the source (and the page of its PDF when known).
2. **Clinical context**: this report's indication; the patient's prior studies (date, exam, its
   conclusion's first sentence, linked by pid/rev); HIS reports kept for the patient (D38). A
   short line when something often relevant is *absent* is left to the synthesis, never invented
   here.
3. **Similar finished reports**: up to 4 passages of other patients' signed/archived reports whose
   findings resemble these, with how each was concluded; linked `/r/{pid}/{rev}` only (D53's rule:
   never the patient path).
4. **Next steps**: the guide's best-matching situations (D62) for the indication, region and
   patient age, this exam's row marked, each linked to `/guide/{key}`; then `protocols:` pages and
   library sources tagged `guideline` matching the findings.

Then **Synthesis**: one short answer from the model, built **only** from the four blocks, citing
them by number (`[1.2]`, `[3.1]`), in the report's language: a ranked differential with the
reasons in the blocks, what the blocks say against each, and the next steps the guide or a
protocol names. *Copy* per block line and for the synthesis; nothing is written into the report.
Pasting it in and saving makes the revision note `assisted: workup` (D8), as other rail actions.

Each block says when it found nothing ("No library passage matches"), and a source that is off
(no library, no HippoBridge, no guide loaded) is said once and skipped.

### 2. How it runs: fixed steps in PHP, one prompt (not an agent)
`POST /api/v1/workup {path, text, base_rev}` (streamed, as Ask the library: `progress`, `block`,
`delta`, `done`):
1. **What to look for** (`Service\Workup\Query`): from the text sent (the editor's current body),
   the *findings* and *conclusion* sections (`Support\Exams`/section reader), the indication
   (frontmatter + *Indicație* section), `modality`, `region`, age and sex. Search words by
   `RecallTerms` (no model); optionally the existing `ask-expand` prompt for the library's English
   words (one small call, on by default, the `lite` tier).
2. **The four lookups**, each an existing service, each under the caller's visibility (invariant 6):
   - library: `PassageSearch::find()` (words + meaning), never this patient's data in the query
     beyond findings words (no name, CNP, date: the query is built from folded content words);
   - context: `PriorStudy::studies($page)`, HIS pages among them;
   - similar: `ArchiveAsk::sources()` / `Recall` candidates, this patient excluded;
   - next steps: `Guide::forReport()` (new: indication + region + `GuideCodes::typeOfModality()`
     + age), `protocols:` and `tag:guideline` library pages by the same words.
3. **One prompt** through `Ai\Context::workup()` (the only place a prompt is assembled, D15): the
   blocks, numbered, **de-identified** by the Redactor taught this patient's and every cited
   patient's identifiers (as `archive()`), the guide rows as a compact table (public, not
   redacted). Reserved prompt id `workup` (D15's list), shipped in `resources/prompts/radiology/`.
   Model tier `expert` by default (the page's own `model` overrides).
4. Audited `ai.call` (action `workup`, the pid, counts per block, never text); the answer is
   shown, never stored (as Ask the archive).

**Why not a true agent** (the figure's model choosing tools and writing queries): its queries
could carry patient details out (invariant 8), local models call tools unreliably, and a fixed
order gives the same visible steps at a fraction of the cost. The steps are code; only the
synthesis is the model's.

### 3. The guide's part (D62 continued)
- `Guide::forReport(array $facts): list<situation>` (facts: indication text, regions, modality,
  age): the search by the indication's words, Pediatrie first under 18 and last otherwise, the row
  of `typeOfModality(modality)` marked `this: true`; top 3. Tested on fixtures.
- The *Checks* warning (D62 step 4) remains a separate, later step after measuring.
- MCP `guide_search`/`guide_get` and a `{guide}` placeholder for other prompts (D62 step 5)
  remain separate, small.

### 4. Protocols
A namespace `protocols:` of ordinary pages (head page `protocols`, D61), one per local rule
("Chist pancreatic la copil → IRM + colangio-IRM, consult chirurgie pediatrică, markeri"),
frontmatter `tags`. Nothing new in storage; Workup reads them by FTS under visibility. Empty to
start: the block simply has no protocol lines.

### 5. Not done
- No image, no DICOM (D27, D39).
- No ghost text, no automatic insertion (D8, D24); no stored answers.
- No tool-calling by the model; no free-form web lookups.
- No new storage, no index schema change (the guide's tables are D62's).

## Steps (each a commit with its tests)
1. `Guide::forReport()` + tests (D62 continued).
2. `Service\Workup\Workup`: the query and the four blocks, no model (`POST /api/v1/workup` with
   `synthesis: false` returns the blocks); tests incl. visibility (a private report of another
   patient the caller cannot read never appears) and de-identification of block text.
3. `Context::workup()`, the `workup` prompt (radiology target), the streamed synthesis; audit.
4. The rail section (`assets/js/editor-workup.js`, lang strings, CSS), *Copy*, the
   `assisted: workup` note; editor JS test; browser check on a throwaway instance.
5. Docs: D63 (CLAUDE.md row, decisions-detail), architecture-api row, FORMATS for `protocols:`
   if any convention, the prompt placeholders list.

Full suite before merge.

## Decisions (owner, 2026-11-16)
1. `POST /api/v1/workup` and the reserved prompt `workup`: **approved**, one model call per click on the **`expert`** tier by default.
2. The library's English search words by the existing `ask-expand` small call: **on by default**.
3. `protocols:` namespace: **created now, empty**, with a head page explaining the convention (by `ai:prompts-install`-like seeding or by hand; not by a migration).
4. Similar finished reports: **the doctor's own first**, then others the caller can read (as Recall).
