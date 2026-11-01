# Plan: library search and ask, refactored (passage index, streaming, its own place)

Status: approved 2026-11-02; built on branch `library-search`.

## Context

Ask the notebook (`Service\Library\NotebookAsk`, `PassageSearch`) was built for a notebook
citing a few sources. The owner's points (2026-11-02):

- **A notebook may cite many references.** `NotebookAsk::sources()` reads every cited source
  whole (`storage->read`, attic included), and `PassageSearch::choose()` cuts and scores every
  passage of all of them in PHP. Cost grows with the size of the notebook.
- **The AI may be slow.** `POST /api/v1/notebook/ask` returns only when everything is done: a
  `notebook-terms` call, then the answer. Nothing is shown meanwhile, though `Assistant`
  already streams pieces (`attempt()` → `$emit`) and Studio/analyses already stream SSE.
- **The AI may have a small token budget.** Passages are packed against `library.context_tokens`
  less a fixed 3000; the prompt page, the question and the answer's room are not counted.
- **An article must be findable by text.** The `/library` filter is client-side, on title and
  authors only (`templates/library.php` `data-lib-filter`).
- **Library search and ask must not mix with reports.** `/search` and the palette list library
  sources beside reports; a library-wide question has no home except `/ask`, which is the reports'
  and HippoBridge's.

Owner's answers: an **Ask the library** on `/library`, separate from `/ask`; library sources
**left out of `/search`** and the palette; a **passage FTS table** (index schema change approved);
the budget stays **`library.context_tokens`**.

## Design

### 1. `passage_fts`: library passages in the disposable index
- Migration `migrations/index/008_passage_fts.sql`: FTS5 `passage_fts(text, pid UNINDEXED,
  n UNINDEXED, page UNINDEXED, tokenize = 'unicode61 remove_diacritics 2')`, as the pages' FTS (D5).
- Filled in `Index\Sqlite::index()` (the one upsert path, so `index:rebuild` fills it too) for a page
  that is a library source (`Support\LibrarySource::isSource($fm)`): its passages from
  `SourcePassages::cut(SourceChunks::withoutReferences($body))`, the same cut `index:vectors` uses,
  so `(pid, n)` names the same passage in `passage_fts` and `passage_vectors`. Delete and re-insert
  per page; removed with the page (delete, trash, move keeps pid).
- New `Sqlite::passageSearch(string $match, ?array $pids, ?User $principal, int $limit)`: joins `pages`
  under `Query::visibilityClause()` (invariant 6), `bm25` order, returns `pid, n, page, text,
  snippet()`. The match string comes from the existing query builder (`Search\Query`: D28 synonyms,
  prefix on the last token), not hand-built.
- `Sqlite::passageLengths(array $pids)`: `SUM(length(text))` per pid, for the fits-whole check
  without reading the sources.

### 2. `PassageSearch` on the index, sources read only when chosen
- Words side: `passageSearch()` over the candidate pids (a notebook's keys) or the whole library
  (`null`), replacing the PHP scoring loop. The notebook's own text (one page, not a source) is still
  cut and scored in PHP as now.
- Meaning side unchanged (`passageCandidates()` with the same pid set, or all library pids).
- RRF fusion, `PER_SOURCE`, `SEMANTIC_PLACES` unchanged; plus a cap on passages sent
  (`MAX_PASSAGES = 24`) so a big notebook's answer is not diluted.
- `NotebookAsk::sources()` resolves keys to pids through the index only (`findByPath` under the
  caller); full pages are read only on the *whole* path, decided by `passageLengths()`.

### 3. The budget, counted honestly
`library.context_tokens` stays the one setting. Passages are packed against it less: the prompt
page's own length, the question and history, and the answer's room (the action's `max_tokens`, else
a fixed 1500 tokens); 3 characters a token as `SourceChunks::budget()`. One helper
(`SourceChunks::budgetFor(Action, string ...$also)`) used by NotebookAsk, Ask the library and
Studio's check.

### 4. Streaming the answer
`POST /api/v1/notebook/ask` and the new library ask take `stream: true` (as analyze/studio do,
`LibraryAiController`): SSE events `progress` (`terms` / `searching` / `answering`), `sources`
(the passages chosen, shown at once with their links), `delta` (answer pieces:
`Assistant::runNotebook()` gets an `$emit` closure like `run()`), `done` (the final answer after
`NotebookAsk::onlySent()`, which replaces the streamed text, so an unsent key never stays).
Without `stream` the JSON answer is as today. `notebook-terms` keeps its lite model; its page's
`timeout:` applies, and a failure is the existing notice, never the end.

### 5. Ask the library (`/library`, never `/ask`)
- `POST /api/v1/library/ask {question, history?, stream?}` → `Service\Library\LibraryAsk`, sharing
  the engine with NotebookAsk (a common `SourceAsk` doing terms → search → pack → answer; the two
  differ in candidates and prompt). Candidates: every library source the caller may read.
- New reserved prompt `ask-library` (in `Actions::LIBRARY`, shipped in `resources/prompts/research`,
  English, `{question}`, `{sources}`, `{history}`, `{language}` = the library's `library.language`),
  same `[@key, p. N]` citations; `notebook-terms` reused for the words. Library features follow the
  library namespace's profile and server (built 2026-11-01).
- Who: an editor of the library namespace, as the analyses (AI cost); others 404.
- No `ask.sources` hook (HippoBridge), no reports, no `Index\Sqlite::recallCandidates()`.
- UI: a question box on `/library` (island in `assets/js/library.js` or its own small island),
  history of the last 5 turns kept in the browser, as `/ask`.

### 6. Library text search on `/library`
- `GET /library?q=` server-side (keeps working without JS) and `GET /api/v1/library/search?q=`
  (the island's live filter): sources whose passages match (`passageSearch()`, best passage's
  snippet and page `p. N`) fused with page-level hits on the record (title, authors, tags from the
  pages' FTS limited to the library namespace and to sources), ranked by RRF; under the caller's
  visibility. The client-side title filter stays for instant narrowing.

### 7. Library out of `/search` and the palette
`SearchController` (page and `suggest`) passes an excluded namespace (`library.namespace`) to
`Index\Sqlite::search()` unless the request's `ns` is the library or under it (the editor's `@`
citation picker asks `ns=library` and keeps working). Ask the archive already takes only
signed/archived pages; a test asserts a library source never reaches it.

## Files

- `migrations/index/008_passage_fts.sql`; `src/Index/Sqlite.php` (index(), passageSearch(),
  passageLengths(), search() exclusion), `src/Index/IndexInterface.php` if search()'s signature grows.
- `src/Service/Library/PassageSearch.php`, `NotebookAsk.php`, new `SourceAsk.php`, `LibraryAsk.php`,
  new `LibrarySearch.php`; `src/Support/SourceChunks.php` (budgetFor()).
- `src/Service/Ai/Assistant.php` (`runNotebook()` with `$emit`, `runLibrary()`),
  `Context.php` (`library()` prompt builder, reusing `notebook()`'s de-identification),
  `Actions.php` (`ask-library` in LIBRARY), `DefaultPrompts.php` + `resources/prompts/research/ask-library.txt`.
- `src/Controller/LibraryAiController.php` (stream for ask; `askLibrary()`),
  `LibraryController.php` (`q`, search API), `SearchController.php`; `src/Kernel.php` routes.
- `templates/library.php`, `assets/js/library.js` (search box, ask panel, SSE reader shared with
  the notebook panel), `lang/en.php`.
- Docs: `docs/architecture-api.md` (two new endpoints, `stream` on notebook/ask),
  `docs/architecture-storage-index.md` (passage_fts), `docs/library.md`, D15 amendment
  (`ask-library`), a new D59 row in `CLAUDE.md` + `docs/DECISIONS.md` (library search and ask
  apart from reports; library left out of /search).

## Tests (touched modules only)

- **Index:** rebuild vs incremental byte-identical rows extended to `passage_fts`
  (the existing rebuild test); a source edited, moved, trashed: its passages follow.
- **Visibility:** `GET /api/v1/library/search`, `GET /library?q=`, `POST /api/v1/library/ask` ×
  {owner, editor with/without the library grant, viewer, anonymous} × private source: exactly what
  is reachable, anonymous 404 (CLAUDE.md: a case before any new listing endpoint).
- `tests/Ai/NotebookAskTest.php`: many sources → index-chosen passages, sources not read whole;
  budget counts prompt and answer room; `stream: true` emits `sources`, `delta`, `done`, and `done`
  drops an unsent key.
- New `tests/Ai/LibraryAskTest.php` (fake server): answers from the whole library, never a report,
  no `ask.sources` call; editor only.
- `tests/Http/SearchTest` (or where /search is tested): a library source is not listed; `ns=library`
  still lists it (citation picker).
- `DefaultPromptsTest`: `ask-library` shipped for research.

## Verification

- The tests above, one module at a time.
- Throwaway instance (`tools/browser/start.sh`, fake AI): `/library?q=` finds an article by a phrase
  of its body with a snippet; Ask the library streams sources then the answer; a notebook citing
  ~30 sources answers within budget; `/search` no longer lists library pages.
- Full suite before merge (owner runs it): index schema and Storage-adjacent indexing changed.
- After deploy the owner runs `sudo -u www-data bin/reporion index:rebuild` once to fill `passage_fts`.

## Build order (small commits)

1. passage_fts + index tests. 2. PassageSearch/NotebookAsk on it + budget. 3. Streaming for
Ask the notebook. 4. `/library` search. 5. Ask the library + prompt. 6. Library out of /search.
7. Docs.
