# epub: a namespace as an EPUB book

`GET /x/epub/{pid}` (Export ▾ on any page, signed-in readers). The page named like a namespace
(or the namespace's `_index`) is the front matter: its title and text. Every page directly in the
namespace is a chapter, in the namespace's `sort` order (default: by title). Sub-namespaces are not
included.

- Reports, and any page with a `patient` block, are refused as the entry page and left out as
  chapters. Anonymous, invisible and chapterless pages are all the same 404.
- Chapters are what the caller may see (the one visibility predicate).
- No images are embedded; links to other pages become plain text.
- Setting: `language` (the book's `dc:language`, default `ro`).
- Audited as `export` (`format: epub`); the plugin writes no page.
