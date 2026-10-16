# dokuwiki: conversion tools for pages imported from DokuWiki

Three buttons in the editor's **Tools** section (the right rail) that turn DokuWiki syntax into
Markdown. They exist for the archive that came from DokuWiki: **switch the plugin off in Admin →
Plugins when nothing is left to convert**, and the buttons (and the script) are gone.

Each works on the selection, or on the whole text when nothing is selected, never on the
frontmatter, a code fence or an inline code span. It runs in the browser, is one undo step
(Ctrl+Z) and says in the status line how many things it changed and how many it left as they
were. Nothing is sent to the server.

| Tool | Converts |
|---|---|
| **DokuWiki links to Markdown** | `[[http://x.org\|Text]]` → `[Text](http://x.org)`, `[[http://x.org]]` → `<http://x.org>`, `[[ns:page\|Text]]` → `[Text](ns:page)`, `[[a@b.ro\|Mail]]` → a `mailto:` link. Left alone and counted: a page with no namespace, `.:` / `..`, interwiki (`wp>Page`), `\\share`, image labels |
| **DokuWiki formatting to Markdown** | `====== H1 ======` … `== H5 ==` → `#` … `#####`; `//italic//`; `''mono''`; `<sup>`, `<sub>` → `^x^`, `~x~`; `<code python rules1.py>…</code>` and `<file …>` → a fenced block ```` ```python ```` (the file name goes; `<code>` and `<code ->` have no language; GeSHi's `html4strict` etc. become `html`; the fence is longer than any backtick run inside); forced `\\` breaks; the two-space `  * item` / `  - item` lists, nested by indentation. `__underline__` is left (Markdown reads it as bold). A text that already has unindented Markdown lists keeps its lists |
| **DokuWiki tables to Markdown** | `^ head ^` / `\| cell \|` rows → a pipe table; alignment from the spaces around a cell's text; a column span (`\|\|`) → the dialect's `<<` cell; a row header → bold; links and inline markup inside cells converted. A table with a row span (`:::`) is left as written, and so is one that already has a `\|---\|` row |

## How it plugs in

- `ui.editor_tool` in `plugin.json`: one entry per tool (`tool`, `label`, `help`, `icon`, `href`).
  `href` is the script, served by the plugin's one route, `GET /x/dokuwiki/js/tools.js` (signed-in
  callers; 404 otherwise).
- `assets/tools.js` builds the tools from the helpers of `assets/js/editor-format.js`
  (`proseTool`, `mask`) and registers them with `addTools()`.
- Tested in `tests/Plugin/DokuwikiToolsTest.php` (node, through `tools/run-dokuwiki-tools.js`).
