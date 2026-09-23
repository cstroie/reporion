# Milestone 1 — "it replaces the folder of documents"

**Done when:** I can open a real report at its own URL, edit it, save a second revision,
see the diff between the two, and export a PDF that matches the print preview — with the
index rebuildable from disk at any point without data loss.

## Acceptance checklist

- [ ] `bin/reporion doctor` passes on the real server (lighttpd, PHP 8.1, FTS5, data/ not
      web-reachable).
- [ ] `GET /{path}` renders a report from `data/pages/` with frontmatter shown as metadata,
      in under 50 ms server time.
- [ ] `GET /` shows the dashboard as owner and `site:home` as an anonymous visitor.
- [ ] A private page returns **404** to an anonymous visitor — asserted by tests for search,
      tree, sitemap, feed and API.
- [ ] `/{path}/edit` saves a new revision: `rev/0002.md.gz` exists, `current.md` matches it,
      `meta.json.revlog` has two entries, and killing PHP mid-save leaves no partial page.
- [ ] `/{path}/history` lists both revisions and shows a unified diff.
- [ ] `/{path}/print` and `/export/{path}.pdf` produce the same layout, with letterhead,
      revision number and the `/r/{pid}/{rev}` verification line.
- [ ] `rm data/index.sqlite && bin/reporion index:rebuild` reproduces every page, and the
      rebuild-vs-incremental equivalence test passes.
- [ ] `import:scan` + `import:convert` handle all three fixtures with the round-trip text
      check green.
- [ ] Render conformance test (PHP vs marked.js) green on the CommonMark fixtures.

## Explicitly NOT in milestone 1

Search and the palette, AI anything, tags management, admin screens, share tokens,
patient timeline, ODT export, vectors. Each is a later milestone with its own checklist.
