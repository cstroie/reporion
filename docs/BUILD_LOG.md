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

## PUT/POST /api/v1/pages (write path — build order step 9)

**Route namespace corrected: `/api/v1/render`, not bare `/render`.** Re-reading
`docs/architecture-api.md` while placing the new pages endpoints, `POST /render` turned out to
be listed under "§3 JSON API" (`/api/v1`), not Table 1's SSR route list — I had built it as a
bare path in the earlier commit. Fixed before more routes accumulated under the wrong prefix;
`RenderRouteTest` now proves both the corrected path works and the old bare path no longer does.

**Real bug in `Storage\FlatFile`, present since its original commit, caught by a live smoke
test — not by the unit suite.** `create()`/`save()` returned a `PageRecord` built from the
caller's raw `$body`/`$frontmatter`, not from what `writeRevisionAndCurrent()` actually
persisted — so the very first live PUT-then-read cycle showed a body missing the trailing
newline `normalizeText()` adds. In the rarer duplicate-submission path (`putOnce()` returning
false), the divergence could be worse: an entirely different rev file's content. Fixed by
reading back (`return $this->read($path)`) rather than constructing from input — the caller
must never see a `PageRecord` that a subsequent `read()` would then contradict.
`FlatFileTest::test{Create,Save}ReturnsExactlyWhatReadWouldReturnAfterward` pin it down.

**Second bug found by the same live smoke test, more structural: `ffi.enable` blocks writes
under PHP's built-in dev server, not just PHP-FPM.** `Support\Fsync`'s docblock claimed "CLI
SAPI trusts FFI::cdef() regardless of ffi.enable" — true for `php script.php`/`php -r`, but
`php -S` (also launched from the CLI) does **not** inherit that trust, and every write 500s
without `-d ffi.enable=1` at startup. The PHPUnit suite never caught this because `phpunit`
itself runs as plain CLI — this class of SAPI-dependent bug is structurally invisible to the
unit suite and only shows up under a real HTTP server, which is exactly why the live curl smoke
test after each step is not optional ceremony. Fixed the docblock (`Fsync.php`), `public/router.php`'s
own doc comment, `README.md`, and added the missing `php_admin_value[ffi.enable] = 1` to
`docs/deploy-lighttpd.md`'s PHP-FPM pool config, which had never mentioned `ffi.enable` at all.

**Not built**: an automated test that actually spins up `php -S` as a subprocess and hits it —
would have caught both of the above without a manual curl pass. Worth adding; deferred as
separate test-infrastructure work rather than folded into this step.

## Live deployment on a shared dev box — three more real gaps, none catchable by phpunit or curl-to-localhost

Requested by the user: get the app working at `http://192.168.3.16/reporion/`, a shared lighttpd
instance (`server.document-root = /var/www/html`, one PHP-FPM pool) with no dedicated
vhost/hostname for reporion — a different topology than `docs/deploy-lighttpd.md` documents
(a dedicated `$HTTP["host"]`). Getting this working end to end surfaced three real,
previously-invisible bugs, in order:

1. **The whole repo, including `.git/`, was web-exposed on a LAN-reachable IP for a window
   during this session** — not just an inconvenience. `alias.url` in the first lighttpd config
   attempt silently never took effect because the running lighttpd process had been up since
   May and `systemctl reload` (SIGUSR1) does not reliably reinitialize a newly-added module
   (`mod_rewrite`). `.git/config` served as plain text confirmed the exposure (full
   history/objects downloadable); `conf/local.php` was "reachable" (200) but empty-bodied since
   `.php` still routed through PHP-FPM and executed rather than leaking source — incidental
   protection, not by design. What was actually exposed: this session's own source history —
   D33 held throughout (`data/`, `conf/local.php` gitignored from the first commit, fixtures
   anonymised), so no patient data or secrets were ever in the git history to leak. Fixed by a
   full `systemctl restart` rather than reload; confirmed via `composer.json`/`.git/config`
   404ing afterward, not just via the app responding.

2. **`Storage\FlatFile` writes need `data/` group-writable by the web server user.** The app
   500'd on `new PDO('sqlite:'...)` because `data/` (created earlier by local CLI testing as
   `costin`) had no write access for `www-data`. Fixed by matching the ownership pattern the
   repo root itself already used (`costin:www-data`, group-writable) plus a setgid bit so files
   `FlatFile` creates at runtime keep inheriting the `www-data` group.

3. **`Http\Request::fromGlobals()` used raw `REQUEST_URI`, which still carries the mount prefix
   under path-based mounting** — every route 404'd once the lighttpd/data-permission issues
   were fixed, because `Request::path` was `/reporion/reports:...` instead of `/reports:...`.
   Fixed by preferring `PATH_INFO` (confirmed empirically to be populated identically by both
   lighttpd's `index.php/$1` rewrite target and PHP's built-in dev server, for any request that
   does not match a real file) over `REQUEST_URI`.

4. **Every template used hardcoded absolute paths** (`/assets/...`, `/search`, `/login`, page
   links) — correct for the topology `docs/deploy-lighttpd.md` documents (a dedicated vhost
   mounted at `/`), wrong under this box's path-prefix mounting. User chose to make the app
   properly path-prefix-aware rather than switch to host-based mounting. Fixed with
   `Request::basePathFromGlobals()` (derived from `SCRIPT_NAME`, so it is always correct for
   wherever the app is actually mounted, no config to keep in sync) threaded through
   `Http\PageTemplateRenderer` and every controller into every template as `$basePath`.

5. **`assets/` (repo root, per `CLAUDE.md`'s own layout, a sibling of `public/`) was never
   actually web-reachable**, even in every earlier local `php -S` smoke test this session — the
   docroot is `public/` only (D23), and `public/assets/` never existed. Every prior "it works"
   check verified the HTML *referencing* the stylesheet, never that the stylesheet URL itself
   resolved. Fixed with `public/assets` as a symlink to `../assets` — no build step (matching
   the project's own constraint), physically organized per `CLAUDE.md`'s layout, actually
   servable.

   Side effect worth recording: `assets/fontawesome.css`, `assets/fonts/`, `assets/marked.js`
   and `assets/qrcode.js` were already sitting in `assets/` on this box (untracked, predating
   this session — never committed, since they aren't this session's work). The symlink makes
   them web-reachable too now, on this box only; a fresh clone will not have them. Not
   committing them here is deliberate, same as every earlier mention of these files this
   session — they are not something this work produced or vetted.

**The common thread**: none of these five are catchable by `phpunit` (runs as plain CLI,
constructs `Request`/config by hand, never touches a real web server) or by `curl` against a
`php -S` instance mounted at the server's own root (which happens to make every one of these
bugs invisible: no separate lighttpd process to have a stale reload, permissions inherited from
the CLI user, no mount prefix to get wrong, and a same-directory `assets/` reference that
"worked" only because `php -S -t public` was never actually asked to resolve `public/assets/`
either — it just never got exercised). This is the concrete case for testing against a real,
independently-configured deployment before calling a user-facing feature done, not local dev
server convenience.
