# Build log

Decisions made while building autonomously, where a question would normally have been asked.
Each entry: the question, the recommended answer taken, and why. Check here before relitigating
a choice — if it turns out wrong, update the decision and this entry in the same commit.

---

## Render (build order step 4)

**No plugin hook wired into `Reporion\Service\Render`.** The build-order bullet calls this a
"markdown + macro pipeline", and `docs/architecture-storage-index.md` §9 lists a `page.render`
hook ("markdown → HTML, register macros, transform AST") in the plugin contract table. Not
implemented here: the working agreement says not to add a plugin hook without a real plugin
using it, and no plugin needing it exists yet. `Render::toHtml()` is a plain, direct
league/commonmark pipeline. Revisit when `Plugin\Hooks` exists and something real needs to
transform the AST.

**The render conformance corpus (`tests/fixtures/render/*.md`) contains no raw HTML.** D17's
dialect is "generic CommonMark plus tables... no HTML passthrough" — the PHP side escapes raw
HTML (`html_input: escape`), marked.js passes it through untouched by default, and those two
behaviors are genuinely different, not a bug in either. Since raw HTML is not part of the
dialect a report may contain, the conformance test never compares the two parsers on it — it is
excluded from every fixture, not silently mishandled. `RenderTest::testRawHtmlIsEscapedNotInterpretedAndWarns`
covers the PHP side (escaped + a warning) in isolation.

## HTTP layer / GET /{path} (build order step 5)

**`public/router.php` added for local dev only.** PHP's built-in server does not emulate
lighttpd's `url.rewrite-if-not-file` — confirmed empirically: `php -S -t public` alone 404s
every colon path before `index.php` even runs, because there's no file at that literal path.
Production needs no such script (lighttpd config does the rewrite, `docs/deploy-lighttpd.md`);
this one exists so `php -S -t public public/router.php` (what `bin/reporion serve` will run,
once `Cli\Application` exists) behaves the same way locally. Not committed as part of `Cli
Application` since that class doesn't exist yet — out of scope for this step.

**`IndexInterface` broadened to include the four read methods** (`findByPath`, `listNamespace`,
`listSitemap`, `search`) that were previously only on the concrete `Index\Sqlite`. Done so
`Controller\PageController` can depend on the interface, matching D9 ("storage and index are
drivers, not... plugin territory" — and drivers should be swappable through their interface,
not the concrete class). `tests/Storage/RecordingIndex.php` got stub implementations.

**Session/login HTTP route not built.** `Http\Session::issue()`/`isOwner()` exist and are
tested directly (signed cookie, tamper/expiry rejection) so the eventual `POST /login` route is
a thin wrapper, not new logic. `GET/POST /login` itself is out of scope for "GET /pages/{path} +
the SSR page view" and lands with a later build-order step.

**`t()` global helper + `composer.json`'s `autoload.files` added**, ahead of schedule but
needed the moment the first real template (`templates/page-view.php`) was written — D26
("no hard-coded strings in templates") isn't optional once a template exists.

## POST /render (still build order step 5/6 — "POST /render, then the print route and PDF/ODT")

**Print route and PDF/ODT export deliberately NOT built alongside `POST /render`**, even though
the architecture doc bundles them into one bullet. `templates/print/report.php` (already in the
repo from the handoff kit) binds to a `Domain\Page` API — `studyDateFormatted()`, `age()`,
`deviceLabel()`, `priorsSentence()`, `verifyUrl()`, `isSigned()`, `signedAtFormatted()`,
`keyImages()` — none of which exist. Two of those depend on subsystems not built yet at all
(signing, media). `POST /render` needed none of that (it's a thin HTTP wrapper around the
already-built `Service\Render`), so it shipped alone; print/PDF is real, separate follow-up
work, not a step that got skipped.

**`POST /render` is owner-only, 404 for anonymous** — caught in review before committing. The
architecture doc's own wording made this an easy miss: it reads like "the render endpoint" is
just infrastructure behind the public page view, but it's compute-on-demand reachable by anyone
who can reach the box, and Table 4 (the public surface) does not list it.

## site:home + public layout (build order step 7)

**Owner's `GET /` renders `site:home` too, not a real dashboard.** The spec differentiates
(owner: dashboard with recent/drafts/order queue/index health; anonymous: `site:home`), but the
dashboard needs listing/worklist queries that don't exist yet. Rather than fabricate a fake
dashboard, `Controller\HomeController` treats owner and anonymous identically for now — both get
`site:home` (or the built-in stub), through the layout their role calls for. Revisit once
`GET /pages` (the worklist query) exists.

**`templates/page-view.php` and `templates/layout-public.php` duplicate a small amount of
markup** (title, toc nav, warnings, document body) rather than sharing a partial. Two files,
~20 lines of overlap, and the difference (owner chrome: path/rev/status/visibility; public:
none of that) is exactly the content A4 says must stay identical between them — a shared
partial would be premature ahead of the real owner chrome (palette, page actions, namespace
tree) landing and changing what "the owner template" even contains. `Http\PageTemplateRenderer`
is the one place that decides which template a given (record, isOwner) pair uses, which is the
part D6/A4 actually require to be centralized.

## GET /search (build order step 8, first-result-page only)

**`Index\Sqlite::search()` hardened while building its first real caller.** Two problems only
became visible once an HTTP route handed it untrusted input: (1) the raw query term was passed
straight into `MATCH`, which is FTS5 query syntax (quotes, AND/OR/NOT, column filters) — a
caller-supplied string with a stray quote or operator threw a `PDOException` instead of
returning results. Fixed by quoting each token separately and joining with FTS5's implicit AND
(`Sqlite::ftsPhrase()`) — a first attempt that quoted the whole term as one phrase was itself
wrong (a phrase only matches tokens adjacent and in order, silently breaking ordinary
multi-word queries); per-token quoting keeps injection-safety without losing that. Structured
query syntax (`mode=fts|vector|hybrid`, filters) is later, real search-feature work, not this
fix's job. (2) `snippet()` was added for the results page
and initially used literal `<mark>`/`</mark>` markers — but `snippet()` extracts raw markdown
body text, not rendered HTML, so a report whose text happened to contain `<` or `&` would have
reached the template unescaped around the one genuinely-trusted tag: an XSS hole. Caught in
review before committing; fixed with sentinel control-character markers plus
`Sqlite::highlightSnippet()`, which escapes the whole string and only then substitutes real
`<mark>` tags — `SearchTest::testReportBodyWithHtmlLookingTextIsEscapedInTheSnippet` pins it
down.

**Palette (⌘K) and live facets not built.** `docs/architecture-api.md` calls this route "first
result page rendered so the URL is shareable; facets then live" — the "then live" part is a JS
island (A1: "the one global island... mounts on every page"), later work. This route works with
JS disabled, which is the actual requirement being met here.

## GET/POST /login, POST /logout

**Built ahead of its own build-order slot** (docs/architecture-api.md lists auth under §2
"Request lifecycle" rather than as its own numbered step) because every owner-only route built
so far — `POST /render`, `GET /{path}` on a private page — was only testable by directly
constructing a signed `Session` cookie, never through a real login flow. This closes that gap:
`Session::issue()`/`isOwner()` (already built, already tested in isolation) now have an actual
HTTP path that calls them. Classic form POST + redirect (`Response::redirect()`,
`Response::withHeader()`, both new), matching the doc's own "a form. Nothing else" and the
`POST /login { password } → 302 /` shape — never a JSON fetch.

Verified live against the real `conf/local.php` owner password: `curl` login, followed by a
second `curl` request using the returned `Set-Cookie` value, confirmed as owner-recognised.

**No rate limiting on `POST /login`, and no constant-time normalisation between "no password
hash configured" and "wrong password".** Deliberately not added: argon2id at the configured
cost already makes each guess ~100ms, this is a single-user instance behind a VPN (D12), and
D13's own threat model never mentions brute force. The unconfigured-vs-wrong-password timing
gap is a real but low-value oracle (confirms an install is set up, not a password), noted here
so it reads as deferred rather than overlooked.

**Consolidated the four `tests/Http/*Test.php` config fixtures into `HttpTestCase`.** Two
build-order steps in a row broke every hand-rolled config array in this directory
(`site.home_page`, then `auth.owner_password_hash`) because `Kernel::boot()` reads new keys
unconditionally as routes get added. Same pattern as `StorageTestCase`/`IndexTestCase` — one
shared fixture, one place for the next key to land.
