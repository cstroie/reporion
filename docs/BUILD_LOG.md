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

## First real mockup port (page view, public layout, search, login)

**Called out by the user, correctly**: every template up to this point used bare, unstyled HTML
— `tokens.css` only defines CSS custom properties (colors/spacing/shadows/fonts), never any
actual layout or component rules, and no template referenced the mockup's markup or class names
at all, despite `CLAUDE.md`/`design/README.md` both saying the mockup is the visual contract
("match its markup, class names and tokens rather than reinventing layout").

**The mockup is not static HTML+CSS — it's authored in a design/prototyping tool.** The
`.dc.html` files use `{{ }}` data bindings, `<sc-if>` conditionals and `<dc-import>` component
references, none of which run outside that tool. `design/README.md` already says so: "read them
as markup... that is the layout and class-name reference." The real, extractable content is the
`.wk-*` CSS rules inline in `Wiki.dc.html`'s `<style>` block (~300 lines) and the class
names/structure in each screen's fragment file.

**A real gap in what's extractable**: base primitives the mockup uses everywhere — `.btn`,
`.card`, `.field`, `.input`, `.tag`, `.radio` — are never defined in this repo. They come from
an external `../../styles.css` (the design tool's own shared component library), referenced by
a relative path that doesn't resolve here. There was nothing to extract for those; `assets/css/wiki.css`
authors them fresh from the same token set, and says so in its own header comment, so a future
reader doesn't assume they were copied verbatim like the `.wk-*` rules were.

**Ported with deliberate departures from the literal mockup**, each because it doesn't match
current product decisions or current build state, not by oversight:
- `WikiPage.dc.html`'s edit/history/export buttons and its full page-actions menu (rename,
  move, duplicate, sign, revert, delete) are not ported — none of those routes exist yet, and a
  button pointing at nothing is worse than no button. Add each back when its route lands.
- `WikiPage.dc.html`'s metadata table includes `acl: @radiology:rw @referrers:r public:none` —
  ACL was abolished by D6 in favor of `visibility` alone, and `design/README.md`'s own "Not in
  the product" table already flags this exact field. Not ported.
- `WikiAuth.dc.html` shows a username field, a 6-digit authenticator-code field and an SSO/guest
  button. D13 is explicit: one owner account, password only, no username, no 2FA, no SSO. The
  ported login form keeps only the password field.
- `WikiSearch.dc.html`'s facet sidebar, saved-queries panel and AI-answer-over-results box are
  not ported — facets are explicitly future JS-island work per `docs/architecture-api.md`, and
  AI ships disabled by default (D15). The result list itself (`.wk-res`/`.wk-resrow`) is ported.

**Icons dropped for this pass.** The mockup uses Phosphor Icons via an external CDN
(`unpkg.com/@phosphor-icons/web`). Untracked files already sitting in `assets/` on this box —
`fontawesome.css` + `assets/fonts/*.woff2` — look like they were pre-selected for this exact
purpose (a curated, self-hosted subset including radiology-relevant glyphs like `fa-x-ray`,
`fa-hospital`, `fa-syringe`), but nothing wires them up, their icon names don't match the
mockup's Phosphor names 1:1, and their provenance/license was never verified this session. Text
labels only for now; icon integration is a deliberately separate follow-up, not resolved here.

Verified live: all four screens (page view, public layout, login, search) fetched and inspected
against the real deployment, both stylesheets confirmed loading (200), structure/classes
compared directly against the mockup source.

## DELETE /api/v1/pages/{path} (soft delete)

**Scoped to soft delete only.** `?purge=1` (permanent deletion) is in Table 2 and D3b requires
it to write an audit entry naming the operator — there is no audit log infrastructure
(`data/audit/*.ndjson`, `docs/FORMATS.md` §6) to satisfy that yet, so it is not implemented
rather than implemented without the guarantee the decision requires.

**Trash naming needs no collision-retry loop, unlike `create()`'s path allocation** — the page
already has a pid (a ULID, globally unique) by the time it's being deleted, so `{leaf-slug}.{pid}`
is unique by construction. `create()`'s `allocatePath()` needs the atomic-`mkdir()` retry loop
specifically because the pid doesn't exist yet at that point.

**Known, documented gap, not fixed**: `FlatFile::delete()`'s journal intent has no
`recoverIntent()` counterpart — a crash between the directory `rename()` and `index->remove()`
leaves the page correctly moved to `trash/` on disk but still present in the index. Disk stays
authoritative (the page really is gone from `data/pages/`), so this is an `index:verify`/
`index:rebuild`-class problem, not data loss — but neither of those commands exist yet either
(no `Cli\Application`). `recoverIntent()` explicitly skips `delete`-op journal lines rather than
misapplying its create/save recovery logic to them, which would have been a worse silent bug
than leaving the gap visible.

Verified live: used to actually clean up the leftover test report from the mockup-port step
(sitting in `data/pages/` since this session couldn't `sudo rm` it) — confirmed 404 afterward,
confirmed it landed in `data/trash/` intact, confirmed `site:home` and an anonymous delete
attempt on a real page were both unaffected.

## Cli\Application + doctor + serve

**Scoped to two commands.** `index:verify`, `index:rebuild`, `trash:purge`, `page:new`,
`page:move` and the `import:*` family are all real, separate work — this step is specifically
the two commands that turn tonight's manually-discovered deployment bugs into an automated
check, per the earlier forward note (three of the five live-deployment bugs this session found
are exactly what `doctor` is specced to assert).

**Every `doctor` check traces back to something that actually broke tonight, not a generic
checklist** — `owner_password_hash`/`session_secret` empty (would have been today's very first
failure), `data/` writable (a *real* write-and-delete, not `is_writable()` — the actual
permission mismatch tonight was a directory being group-writable while a specific file inside
it was still `costin`-owned 644, which `is_writable()` on the directory alone would not have
caught), FFI usability, timezone, and two live HTTP checks (`.git/config` must not be
web-reachable; `/` must respond) that directly catch the `.git`-exposure-class bug and the
routing-never-reaches-the-app-class bug from earlier tonight. The docroot-exposure check went
through one more fix in review: a status-code-only check (originally probing `conf/local.php`)
would have passed for the wrong reason, since this app's own `/{path}` route matches ANY path
and 404s on nonsense just as readily as a correctly-configured docroot would — it needed a
content signature (`.git/config`'s `[core]` line) to actually distinguish "hidden" from
"no such route", which a real spun-up server serving a fake exposed `.git/config` now proves in
`DoctorCommandTest::testDetectsARealExposedGitConfigLiveOverHttp`.

**Two checks are explicitly best-effort and say so in their own output** rather than claiming a
guarantee they can't provide: the FFI check only proves FFI works from `doctor`'s own CLI
process, which trusts it regardless of `ffi.enable` — it cannot see whether the *web* SAPI has
`ffi.enable=1` set, which is exactly the gap that caused tonight's `POST /api/v1/pages` 500. The
two live HTTP checks only mean anything if `site.base_url` is the deployment's real, reachable
URL, not the `example.ro` placeholder `conf/local.php.example` ships with — this local box's own
`conf/local.php` still has the placeholder, so `doctor` currently WARNs (not FAILs) on both,
correctly, until `base_url` gets set to the real address.

**`serve` passes `-d ffi.enable=1` unconditionally** — the exact flag this session spent real
time discovering was necessary for the built-in dev server, now baked into the one command that
launches it, so nobody has to rediscover it.

Verified live: `bin/reporion doctor` run against both the real `conf/local.php` (WARNs on the
two HTTP checks, correctly, since `base_url` is still the placeholder) and, separately, against
a config with `base_url` pointed at the real box — where both HTTP checks PASS, directly
confirming the docroot-exposure and front-controller-reachability bugs from earlier tonight are
now gone and would be caught automatically if they came back. `bin/reporion serve` verified to
actually start a working server on the requested port.

## index:verify + index:rebuild

`Index\Sqlite::verify()` and `rebuild()` already existed and were tested, but nothing invoked
them — `Storage\FlatFile::delete()`'s own docblock names them as the intended fix for its
documented drift window. Both take a caller-supplied `iterable`; nothing walked `data/pages/` to
produce one. Added `FlatFile::allPaths()` (a generator over every directory under `data/pages/`
that has both `current.md` and `meta.json` — which naturally excludes `rev/` subdirectories,
redirect stubs, and anything half-written, with no special-casing needed) and
`FlatFile::snapshotOf(string $path)` (rebuilds the exact `PageSnapshot` `create()`/`save()` would
have indexed, straight from disk, reusing the same private `snapshot()` mapper). `index:verify`
and `index:rebuild` are thin `Cli\CommandInterface` wrappers over these plus `Index\Sqlite`.

**`Cli\Application` command registration is now lazy** (factories, not instances). `doctor` and
`serve` don't need a database connection; forcing one open just to dispatch or print usage would
have meant every `bin/reporion <anything>` invocation touches `data/index.sqlite`, which is
unnecessary and, on the live box, risky (see below). Verified this is actually lazy, not just
refactored to look lazy: `ApplicationTest`'s existing `doctor`/`serve` dispatch tests pass with a
config that has no `paths.index` key at all — if construction weren't deferred, those would fail
with a missing-array-key error instead.

**Real bug caught in review** (`advisor`, before commit): `FlatFile::snapshot()`'s private
mapper stamped `updated: self::now()` unconditionally. On the `create()`/`save()` path this is
correct (that call *is* the update). On `index:rebuild`'s new path through `snapshotOf()`, it
meant every page in the index would get today's date on the `updated` column regardless of when
it was actually last edited — silently wrong for any real rebuild (4000 imported reports all
showing "updated today"), and it would have made the `index:rebuild`-vs-incremental
byte-identical test CLAUDE.md's Testing section calls for structurally impossible to write, since
one column would always differ by definition. Fixed to read `$lastEntry['ts']` off the revlog
(the same array `snapshot()` already pulls `by`/`note`/`kind` from) instead of calling `now()`
again. `FlatFileTest::testSnapshotOfMatchesWhatCreateOriginallyIndexed` now asserts `updated`
matches too, specifically to guard against this regressing.

Both commands stream `FlatFile::allPaths()` through a generator rather than materializing the
full snapshot list in memory first (`rebuild()`'s `iterable` parameter and `allPaths()`'s
generator both already supported this; the first draft didn't take advantage of it) — matters at
the 4000-report archive scale the importer will eventually load.

**Not run against the live box from this machine.** `Index\Sqlite`'s constructor runs
`PRAGMA journal_mode = WAL` and `applyMigrations()` on connect — both writes. Running
`bin/reporion index:verify` as `costin` against the live, `www-data`-owned `data/index.sqlite`
would reproduce the exact mixed-ownership WAL breakage from earlier tonight that required a
`sudo rm -rf` to clear. If verification against the real archive is wanted, it has to run as
`sudo -u www-data bin/reporion index:verify` on the box itself, not from here.

`--vectors` (CLAUDE.md's `index:rebuild [--vectors]`) is out of scope: embeddings need a loadable
sqlite vector extension (D15/D28) that isn't wired up yet.

## Architecture pivot: multi-user, not single-owner

The user corrected a misunderstanding: "personal use, maybe single user" meant a small personal
*project*, not literally one account. Reporion is for a small team — multiple admin-created
accounts, per-namespace ACL grants on top of the existing `visibility` axis, no self-service
registration, no 2FA. This directly reopens the seam D6's own text named: "If a second user ever
appears, this is the seam to reopen: add the principal table then, not now." It appeared.

Forks the user resolved directly (`AskUserQuestion`, not guessed):

- **Account storage**: `data/users/{username}.json` — disk-authoritative like pages, matching
  invariant 1. Not `index.sqlite`: a feature whose only copy is in the disposable index is exactly
  the bug invariant 1 exists to prevent, and accounts/grants are unambiguously that kind of
  feature.
- **Registration**: admin-created only. No public signup route on a public GPL repo's default
  install — avoids needing email verification or an approval queue for a feature nobody asked for.
- **ACL granularity**: per-namespace grants, not per-page and not role-only. A grant is
  `{namespace, role}` and matches by prefix (`reports:mri` covers `reports:mri:*`), reusing the
  colon-hierarchy pages already have instead of inventing a separate inheritance model.
- **Signing authority**: whoever holds `editor` (or `owner`) on a page's namespace signs it as
  themselves — generalises D14's "no review step" past "the owner is the only possible signer"
  rather than replacing the no-review policy itself.

Recorded as D35 (accounts/roles), D36 (storage/caching), D37 (signing) in `docs/DECISIONS.md` and
CLAUDE.md's decision table; D6, D13, D14 kept struck-through for history rather than deleted.
Docs updated in this commit, following CLAUDE.md's own working agreement ("when a doc and the
code disagree, fix the doc in the same commit") — except here the *code* hasn't moved yet, only
the docs have, deliberately: this is a foundational pivot touching auth, storage, the index
schema and every write route, not a single build-order step, so it gets planned before any code
lands. The actual account store, `Session`/auth rewrite, `visibilityClause($principal)`,
`user_grants` cache table + migration, admin user-management screen, and CLI account bootstrap
are separate follow-up commits.

**Three things caught in review (`advisor`) that the docs now say explicitly, so the eventual
code can't quietly contradict them:**

1. **`index:rebuild` (committed just before this pivot) only walks `data/pages/`.** The moment a
   `user_grants` index table exists, that command's current form (`DELETE FROM pages; DELETE FROM
   fts;` then repopulate from `FlatFile::allPaths()`) would need to also delete and repopulate
   `user_grants` from `data/users/*.json` — otherwise a rebuild silently deletes every non-owner's
   access with exit code 0, directly contradicting D36's "must not lose a single account or
   grant." D36's wording above states this as a requirement on the *future* `user_grants`-aware
   rebuild, not a claim about the command as it stands today (it doesn't touch users at all yet,
   so there's nothing to lose — the risk starts the day `user_grants` is added without updating
   this command in the same commit).
2. **`Cli\DoctorCommand::checkOwnerPasswordHash()` still asserts `conf/local.php`'s
   `auth.owner_password_hash`.** That field is superseded by D35 but the check hasn't been touched
   in this commit (docs-only, per the plan above). When the account store migrates, this check
   needs to become "at least one `owner`-role account exists in `data/users/`", and the live box's
   existing single owner credential needs an explicit one-time migration into that format — not
   automatic, since it's exactly the kind of on-disk-layout change CLAUDE.md's working agreement
   says to ask about first.
3. **Who can publish.** D16 requires flipping a page to `public` be "a deliberate, noisy act,"
   audited. With namespace grants, `editor` includes write access to `visibility` via
   `PATCH /pages/{path}/meta` — so any editor on a namespace can publish within it, not just
   `owner`. Settled explicitly in D35's text rather than left implicit: that's intended (editor is
   a real write grant, not a lesser one), and D16's audit/acknowledgement requirement is what keeps
   it safe, applying identically regardless of which role does the flipping.
