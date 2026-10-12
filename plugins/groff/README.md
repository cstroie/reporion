# groff — an ordinary page as groff ms source and as a PDF

For making nice PDF files from regular Markdown pages (notes, procedures, documents). A page comes
out as groff `ms` source, or as a PDF typeset by `groff -T pdf`. It is an export: the plugin writes
no page and keeps nothing (invariant 5). It **never handles patient data**.

## What it does

- **`GET /x/groff/ms/{pid}`** downloads the current revision as `.ms` source (`text/plain`).
- **`GET /x/groff/pdf/{pid}`** runs groff on that source and returns the PDF (inline).
- Both are menu entries in the Export ▾ menu of any page for a signed-in reader (`export_action`
  with `"for": "page"`; the default `"for": "report"` would show it on signed reports only).
- Signed-in readers only. An anonymous caller, a page the caller cannot see, a report (reports
  namespace) and a page whose frontmatter has a `patient` block all get the same 404. There is no
  409: pages are not signed, the current revision is exported. A groff that cannot be run, or
  produced nothing, is 503; one that overran the time limit is 504 (the owner also sees groff's own
  messages; nobody else does). The failure page says "groff could not typeset the page".
- Each export is audited as `export` with `format` `ms` or `groff-pdf`. The file name comes from
  `PrintView::fileName()`.

## The ms file

Written in this order: a comment header (no path), the preamble from the settings, `.TL` the page
title (frontmatter `title`, else the end of its address), `.AU` the last editor's byline, `.AI`
"rev N · date", `.AB no … .AE` the page's `summary`, then the body. There is no patient block, no
signature block, no accession and no verification link.

The body is the canonical HTML of `Render::body()` turned into ms by `Ms\HtmlToMs`. A first `#`
heading equal to the title is dropped; `#` becomes `.SH 1`, `##` `.SH 2`, `###` `.SH 3`, deeper
headings a bold paragraph. Lists become `.IP` / `.RS`, tables `tbl`, code, quotes and text-format
pages literal blocks. Images become their alt text. Special characters are escaped (`Ms\Escape`).

## Settings (Admin → Plugins → groff export)

| Setting | Meaning |
|---|---|
| `groff` | Full path of the binary (default `/usr/bin/groff`). Run as an argument list, no shell, from a private 0700 temp dir, stdout to a file, killed at the time limit. groff's safer mode stays on (never `-U`). |
| `font` | Font family (default `T`, Times). It is the `-f` option and fills `{font}` in the preamble. |
| `preamble` | A `textarea` setting: the first lines of the file. Default: A4 layout `.nr PO 2c`, `.nr LL 17c`, `.nr PS 11p`, `.nr VS 14p`, `.nr PD 4p`, `.ds FAM {font}`. |
| `timeout` | Seconds before groff is killed (5 to 120, default 30). |

The preamble is the one place the owner writes groff. Only comment lines (`.\"`) and the requests
`nr ds as ll po ps vs pl hy nh ad na fam ft lt ls blm cflags hw` are accepted; `.so`, `.sy`, `.pso`,
`.open` and the like are refused, and so is `\X`. It is checked when the setting is saved and again
when the file is built.

## Installing groff

The Debian/Ubuntu package `groff` is needed (the ms macros and the PDF device `gropdf`);
`groff-base` is not enough.

    apt-get install groff
    ls /usr/share/groff/current/tmac/s.tmac
    command -v gropdf

The font must have the language's glyphs (ă â î ș ț). Install one for groff (its `.afm`/`.t42`
files, then the family name in the `font` setting); a missing glyph prints as a blank. See
`docs/deploy-lighttpd.md`.
