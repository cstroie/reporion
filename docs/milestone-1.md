# Milestone 1 — "it replaces the folder of documents"

**Done when:** I can open a real report at its own URL, edit it, save a second revision,
see the diff between the two, and export a PDF that matches the print preview — with the
index rebuildable from disk at any point without data loss.

## Acceptance checklist

Status 2026-09-25 (phase 2 of docs/roadmap.md). Checked against test data and the automated
suite; the first item needs the real server.

- [ ] `bin/reporion doctor` passes on the real server (lighttpd, PHP 8.1, FTS5, data/ not
      web-reachable). **To run by the operator:** `sudo -u www-data bin/reporion doctor` — it now
      also checks `data/audit/` is writable.
- [x] `GET /{path}` renders a report from `data/pages/` with frontmatter shown as metadata,
      in under 50 ms server time. (≈4 ms median, 35 ms worst over 1 012 pages on test data.)
- [x] `GET /` shows the dashboard as owner and `site:home` as an anonymous visitor.
      (Every signed-in user gets the worklist dashboard.)
- [ ] A private page returns **404** to an anonymous visitor — asserted by tests for search,
      tree, sitemap, feed and API. **Partial:** asserted for page view, search, namespace
      index/drawer, API, revision permalinks, print and PDF (`tests/Visibility`, `tests/Http`);
      feeds are allowlisted, public-only and never `reports` (`tests/Http/FeedTest`); the
      sitemap was decided against (2026-09-26).
- [x] `/{path}/edit` saves a new revision: `rev/0002.md.gz` exists, `current.md` matches it,
      `meta.json.revlog` has two entries, and killing PHP mid-save leaves no partial page.
      (`tests/Storage/FlatFileTest` — journal replay cases.)
- [x] `/{path}/history` lists both revisions and shows a unified diff.
- [x] `/{path}/print` and `/export/{path}.pdf` produce the same layout, with letterhead,
      revision number and the `/r/{pid}/{rev}` verification line. (One template; verification
      as text — no QR yet.)
- [x] `rm data/index.sqlite && bin/reporion index:rebuild` reproduces every page, and the
      rebuild-vs-incremental equivalence test passes. (`SqliteTest::testRebuildIsEquivalentToIncrementalIndexing`;
      on the live box run the rebuild as `www-data`.)
- [x] `import:scan` + `import:convert` handle all three fixtures with the round-trip text
      check green.
- [x] Render conformance test (PHP vs marked.js) green on the CommonMark fixtures.

## Explicitly NOT in milestone 1

Search and the palette, AI anything, tags management, admin screens, share tokens,
patient timeline, ODT export, vectors. Each is a later milestone with its own checklist.
