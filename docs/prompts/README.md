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
| `tag-review.txt` | `…:special:tag-review` | `tags:review` (Admin → Tags): `{tags}` is the tag list, no report |

The model and the limits are set in the page's frontmatter (`model:`, `max_tokens:`, `timeout:`),
not here. The prompts are in English (D26: English chrome); they ask for answers in `{language}`, the language reports are written in, and their examples are Romanian tags. They follow
what the server expects of each answer: one line for `summary`; comma-separated tags or `NONE` for
`tags` (`Support\TagList`); one JSON object per line for `tag-review` (`Support\TagProposals`).
