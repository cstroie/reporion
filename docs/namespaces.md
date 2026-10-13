# Namespaces — what each kind of page is, and where it lives

A page's **kind** is told from its path alone (`Support\PageKind::of()`); no frontmatter key decides
it. This file lists the kinds, who reads them, what this instance actually holds, and a proposed
tidy-up. The *Proposal* section is for the owner to approve **before any live page moves** (2026-10-13).

## 1. The kinds today

| Kind | Path | Read by | Notes |
|---|---|---|---|
| **Report** | `reports:{modality ns}:{site}:{yymmdd}-{name}` | everything: `ReportPath::isReport()` | The leaf shape is what makes it one; other pages under `reports:` (a site or modality description) are ordinary pages. Modality ns from `reports.modality_namespaces` (Admin → Settings). |
| **Template** | `templates:{modality ns}:{name}` | new-report form, editor pickers, checklist, reference (D45, FORMATS §3i-b) | `PageKind::TEMPLATE`. Metadata and the exam name are used; the text is only inserted on request. |
| **Snippet** | `templates:snippets:{name}` (shared), `templates:snippets:{modality ns}:{name}` | editor `;name` expansion (D24) | Exactly 3 or 4 segments (`Service\Snippets`). A modality's wins over a shared one. |
| **Prompt** | `ai:profiles:{profile}:{id}` | the assistant (FORMATS §13) | The profile page `ai:profiles:{profile}` is a table. Where the assistant is offered: `ai.namespaces` (default `reports`); which profile: `ai.prompt_profile`. |
| **Reference** | any page under a `references.namespaces` namespace (default `radiology`), not a report | the template's `reference:` picker and the Reference panel (FORMATS §3i) | `radiology:normal:` is one of these as far as the code knows — **no code treats "normal" as a kind**. |
| **Public site** | `site:home` (`site.home_page`) and any `visibility: public` page | `HomeController`, `layout-public.php` | One exact path is special; the rest of the public site is ordinary pages (D16). |
| **Ordinary page** | anything else (`docs:`, `llm:`, `bookmarks:`, …) | the generic page view | |

A namespace's own description page is `{ns}` (the page named like it) or `{ns}:_index`; its
`visibility` is the starting level for new pages underneath (FORMATS §5). `{ns}:_template` is a card
on the namespace view, an ordinary page.

**Visibility and grants follow the path** (D36: a grant on `reports:mri` covers `reports:mri:*`). A
move changes who can see a page; the grant is not rewritten.

## 2. What this instance holds (2026-10-13, counts of files — revisions included — and names of namespaces only)

| Top level | Files | Note |
|---|---|---|
| `reports` | ~40,900 | the archive |
| `templates` | 296 | 72 template pages (`ct` 38, `mri` 33, one directly under `templates`), 53 snippet files, the rest revisions |
| `radiology` | 91 | 19 sub-namespaces; `normal` 26, `traumatism` 8 |
| `docs` | 130 | `presentations` 30 (long articles), `poetry` 64, `novellas` 36 |
| `ai` | 175 | `profiles` 162, plus `install`, `llm`, `prompts` (3 each) |
| `site` | 4 | `home` |
| strays | | `dokullm` 156 (looks like an old copy of `ai`), `mri` (empty directories), `bookmarks` 280, `llm` 77, `code` 27, `quotes` 20, `mgm` 9 |

## 3. Proposal (not done — for the owner to decide)

One root per kind, nothing else decides a kind:

| Root | Kind | Change |
|---|---|---|
| `reports:{mod}:{site}:` | patient reports | none |
| `templates:{mod}:` / `templates:snippets[:{mod}]:` | templates, snippets | none; templates move into the D45 shape page by page (doctor counts the rest) |
| `radiology:{region}:{topic}` | short references | none |
| `radiology:normal:` | normal-report texts | make it a named kind (see question 1) |
| `docs:presentations:radiology:` | long articles | to `radiology:articles:` (so references and articles sit together), or leave (question 2) |
| `ai:profiles:{profile}:` | prompts | none; remove the strays `ai:install|llm|prompts` once confirmed unused |
| `docs:` | everything that is not radiology knowledge | none |
| strays | | decide each: delete, move under `docs:`, or keep |

**Questions**
1. What is `radiology:normal:` for — model normal findings to insert (then it is a snippet-like kind, and
   `Support\PageKind::NORMAL` plus an Insert picker would give it a home), or reference reading?
2. Should the long articles be reachable from the `reference:` picker? Only a `references.namespaces`
   namespace is offered there; moving them under `radiology:` makes them offered, `docs:` does not.
3. `dokullm`, `mri` (empty), `templates:rev`, `ai:rev`: still wanted? `rev` directories are page
   history directories read as namespaces — check they are not stray pages before deleting.
4. Which of `bookmarks`, `llm`, `code`, `quotes`, `mgm` belong under `docs:`?

## 4. Moving pages — what `page:move` and the bulk Move do

`Service\PageMoves`: the page moves and leaves a redirect stub; then **one** pass over every
unsigned page rewrites: markdown links in the body (three forms), `reference:`, `priors:`,
`template:` and each `exams[].template:`. A signed page is never edited (D3): it is counted, and its
links resolve through the stub. A signed report's `template` therefore keeps the old path;
`TemplatePages::read()` follows the stub once, so its checklist and reference still show.

**Not rewritten** — the owner changes these by hand after a move of a namespace they name:
`site.home_page`, `references.namespaces`, `ai.namespaces`, `ai.prompt_profile`, `ai.fallback_profile`,
`feeds.namespaces`, `reports.modality_namespaces`; user **grants** and **pins**
(`data/users/*.json`); plain-text mentions of a path; the index columns derived from a path on
creation only (`FrontmatterGuess`).

**Mechanics that shape a restructure.** A page with child pages cannot be moved ("move those first"),
so a namespace is moved leaf by leaf, bottom-up; the bulk Move moves the pages directly in a
namespace only. A move changes `ns` in the index, so visibility follows the new path at once. Moving
reports means a stub per report (40,900 here) and no cheap undo: do not move `reports:` without an
approved layout, a backup (`backup:create`) and a dry run.
