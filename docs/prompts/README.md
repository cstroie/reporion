# Proposed prompts

Starting points for the assistant's reserved prompts (docs/FORMATS.md §13), one file per prompt:
the file's whole content is the prompt page's body. The web installer offers to make them pages of
the default profile (*Create the proposed prompts*, Configure step: `Service\Ai\DefaultPrompts`,
a page already there is left alone); otherwise copy one into the page
`ai:profiles:{profile}:special:{id}` of the profile serving your reports. Either way, adapt it there:
nothing reads these files afterwards.

| File | Page | Used by |
|---|---|---|
| `summary.txt` | `…:special:summary` | *Summarize* on a report, `pages:summarize`: `{text}` is the conclusion |
| `tags.txt` | `…:special:tags` | *Suggest tags* on a report, `pages:tag`: `{text}` is the whole report |
| `tags-review.txt` | `…:special:tags-review` | `tags:review` (Admin → Tags): `{tags}` is the tag list, no report |
| `recall-expand.txt` | `…:special:recall-expand` | the editor's Recall *Expand the line* (D50): `{text}` is the line, `{recall}` the passages recalled for it |
| `recall-missing.txt` | `…:special:recall-missing` | the editor's Recall *What is missing?* (D50): `{text}` and `{recall}` the same; runs on `lite` unless its page says otherwise |
| `ask-archive.txt` | `…:special:ask-archive` | *Ask the archive* (`/ask`, D53): `{question}` is the question (a patient's name in it goes as `[nume]`), `{sources}` the passages found for it as numbered `<sursa id="n">` blocks, de-identified; the profile serving `reports` |

## Rail actions (`actions/`)

Proposed actions for the editor's Assistant rail (2026-10-19, after Notion AI's): `actions/{id}.txt`
is the page `ai:profiles:{profile}:{id}`, and `actions/table.md` the rows of the profile's own page
`ai:profiles:{profile}` (docs/FORMATS.md §13), which decide what the rail shows and in what order.
The installer makes the action pages, and the profile page with this table only when the profile
has none; to an existing profile, copy the rows you want into its first table by hand.

| File | Page | Result |
|---|---|---|
| `actions/ask.txt` | `…:ask` | *Ask about this report*: `{prompt}` gives the rail a question box; the answer comes from `{text}` and the patient's earlier reports, `{history}`; shown only |
| `actions/grammar.txt` | `…:grammar` | spelling, diacritics and punctuation only: replaces the selection, else the pane's text; `lite` |
| `actions/shorten.txt` | `…:shorten` | the same findings in fewer words: replaces |
| `actions/patient.txt` | `…:patient` | the report in plain words for the patient: shown, never written |
| `actions/english.txt` | `…:english` | the report in English for a referral abroad: shown, never written |

Every answer of the rail is the doctor's to keep or not (D8): `replace` acts on the text in front,
Ctrl+Z undoes it, nothing is saved until Save.

The model and the limits are set in the page's frontmatter (`model:`, `max_tokens:`, `timeout:`),
not here. The prompts are in English (D26: English chrome); they ask for answers in `{language}`, the language reports are written in, and their examples are Romanian tags. They follow
what the server expects of each answer: one line for `summary`; comma-separated tags or `NONE` for
`tags` (`Support\TagList`); one JSON object per line for `tags-review` (`Support\TagProposals`); for `recall-expand` the description alone, which *Replace line* puts in place of the line, with `[blanks]` for what the line does not give; for `recall-missing` a short list, shown and never written; for `ask-archive` an answer citing its sources as `[n]`, which the screen lists under it.
