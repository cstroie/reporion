# Generic page import

Turning the rest of a DokuWiki export — everything outside `reports/` — into ordinary Reporion pages.

*companion to architecture-import.md*

The report importer (`import:*`, see `docs/architecture-import.md`) exists to solve a hard problem:
turning prose into typed metadata (site, modality, region, accession, patient) without guessing.
Most of a DokuWiki export isn't that. Personal documentation, bookmarks, code snippets, teaching
material, LLM prompt templates, quotes — none of it has a patient, an exam, or a site. Running it
through the report pipeline would mean stubbing out fields that don't apply. Instead it gets a
second, much smaller pipeline: same safety properties (deterministic conversion, atomic writes
through `Storage`, reversible), generic frontmatter instead of the report schema.

## Namespace mapping

One Reporion namespace per source top-level directory. A directory not listed in
`data/page-import-map.json`'s `namespace_map` uses its own name as the namespace verbatim
(`bookmarks/` → `bookmarks:`). A directory listed in `skip_dirs` is skipped entirely — this is how
`reports/` (handled by the other pipeline), and any DokuWiki-stock or scratch directories, are
excluded, without hardcoding directory names in the command. `skip_paths` is an escape hatch for
excluding individual files or subtrees by relative path.

```json
{
  "namespace_map": { "documents": "docs" },
  "skip_dirs": ["reports", "wiki", "playground"],
  "default_visibility": "private",
  "skip_paths": []
}
```

`data/page-import-map.json` is instance-specific configuration, same category and same location as
`data/import-map.json` for the report importer: it lives under `data/`, gitignored (D33), disk
authoritative, never checked into the public repo.

## What's different from the report pipeline

- **No site/modality/region mapping, no accession generation, no patient-name extraction.** There
  is nothing report-shaped to extract.
- **Frontmatter is generic**: `title`, `visibility` (from the map's `default_visibility`, `private`
  unless configured otherwise), `status: archived`, `tags: [<namespace>]`, `imported_from`,
  `import_batch`, and `template`/`priors` only when the source body actually referenced them via
  `~~LLM_TEMPLATE:...~~`/`~~LLM_PREVIOUS...~~` macros.
- **Heading offset is 0, not 1.** The report pipeline lifts the DokuWiki H1 out as the patient name
  and shifts every heading down one level (`======` → `##`). Generic pages have no name to lift, so
  their outermost heading becomes the page's real `#` H1 — `SyntaxConverter::convert($body, 0)`.
- **Private by default**, everywhere, regardless of what visibility the source implies. Publishing
  any of this content is a separate, deliberate act (D16) — the importer never guesses public.
- **Empty-after-conversion files are skipped, not committed.** A source file that is entirely a
  navigation macro (e.g. `<nspages>`-only) becomes an empty body after macro extraction; rather
  than create a blank page, `pages:convert` records it in `skipped-empty.json` and moves on.

## Syntax conversion additions

`SyntaxConverter` is shared with the report pipeline; these constructs only appear in the generic
corpus but are available to both:

| DokuWiki | Markdown | Note |
|---|---|---|
| `<code lang>` / `<file lang>` … `</code>`/`</file>` | fenced block, `lang` as info string | bare `<code>` (no lang) still emits a bare fence, unchanged from before |
| `<poem>...</poem>` | tags stripped, lines kept verbatim | always flagged `poem-block` — soft-wrap loses the original line breaks, worth a manual look |
| `<blockquote>...<cite>X</cite>...</blockquote>` | `> ` per line + trailing `> — X` | |
| `; term` / `: definition` | `**term**` / plain text, each on its own line | loses the visual indent, keeps all the text |
| `^ th ^ th ^` / `\| td \| td \|` | markdown table (header + `\|---\|---\|` + rows) | a row whose cell count doesn't match the header, or any other malformed shape, is left verbatim for that whole block and flagged `table` — never silently mis-rendered |

## Commands

```
bin/reporion pages:scan --from <dir> --batch <id> [--dry-run]
bin/reporion pages:convert --batch <id> --map data/page-import-map.json [--limit <n>]
bin/reporion pages:commit --batch <id> [--limit <n>]
bin/reporion import:rollback --batch <id>
```

`import:rollback` is reused as-is (see `docs/architecture-import.md`) — it only ever touches
`commit-log.json` and `Storage`, nothing report-specific, so it works against either pipeline's
batches unchanged.
