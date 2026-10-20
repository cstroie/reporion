# Namespaces: what each kind of page is, and where it lives

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
| **Reference** | any page under a `references.namespaces` namespace (default `radiology`), not a report | the template's `reference:` picker and the Reference panel (FORMATS §3i) | Short references (`radiology:{region}:{topic}`) and long articles (`radiology:articles:`). `radiology:normal:` are **normal reports**: x-ray, ultrasound: kept to be copied into the HIS; the app never uses them as templates, so they are ordinary pages there (best as `format: text`, D40, copied as typed) and no code treats "normal" as a kind. |
| **Public site** | `site:home` (`site.home_page`) and any `visibility: public` page | `HomeController`, `layout-public.php` | One exact path is special; the rest of the public site is ordinary pages (D16). |
| **Personal** | `users:{username}:…` | the account itself and instance owners; others by an explicit grant on `users:{username}` (D46) | An implicit editor grant, derived from the username and never stored. Ordinary pages in every other respect: private by default, never reports, never signable. |
| **Ordinary page** | anything else (`docs:`, `llm:`, `bookmarks:`, …) | the generic page view | |

A namespace's own description page is `{ns}` (the page named like it) or `{ns}:_index`; its
`visibility` is the starting level for new pages underneath (FORMATS §5). `{ns}:_template` is a card
on the namespace view, an ordinary page.

**Visibility and grants follow the path** (D36: a grant on `reports:mri` covers `reports:mri:*`). A
move changes who can see a page; the grant is not rewritten.

## 2. What this instance holds (2026-10-13, counts of files: revisions included, and names of namespaces only)

| Top level | Files | Note |
|---|---|---|
| `reports` | ~40,900 | the archive |
| `templates` | 296 | 72 template pages (`ct` 38, `mri` 33, one directly under `templates`), 53 snippet files, the rest revisions |
| `radiology` | 91 | 19 sub-namespaces; `normal` 26, `traumatism` 8 |
| `docs` | 130 | `presentations` 30 (long articles), `poetry` 64, `novellas` 36 |
| `ai` | 175 | `profiles` 162, plus `install`, `llm`, `prompts` (3 each) |
| `site` | 4 | `home` |
| strays | | `dokullm` 156 (looks like an old copy of `ai`), `mri` (empty directories), `bookmarks` 280, `llm` 77, `code` 27, `quotes` 20, `mgm` 9 |

## 3. Layout: decided 2026-10-13 (the moves are not run yet)

One root per kind, nothing else decides a kind:

| Root | Kind | Change |
|---|---|---|
| `reports:{mod}:{site}:` | patient reports | none |
| `templates:{mod}:` / `templates:snippets[:{mod}]:` | templates, snippets | none; templates move into the D45 shape page by page (`doctor` counts the rest) |
| `radiology:{region}:{topic}` | short references | none |
| `radiology:normal:` | normal reports for the HIS (copy-paste) | none; ordinary pages, not templates |
| `radiology:articles:{topic}` | long articles | **from** `docs:presentations:radiology:{topic}`, so the `reference:` picker offers them (`radiology:ankle`, `:shoulder`, `:spine` exist already as short-reference namespaces, hence the `articles` level) |
| `ai:profiles:{profile}:` | prompts | none |
| `docs:` | everything that is not radiology knowledge | `mgm:instruire`, `mgm:protocoale` come in as `docs:mgm:…` |
| `bookmarks`, `llm`, `code`, `quotes` | ordinary pages | stay where they are |
| `dokullm`, `mri` (empty) | strays | removed, not wanted |

`templates/rev` and `ai/rev` are **not** strays: they are the revision history of the `templates` and
`ai` namespace pages, and stay.

**To run** (by the owner, on the live instance:`sudo -u www-data` from the checkout; each prints
what it does and leaves a redirect (a row, D54); links, `reference:`, `priors:` and template keys in unsigned
pages are fixed in the same pass):

```
for t in ankle shoulder spine anonymizing; do
  sudo -u www-data bin/reporion page:move docs:presentations:radiology:$t radiology:articles:$t
done
sudo -u www-data bin/reporion page:move mgm:instruire docs:mgm:instruire
sudo -u www-data bin/reporion page:move mgm:protocoale docs:mgm:protocoale
```

All six are leaf pages. Since D54 (2026-10-20) a page with pages under it moves alone, so none would be refused anyway. Then
delete the 51 `dokullm` pages from the namespace view (they go to Trash; `trash:purge` removes them
for good) and `rmdir` the two empty directories `data/pages/mri/scuc` and `data/pages/mri`. Check
`site.home_page`, `references.namespaces` and any user's grants/pins that name `docs:presentations` or
`mgm` afterwards (§4).

## 4. Moving pages: what `page:move` and the bulk Move do

`Service\PageMoves`: the page moves and leaves a redirect (a `redirect` row, old path → pid); then **one** pass over every
unsigned page rewrites: markdown links in the body (three forms), `reference:`, `priors:`,
`template:` and each `exams[].template:`. A signed page is never edited (D3): it is counted, and its
links resolve through the redirect. A signed report's `template` therefore keeps the old path;
`TemplatePages::read()` follows the redirect once, so its checklist and reference still show.

**Not rewritten**: the owner changes these by hand after a move of a namespace they name:
`site.home_page`, `references.namespaces`, `ai.namespaces`, `ai.prompt_profile`, `ai.fallback_profile`,
`feeds.namespaces`, `reports.modality_namespaces`; user **grants** and **pins**
(`data/users/*.json`); plain-text mentions of a path; the index columns derived from a path on
creation only (`FrontmatterGuess`).

**Mechanics that shape a restructure.** A page with pages under it moves alone (since D54, 2026-10-20: a page is a file, its namespace a directory; was refused with "move those first"), so a namespace is still moved page by page; the bulk Move moves the pages directly in a
namespace only. A move changes `ns` in the index, so visibility follows the new path at once. Moving
reports means a redirect row per report (40,900 here) and no cheap undo: do not move `reports:` without an
approved layout, a backup (`backup:create`) and a dry run.
