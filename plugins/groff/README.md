# groff — a signed report as groff ms source and as a PDF

For the team that edits or typesets its reports by hand. A signed report comes out as groff `ms`
source, or as a PDF typeset by `groff -T pdf`. It is an export: the plugin writes no page and keeps
nothing (invariant 5).

## What it does

- **`GET /x/groff/ms/{pid}`** downloads the signed revision as `.ms` source (`text/plain`).
- **`GET /x/groff/pdf/{pid}`** runs groff on that source and returns the PDF (inline).
- Both are menu entries in the Export ▾ menu of a signed report (`export_action`).
- Signed-in readers only. An anonymous caller, a report the caller cannot see, and a page that is not
  a report get the same 404. An unsigned current revision gets 409. A groff that cannot be run, or
  produced nothing, is 503; one that overran the time limit is 504 (the owner also sees groff's own
  messages, which can quote the report; nobody else does).
- Each export is audited as `export` with `format` `ms` or `groff-pdf`. The file name is the page
  path with `-` for `:` (`PrintView::fileName()`, invariant 8).

## The ms file

Written in this order: a comment header, the preamble, `.TL` the exam title, `.AU` the site name,
`.AI` "Buletin imagistic · PACS accession(s) · date" (the PACS accession only, never Reporion's own
accession, D20), `.AB no … .AE` the indication, the patient block, the body, and the signature block
(byline, parafa, revision, and the `/r/{pid}/{rev}` verification URL).

The body is the canonical HTML of `Render::body()` turned into ms by `Ms\HtmlToMs`: `##` becomes
`.SH 1` (and so on down), lists become `.IP` / `.RS`, tables `tbl`, code and text-format pages
literal blocks. Images become their alt text. Special characters are escaped (`Ms\Escape`).

Limits: the letterhead is the site name only, and the signature carries no title, because the plugin
container exposes no department, address, phone or signer title.

## Settings (Admin → Plugins → groff export)

| Setting | Meaning |
|---|---|
| `groff` | Full path of the binary (default `/usr/bin/groff`). Run as an argument list, no shell, from a private 0700 temp dir, stdout to a file, killed at the time limit. groff's safer mode stays on (never `-U`). |
| `font` | Font family (default `T`, Times). It is the `-f` option and fills `{font}` in the preamble. |
| `preamble` | A `textarea` setting: the first lines of the file. Default: A4 layout `.nr PO 2c`, `.nr LL 17c`, `.nr PS 11p`, `.nr VS 14p`, `.nr PD 4p`, `.ds FAM {font}`. |
| `base_url` | `https://…` of this site, so the verification link under the signature is complete. Empty prints the path only. |
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
