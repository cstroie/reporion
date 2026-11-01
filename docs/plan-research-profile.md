# Plan: a `research` prompt profile for the library and notebooks

Status: planned and **built 2026-11-01** (branch `research-profile`). Departures from the plan:
`specialUnder()` walks the sub-namespaces' own profiles before the fallback's page; Admin → AI
offers research only to a profile a routing row gives the library (not the fallback that serves it
by default); `AiConfig::routedProfileFor()` is profileFor() without the fallback.

## Context

The library features (source analyses, Ask the notebook, Studio, flashcards, the audio
script: the 20 ids of `Actions::LIBRARY`) are neither report work nor ordinary-page work, but
their example prompts ship mixed into the **generic** set, and each feature resolves its
profile from the path of the page it runs on (`Actions::special($path, $id)`). A source in
`library:` is easy to route; a **notebook is any page anywhere** (`sources:` or `[@key]`), so
in `teaching:` or `users:x:` its Ask/Studio use whatever profile serves that namespace, and
quietly switch off when that profile lacks the prompts.

Owner's decisions (2026-11-01):
1. A third shipped set, **`research`**, collects the library prompts.
2. Its rail includes `ask` and `grammar`.
3. Only the **library features** follow the `library` namespace's profile and server,
   wherever they run. A notebook's ordinary assistant (rail, summary, tags, Save note) keeps
   its own namespace's profile, unless its namespace is mapped or the page says
   **`prompts: research`** in its frontmatter.
4. **No duplicates:** a prompt page missing from a profile is looked up in the
   **everywhere-else profile** (`ai.fallback_profile`); none set: it is missing, as today.

## Design

### A. Library features resolve at the library namespace
`Service\Ai\Actions::special($path, $id)`: when `$id ∈ self::LIBRARY`, resolve the profile and
the server as if the page were at the library namespace (`Kernel::libraryNamespace($config)`,
default `library`): `$this->config->profileFor($libraryNs)` and `serverFor($libraryNs)`.
- `Actions` gets the library namespace as a constructor argument (default `'library'`, so
  tests and old call sites keep working); `Kernel` passes it at its four `new AiActions(...)`
  sites (`Kernel.php` ~366, 379, 392, 538).
- Every caller already goes through `special()` with a LIBRARY id (`SourceAnalysis`,
  `Studio`, `NotebookAsk`, `PageTemplateRenderer` 167/183/186), so nothing else changes.
- The routing table row `library → research, server N` now decides the prompts **and** the
  provider for all library features. No new setting.

### B. Page frontmatter `prompts: {profile}`
A page may name its profile: `prompts: research`. In `Actions::forPage()` and `special()`
(non-LIBRARY ids), the profile is the page's `prompts` when it is a valid profile name
(`/^[a-z0-9][a-z0-9_-]{0,31}$/`) **and** that profile has pages (`profiles()`), else
`profileFor($path)` as today.
- Read through the existing cached `Actions::page($path)` (`Actions.php:393`); one indexed
  lookup per page per request, as for prompt pages.
- The **server stays by namespace** (`serverFor($path)`): the key picks prompts, never a
  provider, so it cannot move a page's text to another server's egress.
- Caches: `byProfile` is already keyed `profile|server`.
- Library pages and reports: allowed (it only picks owner-made prompt pages); the D47 gate
  for reports is unchanged (it sits before `Actions`).
- `docs/FORMATS.md` §3 (frontmatter keys) gets `prompts`.

### C. Fallback for missing prompt pages
One helper in `Actions`, `promptPage(string $profile, string $rel): ?string` returning the
first path that has a body among `ai:profiles:{profile}:{rel}` and, when
`ai.fallback_profile` is set and differs, `ai:profiles:{fallback}:{rel}`. Used for:
- reserved prompts (`special()`, `specialPath()`), the `model:`/`timeout:` settings read
  from the page found;
- rail rows: a row of the profile's table whose own prompt page is missing (`build()`,
  `$this->body($ns . ':' . $id)`), and its `system:{id}` appendage;
- **not** the table page itself (the rail is the profile's own), and not the system prompt,
  which keeps its current rule (own, else `ai:profiles:default:system`).
`overview()` (Admin → AI *Prompts* panel) marks a prompt served from the fallback
("from {fallback}") instead of "missing", so the owner sees why it works.

### D. The shipped `research` set
`Service\Ai\DefaultPrompts`:
- `RESEARCH = 'research'`, added to `TARGETS`; `resources/prompts/research/` holds the 20
  library prompt files (moved from `generic/`, `git mv`) plus its own `table.md` (rail: `ask`,
  `grammar`; it references them, their pages come from the fallback per C).
- The 20 LIBRARY entries of `CATALOGUE` change `targets` from `GENERIC_ONLY` to
  `RESEARCH_ONLY`; `table` gets all three targets (each has its own file). `summary`, `tags`,
  `commit`, `ask`, `grammar` are **not** shipped for research (fallback, decision 4).
- Pages already made in existing profiles stay (never removed, as with every earlier change
  to the examples).
- Admin → AI's default *Examples for* (`AdminAiController::show()` line ~91): radiology for
  the profile serving `reports`, **research for the one serving the library namespace**,
  else generic. `ai:prompts-install --target=research` accepted (`AiPromptsInstallCommand`
  reads `TARGETS`; check its help text).
- `DefaultPrompts::CORE` unchanged (installer's first tick is about the rail).

## Files

- `src/Service/Ai/Actions.php`: A, B, C (constructor arg, `profileOf($path, $id)`,
  `promptPage()`, `overview()` flag).
- `src/Kernel.php`: pass the library namespace to `AiActions`.
- `src/Service/Ai/DefaultPrompts.php`, `resources/prompts/research/*` (moved), new
  `resources/prompts/research/table.md`.
- `src/Controller/AdminAiController.php`, `templates/admin-ai.php` (target default; "from
  fallback" marker), `lang/en.php` (target label, marker).
- `src/Cli/AiPromptsInstallCommand.php` help text.
- Docs: `docs/FORMATS.md` (frontmatter `prompts`, §13 profile resolution, the fallback),
  `docs/library.md` (which profile its features use), D15 row in `CLAUDE.md` and
  `docs/DECISIONS.md` (amendment 2026-11-02), `ai:prompts-install` line in the CLAUDE.md
  command list (three targets).

## Tests (only the touched modules)

- `tests/Service/Ai/ActionsTest.php`:
  - a notebook at `teaching:x` gets `ask-notebook` from the profile and server routed to
    `library`, while its rail comes from `teaching`'s profile;
  - `prompts: research` on a page switches its rail and reserved prompts, not its server;
    an unknown or invalid profile name is ignored;
  - a reserved prompt and a rail row missing from the profile come from
    `ai.fallback_profile`; with no fallback set they stay missing; the table never falls back.
- `tests/Service/Ai/DefaultPromptsTest.php` (or wherever the catalogue is tested): every
  `research` entry has its file; LIBRARY ids are research-only; `install(..., 'research')`.
- `tests/Http/AdminAiTest.php`: the profile serving `library` defaults to research;
  the fallback marker.
- `tests/Ai/{NotebookAskTest,LibraryAnalysisTest,StudioTest}.php`: they set
  `fallback_profile: wiki` and put the prompts under `wiki`; they must still pass (no
  `library` row: `profileFor('library')` is the fallback, i.e. today's behaviour).

## Verification

- Run the tests above (`vendor/bin/phpunit tests/Service/Ai/ActionsTest.php`, the
  DefaultPrompts test, `tests/Http/AdminAiTest.php`, `tests/Ai` as one folder).
- Throwaway instance (`tools/browser/start.sh`, fake AI server): install `research` into a
  profile, add the routing row `library → research, server 2`, open a notebook outside
  `library:` and check that Studio/Ask appear and that the fake server for slot 2 receives the call,
  while the rail still names the notebook namespace's server.
- Full suite before merge (owner runs it): `Actions` sits under every assistant feature.

## Upgrade note for the owner

Library features change nothing until a `library` row exists: with none, they use the
everywhere-else profile, where most notebooks get them today (a notebook in a namespace mapped
to another profile now asks the everywhere-else one too). **The fallback for missing prompt pages
(C) applies at once, to every profile:** a profile lacking `commit`, `summary` or `tags` (a
reports profile, say) is served the everywhere-else profile's from the first request, so Save
writes revision notes and Summarize/Suggest tags appear there. Admin → AI shows these as *from the
profile …*. An empty page is as absent as none, so the way to keep one off is to remove the
everywhere-else profile's page or to set no fallback. To switch:
`bin/reporion ai:prompts-install --profile=research --target=research`, then Admin → AI
routing table: `library → research`, server of choice.
