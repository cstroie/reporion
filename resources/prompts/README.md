# Proposed prompts

Starting points for the assistant's prompts (docs/FORMATS.md §13), one file per prompt: the file's
whole content is the prompt page's body. They come in two **targets** (2026-10-20), each a folder:

- `radiology/`: for a profile serving reports. Every prompt, **written in Romanian**.
- `generic/`: for a profile serving ordinary pages. The prompts that make sense for any page
  (`summary`, `tags`, `tags-review`, `commit`, `ask`), **written in English**; they answer in the language of the page.

The web installer (radiology), Admin → AI (*Examples for*) and `bin/reporion ai:prompts-install
--target=radiology|generic` make them pages of a profile (`Service\Ai\DefaultPrompts`); a page that is
there already, edited or not, is left alone. Adapt a page there: nothing reads these files afterwards.
`summary`, `tags`, `tags-review`, `commit` and `ask` exist in both targets; `evolution`, `presign`,
`recall-expand`, `recall-missing`, `ask-expand` and `ask-archive` only for radiology.

| File | Page | Used by |
|---|---|---|
| `summary.txt` | `…:special:summary` | *Summarize* on a report, `pages:summarize`: `{text}` is the conclusion |
| `tags.txt` | `…:special:tags` | *Suggest tags* on a report, `pages:tag`: `{text}` is the whole report |
| `tags-review.txt` | `…:special:tags-review` | `tags:review` (Admin → Tags): `{tags}` is the tag list, no report |
| `evolution.txt` | `…:special:evolution` | the patient timeline's and compare view's Evolution panel: `{text}` is this report, `{history}` the patient's earlier ones as `<report date exam>` blocks |
| `presign.txt` | `…:special:presign` | the Sign screen's *Check before signing*: the whole report; the answer is a `- ` list, or `none` |
| `commit.txt` | `…:special:commit` | the revision note a Save writes when *What changed?* is empty: `{diff}` is the change; lite, 10 s |
| `recall-expand.txt` | `…:special:recall-expand` | the editor's Recall *Expand the line* (D50): `{text}` is the line, `{recall}` the passages recalled for it |
| `recall-missing.txt` | `…:special:recall-missing` | the editor's Recall *What is missing?* (D50): `{text}` and `{recall}` the same; runs on `lite` unless its page says otherwise |
| `ask-expand.txt` | `…:special:ask-expand` | *Ask the archive*'s first step (D53): `{question}` is the question, `{history}` the earlier turns of the conversation as `<turn>` blocks (de-identified, citations removed; empty at the first question); the answer is the question as a complete standalone one, then one word or phrase a report would use per line; lite; without this prompt Ask searches with the question's own words |
| `ask-archive.txt` | `…:special:ask-archive` | *Ask the archive* (`/ask`, D53): `{question}` is the question (a patient's name in it goes as `[nume]`), `{sources}` the passages found for it as numbered `<sursa id="n">` blocks, de-identified; the profile serving `reports` |

## Rail

`table.md` holds the rows of the profile's own page `ai:profiles:{profile}` (docs/FORMATS.md §13),
which decide what the rail shows; the only shipped action is `ask` (`ask.txt` -> `…:ask`): `{prompt}`
gives the rail a question box, the answer comes from `{text}` (radiology: also the patient's earlier
reports, `{history}`); shown only. Grammar, shorten, patient and English were dropped (2026-10-20):
no real use. Add your own rows by hand.

Every answer of the rail is the doctor's to keep or not (D8): `replace` acts on the text in front,
Ctrl+Z undoes it, nothing is saved until Save.

The model and the limits are set in the page's frontmatter (`model:`, `max_tokens:`, `timeout:`),
not here. The radiology prompts are in Romanian, the language the reports are written in; the generic ones in English. They follow
what the server expects of each answer: one line for `summary`; comma-separated tags or `NONE` for
`tags` (`Support\TagList`); one JSON object per line for `tags-review` (`Support\TagProposals`); for `recall-expand` the description alone, which *Replace line* puts in place of the line, with `[blanks]` for what the line does not give; for `recall-missing` a short list, shown and never written; for `ask-expand` the standalone question on the first line, then the words to search for, one per line (`Support\AskExpansion`); for `ask-archive` an answer citing its sources as `[n]`, which the screen lists under it.
