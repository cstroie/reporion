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

## Account store (D35/D36) — data/users/{username}.json

First code for the multi-user pivot, scoped to exactly the store: `Reporion\Auth\User` (immutable
record: `username`, `passwordHash`, `isOwner`, `grants`, `active`, `createdAt`, `updatedAt`, plus
pure predicates `roleOn()`/`canRead()`/`canWrite()` — a namespace's grant is a prefix match,
mirroring the colon-hierarchy pages already have), `Grant`/`GrantRole` (namespace + editor|viewer),
`UserStoreInterface` + `FlatFileUserStore` (`data/users/{username}.json`, one file per account,
`AtomicWriter::put()` — the same temp+fsync+rename primitive `Storage\FlatFile` uses for
`current.md` — since an account has no revision history requirement, no journal is needed: one
file, one write, nothing a crash could leave half-done).

Session/auth rewrite, `visibilityClause($principal)`, a `user_grants` index cache table (and
teaching `index:rebuild` to walk `data/users/*.json` too — see the previous entry), the admin
user-management screen, and `bin/reporion user:create` are still separate follow-up work, not
touched here.

**Two real bugs caught in review (`advisor`), both fixed before commit:**

1. `User::$grants` was `array` with only a `@param list<Grant>` docblock — phpstan accepts the
   annotation without checking callers. A non-`Grant` element (a typo, a bad cast, a future caller
   passing raw arrays) would fatal inside `roleOn()`, which is the one predicate that decides who
   can read a private report — an authorization-path crash, not a cosmetic one. Fixed with a
   constructor loop that throws `InvalidArgumentException` on any non-`Grant` element, so the
   failure is at construction, not at the worst possible call site.
2. `find()`/`all()` cast `file_get_contents()`'s result straight to `string`. A failed *read*
   (permissions — exactly what broke this project twice already on the live box, `costin` vs
   `www-data` ownership) casts `false` to `''`, and `decode('')` reports "corrupt user record" for
   a file that's perfectly fine — sending whoever investigates looking for JSON corruption that
   isn't there. Split into `readFile()` (throws "User record unreadable" on a real read failure)
   and `decode()` (throws "Corrupt user record: {filename}" only for genuinely malformed JSON).

**Known gap, not a blocker for a store-only commit:** `active` is stored and round-trips correctly,
but nothing reads it yet, and `UserStoreInterface` has no `disable()` — `docs/architecture-api.md`'s
`DELETE /api/v1/users/{username}` ("deactivate — accounts are never hard-deleted") isn't backed by
anything yet. Enforcing `active` belongs with the Session/auth rewrite (login must refuse a
disabled account), not the store — noting it here so that step doesn't miss that the field already
exists and only needs to be *checked*, not added.

## Session/login rewrite: multi-user authentication (D35)

Scope, deliberately split from authorization: this step makes `Session` and `POST /login` resolve
a real account from `Reporion\Auth\UserStoreInterface` instead of a single-owner boolean cookie.
It does **not** enforce namespace grants anywhere yet — `Session::isOwner()` still gates every
existing controller and index/search call exactly as before, unchanged in meaning (true only for
`isOwner: true` accounts). An editor or viewer account can now log in successfully and will see
exactly what anonymous sees today, because nothing yet consults `User::canRead()`/`canWrite()` at
request time. That's the expected shape of this step, not a bug — `visibilityClause($principal)`
and per-namespace checks in the controllers are the next step, still to come.

**What changed:** `Session` gains a `UserStoreInterface` dependency; the signed cookie now carries
a `username` instead of a bare `owner: true` flag; `Session::principal(Request): ?User` is the one
place a request becomes an account, and `isOwner()` is now a thin wrapper over it. `active` is
enforced in two places, not one — `principal()` returns null for a deactivated account even with a
validly-signed, unexpired cookie, and `login()` refuses one too — closing the gap the account-store
BUILD_LOG entry flagged ("active is stored but nothing reads it"). `AuthController::login()` takes
`username` + `password` (the login form's username field, dropped in the earlier single-owner
mockup port, is back — `templates/login.php`, `design/README.md` updated to match) and does a
constant-cost `password_verify()` against a fixed dummy hash on any unknown username, so a missing
account and a wrong password take the same time — a username-enumeration guard that costs one
constant.

New: `bin/reporion user:create --username=<u> --password-hash=<h> [--owner] [--grant=<ns>:editor|viewer]`
— the only way to create an account (D35: no self-service registration). Takes a pre-computed hash,
never a plaintext password, because argv is visible in shell history and to anyone on the box
running `ps`. `Cli\Application::boot()` registers it the same lazy-factory way as the `index:*`
commands. `conf/local.php.example`'s `auth.owner_password_hash` field is gone — accounts live in
`data/users/` now, and the example file points at `user:create` instead.

**Live-box impact — this is the consequential part.** Once this ships, the real `conf/local.php`'s
existing owner password stops authenticating anything: `Session`/`AuthController` read only
`data/users/` now, with no fallback. Asked the user narrowly before building this (per CLAUDE.md's
working agreement — this is exactly "changing anything in the account/grant model"); they chose a
CLI bootstrap command over a silent auto-seed from `conf/local.php`, specifically to avoid a
lingering dual-credential path. **To restore login on the live box, someone has to run, on the box,
as the user that owns `data/`:**

```
sudo -u www-data bin/reporion user:create --username=<u> --password-hash="$(php -r 'echo password_hash("…", PASSWORD_ARGON2ID);')" --owner
```

The `--password-hash` value **must be quoted** — an unquoted argon2id hash contains `$` characters
the shell will try to expand as variables (`$argon2id`, `$v`, ...), silently truncating the hash to
garbage with no error from the shell. `bin/reporion doctor`'s new failing check
("At least one active owner account exists") prints this exact command in its failure detail, so
the fix is discoverable from the tool that reports the problem, not just from this log.

**Four things caught in review (`advisor`), all fixed before commit:**

1. `Session::principal()` must never call `UserStoreInterface::find()` with a username taken from
   an unverified cookie payload — signature and expiry are checked first, `find()` only ever runs
   on a payload that already passed HMAC verification against the server secret.
2. A deleted or deactivated account must stop authenticating immediately, not only once its
   (30-day) cookie happens to expire — `principal()` treats "found but inactive" the same as "not
   found," both `null`. `SessionTest` has explicit cases for both.
3. `bin/reporion doctor`'s new check told the operator to run `user:create` but gave no way to
   produce a `--password-hash` value, because that instruction used to live in
   `conf/local.php.example`'s comment, which this commit deletes. Fixed by folding the
   `php -r '...password_hash...'` one-liner directly into the check's failure text.
4. `--password-hash` was accepted with zero validation — a plaintext string typed in the wrong
   field would write successfully and the account would be silently, permanently unusable
   (`password_verify()` can never match it, and login only ever reports "incorrect username or
   password"). Fixed with a `password_get_info()` check at creation time, so the mistake is an
   immediate, specific error instead of a mystery discovered at the next login attempt.

**Known gaps, not blockers for this step:** `UserStoreInterface` still has no `disable()` — the
only way to set `active: false` today is a direct `save()` with a hand-built `User`, no CLI or API
surface for it yet. `visibilityClause($principal)`, the `user_grants` index cache table (and
teaching `index:rebuild` to walk `data/users/*.json`, per the previous entry), per-namespace checks
in `PagesApiController`/`RenderController`/`SearchController`/`HomeController`/`PageController`, and
the admin user-management screen are all still separate follow-up work.

## Authorization, read side: visibilityClause($principal) (D35-D37)

Split from the Session rewrite on `advisor`'s recommendation: that step made authentication
multi-user but left every controller still gating on `isOwner()` unchanged — this step is what
actually makes namespace grants mean something. Read side only (index queries, page view, search,
namespace tree, sitemap); write-side authorization (`PagesApiController`, `RenderController`, and
threading the real `username` into `Storage::create/save/delete`'s `actor` parameter instead of the
hardcoded `'owner'` string) is deliberately deferred to its own commit — it's a different risk
surface (append-only revlog history vs. read-path data exposure) and deserves its own review.

**Design decision made while building this, not before:** no `user_grants` cache table in
`index.sqlite`, contrary to the sketch in the multi-user pivot commit. A signed-in principal's
grants are already fully resolved in PHP before any query runs (`Session::principal()` reads them
straight from `data/users/{username}.json`), so `Search\Query::visibilityClause($principal)` /
`pageAccessClause($principal)` bind them as SQL parameters directly instead of joining a cache of
them. Simpler than a join, and it eliminates an entire hazard class the join-table sketch would
have created: `index:rebuild` never touches `data/users/` at all now, because there is nothing
about accounts in the index to lose. **D36 is edited in this commit** (CLAUDE.md and
`docs/DECISIONS.md`) to drop the `user_grants`/`index:rebuild`-must-walk-`data/users/` language —
`docs/architecture-storage-index.md`'s SQL sketch and blockquote are corrected too, since they had
already been written assuming the join-table shape.

`Query`'s two clauses (`visibilityClause` for listings, `pageAccessClause` for direct-path access)
still differ only in their non-grant base visibility set (`'public'` vs `'public','unlisted'`) —
but the grant branch is identical between them and is the important, non-obvious part: **a grant
covering a row's namespace bypasses the visibility filter entirely**, not just widens it. A
grant-holder sees `private` pages inside their own namespace, the same as `owner` does — that's
the whole point of a namespace grant being ordinary staff access, not a scoped-down token. The
class docblock says this explicitly now, because an editor unable to read their own private drafts
is exactly the kind of "fix" someone would otherwise make.

Namespace matching uses `LIKE ... ESCAPE '\'` with `%`/`_` escaped in the grant namespace before
binding — `Grant`'s own validation allows both characters (they're ordinary namespace characters),
and both are SQL `LIKE` wildcards; unescaped, a grant on `reports_mri` would also match the
unrelated namespace `reportsXmri`. `VisibilityMatrixTest::testGrantNamespaceWithAnUnderscoreDoesNotWildcardMatch`
proves this doesn't regress.

Threaded `?Reporion\Auth\User $principal` (nullable = anonymous) through `IndexInterface`,
`Index\Sqlite`, `HomeController`, `PageController`, `SearchController`, and `Kernel`'s read-route
wiring, replacing `bool $isOwner` everywhere on the read path. `Http\PageTemplateRenderer`'s
`bool $isOwner` param is renamed to `bool $isSignedIn` — **a behavior change, not just a rename**:
any authenticated user now gets `page-view.php`'s app chrome (with `path`/`rev`/`status`/
`visibility` exposed), where before only the owner did. Correct per D35 — an editor/viewer with a
namespace grant is ordinary staff using the app, not a restricted reader — but worth flagging
explicitly so it doesn't read as an accident later. `HomeController`'s "/" special case (unlisted
via `pageAccessClause()` isn't good enough for the landing page, since "/" isn't "knowing the exact
path" — see the existing comment) now extends the same reasoning to grant-holders: a caller reaches
`site:home` directly only via `owner`, a grant covering `site:` (both folded into
`User::canRead()`), or the page actually being `public`.

**Three things caught in review (`advisor`), all fixed before commit:**

1. The doc/code contradiction above (`user_grants` table sketch vs. the bound-parameter design
   actually built) — CLAUDE.md's own working agreement says fix the doc in the same commit as the
   code, not later.
2. `HomeController`'s direct-read check had a redundant `$principal?->isOwner === true ||` branch —
   `User::canRead()` already returns true for an owner (`roleOn()` short-circuits on it). Collapsed
   to a single `canRead()` call so a future reader doesn't go looking for a difference that isn't
   there.
3. `VisibilityMatrixTest` asserted row-level filtering within a granted namespace but never called
   `listNamespace()` on a namespace the caller has *no* grant on — exactly invariant 9's territory
   (an empty listing must be indistinguishable from a namespace that doesn't exist, not a peek at
   what it privately contains). Added, for both a grant-elsewhere editor and anonymous.

Test matrix now covers CLAUDE.md's full Testing-section requirement: private/unlisted/public ×
{owner, editor-with-grant, editor-without-grant (holds a grant on a *different* namespace — the
case that actually discriminates a leaky predicate), viewer-with-grant, anonymous} ×
search/tree/sitemap/API.

## Authorization, write side: PagesApiController + RenderController (D35-D37)

Completes the split from the read-side authorization step: `PagesApiController::create()/save()/
delete()` and `RenderController::render()` now take `?Reporion\Auth\User $principal` instead of
`bool $isOwner`, and a write requires `editor` (or `owner`) covering the target path's namespace —
not just being signed in. `Storage::create()/save()/delete()`'s `actor` parameter now carries the
real signed-in `username` instead of a hardcoded `'owner'` string, so `meta.json`'s append-only
revlog finally records who actually wrote each revision (D37: whoever holds the write grant signs
their own work). Pages written before this commit still say `'owner'` in their revlog — accurate
for them, since there was only one account at the time; history is append-only and is not rewritten.

**Real bug caught in review (`advisor`) and fixed before commit: `create()`'s authorization order.**
`save()`/`delete()` have `$path` as a route parameter, so `canWrite($path)` always has something
real to check. `create()` doesn't — `path` lives inside the JSON body, so there is no namespace to
check *before* the body is parsed. The first version checked `canWrite($path)` as part of the same
`is_string($path) && ...` guard that also validates the field — which meant a real, authenticated
**owner** submitting a request with no `path` at all got 404 (looks unauthorized) instead of 422
(their actual mistake: a malformed request), because `canWrite(null)` doesn't type-check and the
whole guard short-circuited to the not-found branch.

Fixed with `User::hasAnyWriteAccess()` — a coarse, namespace-blind gate ("could this account write
*somewhere*, at all") — checked first. The full request ordering is now, deliberately in this
order:

1. `$principal === null || !$principal->hasAnyWriteAccess()` → 404. Rules out anonymous and pure
   viewers regardless of what they submit — matches invariant 9 exactly like the old single-owner
   check did: a caller who could never write here anyway learns nothing from the response.
2. Field validation (`path`/`meta`/`body` well-formed) → 422 for anyone who passed step 1. A real
   writer's malformed request is now honestly a 422 again, not a false 404.
3. `!$principal->canWrite($path)` → 404. The real per-namespace check, only reachable once `path`
   is known to be valid — an editor with a grant on a *different* namespace reaches this step (they
   passed the coarse gate) and gets 404 here, not because their request was malformed but because
   this namespace isn't theirs.

This ordering is easy to "simplify" back into the bug by merging steps 1 and 3, so
`PagesApiControllerTest`/`PagesApiTest` and the code comments both call it out explicitly. One
consequence worth naming precisely: an editor holding a grant on `reports:ct` who submits a
`reports:mri` path passes step 1 (they're a real writer) and reaches step 2 field validation before
failing step 3 — so they *can* distinguish a 422 (bad request) from a 404 (wrong namespace) for a
namespace that isn't theirs. That's intended, not a leak: it reveals "you are some kind of writer
here," which anonymous/viewer callers already can't hide from step 1 either, never *which*
namespace anyone else can write to.

**`RenderController` widened to any signed-in user, not owner-only** — a judgment call flagged as
open in the read-side commit, decided here: `/render` compiles caller-supplied markdown with no
page lookup, so there's no namespace grant to scope it by, and a viewer previewing a print
rendition of a page they can already read needs it exactly as much as an editor previewing a draft.
Still 404 for anonymous. `docs/architecture-api.md`'s Render section and Pages table are updated in
this commit to state the grant requirement per endpoint (CLAUDE.md: "New endpoints get a row in
docs/architecture-api.md in the same commit" — extended here to changed authorization on existing
rows, same reasoning).

Test matrix: editor-with-grant / editor-without-grant (a grant on a *different* namespace, the
leak-discriminating case) / viewer-with-grant (read-only — must fail every write) / anonymous, for
all three of create/save/delete, plus a direct assertion that the created page's `meta.json` revlog
records the real username. `RenderControllerTest` covers a viewer with no write grant anywhere still
getting a real render, and anonymous still getting 404.

## Admin user-management screen (D35-D37)

`GET/POST /admin/users` + `POST /admin/users/{username}/deactivate|reactivate`
(`Controller\AdminUsersController`, `templates/admin-users.php`) — owner-only account management:
list accounts, create one (username, password, owner checkbox, newline-separated
`namespace:role` grants), deactivate, reactivate. The mockup's "Users & groups" panel
(`design/mockup/WikiAdmin.dc.html`) is the visual source; its `groups`/`2FA`/`last seen` columns
and `@radiology:rw` ACL-string column are dropped in favor of the real model (namespace grants,
no 2FA, no login-tracking yet), and its "Invite" action is dropped (D35: admin-created only, no
self-service).

**Deliberate deviation from the route table:** `docs/architecture-api.md` (pivot commit) and
`design/README.md` both said `/admin/*` is an island and sketched `GET/POST/PATCH /api/v1/users`
as the JSON contract for it. Built as plain SSR forms instead — classic POST + redirect, the same
shape as `AuthController`, zero JavaScript. Reasoning: this project's own SSR-vs-island rule
("if it should survive JavaScript being broken... it is server-rendered") fits a rarely-used admin
form better than the mockup's tabbed-island design, and standing up this project's first
island-mounting subsystem to serve one CRUD screen would be a lot of new infrastructure
disproportionate to what it needs. Both docs updated in this commit to say so and to record the
actual routes; the `/api/v1/users` JSON shape stays documented as future work for whenever a
non-browser caller actually needs it, not built.

New: `Reporion\Auth\GrantParser::parse()` — extracted from `bin/reporion user:create`'s
`--grant=<ns>:<role>` parsing (last-colon split, so a multi-segment namespace like
`reports:mri:mioveni:editor` isn't misparsed as namespace `reports` + role
`mri:mioveni:editor`) so the CLI and this screen's grants textarea share one implementation
instead of two copies drifting apart. `UserCreateCommand` now calls it; its own tests that covered
parsing specifically moved to `GrantParserTest`.

`User::hasAnyWriteAccess()` (added in the previous write-side-authorization step) wasn't needed
here — every action on this screen is owner-only, checked once (`$principal?->isOwner !== true`),
not per-namespace.

**Design decisions made while building, not before, per `advisor`'s guidance:**

1. **"Owner-only" isn't a flat 404 everywhere it touches this screen.** The POST action routes
   (create/deactivate/reactivate) 404 a non-owner exactly like every other write endpoint —
   consistent with `PagesApiController`'s precedent. But `GET /admin/users` for a signed-in editor
   isn't an entitlement probe, it's a navigation dead end — and the app already settled the
   analogous question (`PageTemplateRenderer` shows app chrome to any signed-in user, not just
   owner). Resolution: the admin link only ever renders in `page-view.php`'s chrome when
   `$principal->isOwner` — so a non-owner never sees a link to a route that would 404 them, and
   the route itself still 404s regardless (invariant 9's letter, no visible broken link in
   practice). `PageTemplateRenderer::render()` now takes `?User $principal` instead of
   `bool $isSignedIn` so it can pass `isOwner` into the template alongside the existing
   signed-in/anonymous split.
2. **Plaintext password here, unlike the CLI, is correct, not an inconsistency.**
   `user:create --password-hash` exists specifically because argv leaks into shell history and
   `ps`. An HTML form POST body doesn't, so `AdminUsersController::create()` hashes the submitted
   password server-side with `password_hash(..., PASSWORD_ARGON2ID)`. Documented explicitly in the
   controller's own docblock so the difference doesn't get "fixed" into a mismatch later — the
   password is never echoed back into the re-rendered form on a validation error either.
3. **Self-lockout guard checks the real failure condition, not the literal action.**
   `wouldRemoveTheLastActiveOwner()` refuses a deactivation only when it would leave zero active
   owner accounts — not "can't touch yourself" and not "can't touch the current owner," either of
   which would also block a legitimate second-owner handoff. Mirrors exactly what
   `bin/reporion doctor`'s `checkOwnerAccountExists()` already looks for. Reactivate has no such
   guard — it can only ever add access back, never remove the last one.
4. **Reactivate is load-bearing, not a nicety.** Without it, `active: false` would be a one-way
   door with no recovery short of hand-editing `data/users/{username}.json` — `UserStoreInterface`
   still has no `disable()`/`enable()` method; both actions build a new `User` and go through the
   existing `save()`.

Test matrix: owner sees the list, non-owner and anonymous both 404 (including on the action
routes), duplicate-username and invalid-grant both re-render the create form with the typed
username preserved and the password dropped, last-active-owner deactivation is refused with a
second active owner making it succeed, and — the end-to-end case a controller-only test would
miss — **an account created through this screen can actually log in and write inside the
namespace it was granted**, and a deactivated account's already-issued cookie stops authenticating
on the very next request. `.wk-panel`/`.wk-panel-h` extracted verbatim from
`design/mockup/Wiki.dc.html`'s stylesheet (matching the established extracted-vs-authored
distinction in `assets/css/wiki.css`'s own header comment); `table.table` has no mockup source
(the mockup's own table rendered unstyled) and is authored fresh from the same design tokens.

## Revert (build order step 9, slice 1 of 4)

Step 9 in CLAUDE.md's build order is "History, diff, revert, sign" as one line, but it isn't one
commit's worth of work: `sign` alone needs a schema validator (`required_for: [sign]`, D7 — nothing
reads `conf/schema/*.json` yet beyond the files existing on disk) and `Support\Canonical` (canonical
byte-hashing for the signature digest, docs/FORMATS.md §8), neither of which exists. Rather than
land a big commit with two half-built prerequisites inside it, this step is sliced into four:
revert (done here — smallest complete slice, exercises the write-grant authorization from the two
previous steps, and A2 is the one invariant in this group that's unrecoverable if gotten wrong,
since history is append-only), then history/diff (read-only, lower-risk, and better designed after
seeing what a real `revert` revlog entry looks like on disk), then the schema validator, then sign.

`StorageInterface::revert($path, $toRev, $actor, $note = null): PageRecord` +
`Storage\FlatFile::revert()`, `POST /api/v1/pages/{path}/revert { to: N }`
(`PagesApiController::revert()`) — same authorization as save/delete (`editor`/`owner` grant
covering the namespace, 404 otherwise).

**Design decisions made while building, not specified in advance:**

1. **Revert is its own `StorageInterface` method, not a `$kind` parameter on `save()`.** `save()`'s
   contract is "here is the new content, write it"; revert's is "make an old revision number
   current again." Collapsing them would let a caller pass `kind: 'revert'` alongside arbitrary
   frontmatter/body — i.e. *claim* to be reverting while actually supplying different content.
   `revert()` takes only a revision number and reads the bytes itself.
2. **Bytes are replayed verbatim, never re-encoded.** `revert()` uses the exact bytes
   `readRevision($toRev)` returns as the new revision's content — it does not round-trip them
   through `parseDocument()` → `encodeDocument()`. That's what makes A2 ("revision 8's content
   equals revision 6's") literally byte-for-byte true rather than true only up to normalisation;
   `parseDocument()` is still called separately, only to extract `visibility` and build the index
   snapshot, never to reconstruct what gets written to disk.
3. **No `base_rev`/conflict check, unlike `save()`.** `save()`'s `base_rev` protects
   caller-supplied content the caller might not have seen change underneath them. `revert()` never
   makes that claim — it always appends `$toRev`'s content forward as the new current revision,
   regardless of what happened in between — so there is nothing to protect against. Documented in
   `FlatFile::revert()`'s own docblock so a future reader doesn't "fix" this to match `save()`.
4. **Status is never carried forward.** Reverting to an old `signed` revision produces a fresh
   `draft` (unless the page is `archived`) — the new revision has no signature record of its own,
   so claiming `status: signed` for it would be a legal-integrity bug (D3: a correction is a new
   revision, signed again, not an old one un-superseding itself). `revert()`'s status logic is
   identical to `save()`'s existing archived-preserving branch, reused as-is.

**Real bug caught in review before commit: crash-recovered reverts were silently mislogged as
`edit`.** `FlatFile::recoverIntent()` (the journal-replay path) derived `revlog[].kind` from the
journal's `op` field with only two cases — `'create'` and everything-else-is-`'edit'`. A crash
between the rev file landing and `current.md`/`meta.json`/the journal's `done` line meant the
*next* boot's replay would recover the revert correctly in every respect except recording it as a
plain edit in the audit trail — silently wrong, not loudly broken, so nothing but a test targeting
this exact window would have caught it.
`testReplayRecoversARevertInterruptedBeforeCurrentMdAndMetaWithTheRightKind` now does.

**Known gap, noted rather than fixed:** `testRevertOfASignedPageProducesAFreshUnsignedDraft` hand-writes
`status: signed` and a synthetic `signatures[]` entry directly into `meta.json`, because
`Storage::sign()` doesn't exist yet — the right call for testing revert's own behaviour in
isolation, but that test should be revisited to drive the same assertion through the real `sign()`
path once slice 4 lands.

`docs/FORMATS.md`'s journal `op` enum, `docs/architecture-api.md`'s A2, and
`docs/architecture-storage-index.md` §5 (a new "Revert" subsection, since it's a second entry into
the same journal/rev-file/meta write path §5 already documents step by step) are all updated in
this commit.

## History and diff, read-only (build order step 9, slice 2 of 4)

`GET /{path}/history` (`Controller\HistoryController`, `templates/history.php`) — revision list
with per-row add/remove line counts, plus a diff panel when `?from=&to=` query params are present.
`POST /{path}/history/revert` — a classic SSR form action (not a call to the JSON
`/api/v1/pages/{path}/revert` endpoint), so the "restore" button on this page works with no
JavaScript, same split established by the admin screen's own actions. Same read-entitlement rule
as viewing the page itself (`Index\Sqlite::findByPath($path, $principal)` — a namespace grant or
public/unlisted direct-path access), so anyone who can read a page can read its history, and no
more.

New `Reporion\Support\Diff::lines()`/`::counts()` — hand-rolled classic LCS line diff, the class
the repo layout already named for this. Not a Composer dependency: reports are ~2.4 kB prose
documents (D2), so `O(lines × lines)` is nowhere near a real cost, and reaching for Myers diff or a
package would be solving a problem this project doesn't have.

**Deliberate deviations from the mockup**, same reasoning as the admin screen's SSR-vs-island call:

- No radio-multiselect "compare selected" — that needs client-side selection state. A per-row
  "diff vs current" link plus the plain `?from=&to=` query string covers the useful case (see an
  old revision's changes against what's live now) without JavaScript. Picking an arbitrary pair to
  compare is out of scope for this slice.
- Only the "unified" diff view is built — the mockup's side-by-side and rendered toggles aren't.
- `page-view.php`'s "History" button, one of the buttons that file's own docblock listed as
  deliberately unported ("a button pointing nowhere is worse than no button"), is live now that
  the route exists — the docblock is updated to say so.

**A behavior nobody specified, worth stating plainly:** the diff covers the *whole stored
document*, frontmatter included, not just the body — a consequence of `readRevision()` returning
the full encoded bytes (frontmatter block + body), which `Diff::lines()` then compares as-is. This
is arguably the right default (a metadata change, e.g. `visibility: private` → `public`, shows up
in the diff same as a body edit), but it wasn't a deliberate design choice so much as what falls
out of reusing `readRevision()` unchanged — noted here so it reads as informed, not accidental,
the next time someone asks "why is the YAML frontmatter in my diff."

**Known, accepted cost, not fixed this slice:** `history()` calls `readRevision()` once per
revision to compute each row's add/remove counts — for a page with N revisions, N gunzip-and-reads
on every history-page load. At CLAUDE.md's own report size (2.4 kB) and the revision counts this
project will realistically see, this is not a problem in practice, and this route sits outside the
`page view < 50 ms` performance target CLAUDE.md's working agreement names — but it is an
unbounded-N cost with no upper limit today, and worth revisiting (capping to the most recent N
rows, most likely) if a page ever accumulates hundreds of revisions.

`docs/architecture-api.md`'s Table 1 and "Revisions" section are updated to say which of the
documented shapes are actually built: `GET /{path}/history` (SSR, this commit) and
`POST /api/v1/pages/{path}/revert` (the previous commit) exist; the JSON
`GET .../revisions`, `GET .../revisions/{n}` and `GET .../diff` endpoints do not.

Test matrix mirrors the read-side authorization matrix already established: owner, editor-with-grant,
editor-without-grant, anonymous-on-public, anonymous-on-private, and — for the restore action
specifically — viewer-with-grant (read-only, must get 404 and must not even see the form in the
rendered page).

## Schema validator (build order step 9, slice 3 of 4)

`Reporion\Schema\Loader` + `Reporion\Schema\Validator::missingForSign()` — the last prerequisite
`sign` (slice 4) needs. Nothing calls this yet; it's built and fully tested in isolation, matching
the same reasoning as revert and history before it (see the "slice 1 of 4" entry).

`Loader::fieldsFor(list<string> $modalities)` loads `conf/schema/base.json` plus every listed
modality's own file (D29: `modality` is a list — a combined CT+MR study must satisfy *both*
modalities' sign-requirements, so the resolved field set is a union, not a pick-one), resolving
each file's `extends` one level only — every shipped schema extends `base` and nothing chains
deeper, so a general multi-level resolver would be solving a problem no file has. A modality with
no schema file (`PET`, `other`, any future addition) silently falls back to `base` fields only,
never an error — a missing file must not make a report un-signable for a reason nobody intended.

`Validator::missingForSign()` is deliberately the *only* validation mode. `conf/schema/base.json`'s
own `$comment` says "required blocks SIGNING, never saving" (D7) — every `required: true` and
`required_for: ["sign"]` field is the same gate, checked at the same one moment. There is no
`missingForSave()`; building an unused validation mode nothing calls would be exactly the
speculative surface CLAUDE.md's working agreement warns against. Fixed the same "validation on
save" phrasing left over in `docs/architecture-storage-index.md` §7 while touching that section —
it was wrong before this slice too, just never mattered until something read it literally.

**The one finding that would have made slice 4 unbuildable, caught before writing the validator,
not after:** `status` and `visibility` are both `required: true` in `base.json`, but
`Storage\FlatFile` keeps `status` entirely in `meta.json` — it is never written into `current.md`'s
frontmatter YAML block at all, and `visibility` is read *from* frontmatter only to seed
`meta.json`'s own copy. A validator fed only `PageRecord::$frontmatter` would report both fields
missing on every single sign attempt, for every page, permanently — signing would simply never
work. `missingForSign()`'s contract is explicit about this instead of hiding it: it takes a plain
`array $fields`, and the **caller** is responsible for merging `status`/`visibility` in alongside
frontmatter before calling it (documented on the method itself).
`testStatusAndVisibilityLiveOutsideFrontmatterButAreStillChecked` exists specifically so slice 4
can't reintroduce this by feeding the validator `$record->frontmatter` alone.

Test matrix: one test per modality schema file confirming its own `required_for: ["sign"]` fields
are actually enforced (a typo like `"required_fr"` in any one file fails loudly and specifically,
not as a generic "something's missing" case), the union case using a CT+MG combination
specifically — not CT+MR, where both files happen to require the same field (`indication`) and
so wouldn't have caught a union that silently resolved only one modality — an unknown-modality
fallback, whitespace-only text counting as absent, an empty list counting as absent, and
`patient.name` reporting as the dotted path `patient.name` (the one nested field in the shipped
schemas).

## Sign — the last slice of build order step 9 (4 of 4)

`POST /api/v1/pages/{path}/sign { parafa? }` (`PagesApiController::sign()`) + `StorageInterface::sign()`
/ `Storage\FlatFile::sign()` + `Reporion\Support\Canonical` — closes out step 9 ("History, diff,
revert, sign"), sliced into four commits since revert. Every prerequisite from the earlier slices
gets used together here for the first time: `Schema\Loader` resolves the page's modality fields,
`Schema\Validator::missingForSign()` gates the request, `Canonical::bytes()` produces what actually
gets hashed.

**Sign is a `meta.json`-only operation — no new revision, no journal entry.** Signing doesn't
change what the document says, only that it is now legally the report
(docs/architecture-storage-index.md §5): it appends a `signatures[]` record for the *current*
revision and sets `status: signed`. No journal entry either — a single atomic `meta.json` rewrite
(temp + fsync + rename, the same `AtomicWriter::put()` every other meta write already uses) is
already all-or-nothing on its own; there is no multi-file sequence for a crash to leave half-done,
unlike create/save/revert.

**Idempotent per revision.** A repeated sign of a revision that already has a signature record is
a no-op — same "duplicate submission" reasoning `create()`/`save()` already apply to a retried
request — not a second `signatures[]` entry for the same `rev`. `FlatFileTest` and `PagesApiTest`
both cover this at their respective layers (the exact `signatures[]` count at the storage level,
"repeating sign never creates a new revision" at the HTTP level, since the JSON response doesn't
expose `signatures` at all).

**`current.md`, not `readRevision($rev)`, is what gets hashed** — deliberately, not a shortcut:
D2 makes them byte-identical for the current revision, and `current.md` is already open for every
other read `sign()` does; `readRevision()` would gunzip a file whose plain bytes are sitting right
next to it. Commented in the code specifically because the "obvious" alternative looks more
correct at a glance.

**`Canonical::bytes()`'s frontmatter reordering ties it to `Schema\Loader::fieldsFor()`'s output
shape (not a hard class dependency — a duck-typed array contract), but `Storage\FlatFile` itself
never imports `Schema\Loader`.** The resolved schema fields ride in as a parameter to both
`Canonical::bytes()` and `Storage::sign()`; the controller resolves the schema (from the page's own
`modality` frontmatter field) and passes it through. Keeps `Storage` decoupled from `Schema`, same
principle as `Session`/`Kernel` staying decoupled from disk paths elsewhere.

**Doc-vs-code correction, same discipline as the last two slices:** `docs/FORMATS.md` §8 said
canonical bytes have scalars "unquoted where YAML allows." They don't — `Symfony\Yaml`'s dumper
quotes some plain strings (`RM cerebral` becomes `'RM cerebral'`) for reasons this project doesn't
control and isn't fighting. Corrected to say what's actually true: quoting is whatever the dumper
decides, and the property the signature digest depends on is *determinism*, not a particular quote
style — proven by `testReCanonicalisingSignedBytesIsANoOp` reparsing and re-canonicalising real
data (including a nested `patient` object, an unknown field, and a null value) and getting
byte-identical output. `docs/architecture-api.md`'s sign row updated from "not built yet" to the
actual response shapes, including the `422 { error: { fields: { missing: [...] } } }` shape chosen
for the incomplete case (a `fields` map wrapping the dotted-path list, not the bare list itself —
`Http\ApiResponse::error()`'s existing type contract is `array<string, mixed>`, caught by phpstan
before it shipped).

**Deferred, not forgotten:** D3 specifies `revlog[].kind` records `resign` when an edit corrects an
already-signed page (distinct from ordinary `edit`, for the audit trail / history view).
`Storage\FlatFile::save()` still writes `edit` unconditionally, regardless of whether the page being
saved was previously `signed`. Left alone deliberately this slice — CLAUDE.md's working agreement
names "touching the signing/revision code" as something to ask about first, this is a behavior
change to already-shipped code (not new code), and `sign()` itself works completely without it.
When it lands: it needs the same pairing `revert` needed —
`FlatFile::recoverIntent()`'s crash-replay `kind` derivation (currently `match` on `create`/
`revert`/default-`edit`) would need a `resign` arm too, or a crash-recovered correction-of-a-signed-page
would silently misreport in the revlog exactly the way a crash-recovered revert once did before that
bug was caught.

**Known, accepted gap, not fixed this slice:** the index update after a successful sign is not
covered by `meta.json`'s own write atomicity. A crash between the `meta.json` rewrite and
`$this->index->index()` leaves the index showing `draft` for a page that is `signed` on disk — disk
stays authoritative (invariant 1) and `index:verify`/`index:rebuild` catch the drift, the same class
of gap `save()` and `revert()` already have. Worth stating plainly here since this is the one place
"signed" and "not signed" could disagree between the two stores, however briefly.

## Addendum to "Sign — the last slice": resign lands too

The previous entry deferred D3's `revlog[].kind = "resign"` distinction. Asked the user directly
(CLAUDE.md's working agreement names "touching the signing/revision code" as something to confirm
first) — approved as an immediate follow-up, done in the same session.

`FlatFile::save()` now captures `$kind = $meta['status'] === 'signed' ? 'resign' : 'edit'` *before*
`$meta['status']` gets overwritten to `draft` a few lines later — correcting an already-signed page
is a distinct audit-trail event from ordinary drafting, and the two are otherwise indistinguishable
in `revlog[]` once written. `FlatFile::recoverIntent()`'s crash-replay `kind` derivation gained the
paired arm, same pairing `revert` already needed: `$intent['op']` is `'save'` for both an ordinary
edit and a correction-of-signed, so the journal's own `op` field can't tell them apart — the
*pre-crash* `meta.json`'s `status` is the only available signal, and it's still intact on disk at
this point in recovery precisely because the crash happened between the rev-file write and the meta
rewrite that would have changed it. That's why this recovery arm keys on `$meta['status']` rather
than `$intent['op']`, unlike the `create`/`revert` arms next to it.

Three tests: a direct correction of a signed page records `resign` and drops to `draft` (never
carrying `signed` forward with no new signature); an ordinary edit of an unsigned page is unaffected
and still records plain `edit`; and the crash-window case — a correction of a signed page
interrupted before `current.md`/`meta.json`/the journal's `done` line, replayed — recovers as
`resign`, not the `edit` a naive recovery would produce.

## The editor: GET/POST /{path}/edit — build order step 8's missing half

Before this route existed, the entire write surface (steps 5-9 of the build order) was reachable
only from `curl`/a script calling the JSON API directly — there was no way to create or edit a
page's content through the actual website. `Controller\EditorController` +
`templates/editor.php` close that gap: classic SSR form, no JavaScript, the same shape
`AdminUsersController`/`HistoryController` already established.

**One textarea holds the whole document, not a generated per-field form — the load-bearing
decision, not a shortcut.** `design/mockup/WikiEditor.dc.html`'s own `<textarea class="wk-ta">`
already shows the full `---\nfrontmatter\n---\n\nbody` block as one field, so this matches the
mockup exactly — but the reason it's *right*, not just faithful: `Storage::save()` replaces
frontmatter wholesale, it does not merge. A form exposing a curated subset of fields (`title`,
`visibility`, `summary`, ...) would silently delete every field it doesn't show the moment someone
saved through it — `patient`, `modality`, `region`, anything the form's author didn't think to
include. Editing the raw document makes that failure mode structurally impossible: whatever the
page already had round-trips through the same textarea, untouched fields included, because the
user is never asked to reconstruct a subset — they're editing the whole thing.

**Four explicit scope cuts**, each a separate, independently useful follow-up:

- No marked.js live preview.
- No autosave / no IndexedDB draft (D25's "survive a dropped connection" requirement is not met
  by this slice — a browser crash or a closed tab loses unsaved text, same as any plain HTML form).
- No JS-driven conflict-resolution UI (see the no-JS conflict path below — a real, working
  fallback exists, just not an interactive one).
- **No `GET /new`.** A path builder for brand-new pages is a different problem — `POST`, not
  `PUT`, and there is no existing document to round-trip into a textarea. Pages are still created
  via the JSON API only; this route closes the *editing* gap, not page creation.

**The no-JavaScript conflict path is a real, tested fallback, not a stub.** `RevisionConflictException`
re-renders the same form with exactly what the user submitted still in the textarea (never lost),
the server's actual current document shown read-only alongside it for comparison, and `base_rev`
advanced to the now-current revision — so a deliberate resubmit, once they've reconciled by hand,
succeeds. `testStaleBaseRevReRendersWithTheTypedTextAndTheCurrentServerDocument` covers all three
parts of that guarantee together, not just that the response is a 200.

**Real bug caught by the test suite itself, not review:** the frontmatter-shape check originally
used bare `is_array($frontmatter)`, which YAML's PHP representation can't distinguish from a
*list* — `Yaml::parse("- a\n- b\n")` returns an array too. A document with a YAML list where the
frontmatter mapping should be would have been silently accepted and handed to `Storage::save()` as
"frontmatter." `testFrontmatterThatIsNotAMappingReRendersWithAnError` caught this immediately
(a genuine test failure, not a hypothetical) — fixed with `array_is_list()`, which is the actual
distinguishing check.

**Read access resolves through `Index\Sqlite::findByPath($path, $principal)`, the same predicate
every other read route uses — not re-derived from `canWrite()` alone**, caught in review. `canWrite()`
happens to imply read access for every grant shape that exists today, so this wasn't currently
exploitable, but `EditorController` was the one controller resolving page access outside the
query invariant 6 names as the single source of truth. Both `edit()` and `save()` now check
`findByPath()` first, matching `PageController`/`HistoryController`.

**Deliberate ~10-line duplication:** `EditorController::encode()`/`parse()` mirror
`Storage\FlatFile`'s own private `encodeDocument()`/`parseDocument()` exactly (same
`"---\nyaml\n---\n\nbody"` shape and regex). Not extracted into a shared `Support` helper this
slice — the controller never touches disk itself (`Storage::save()` still does, satisfying
invariant 5), and the duplication is small and stable. A shared `Support\DocumentFormat` (or
similar) is a reasonable follow-up refactor, not a correctness requirement here.

Smaller things worth recording: the `note` field submits straight into `Storage::save()`'s
existing `$note` parameter, so revlog notes are now actually reachable from the UI (the history
page already displays them — this is the first thing that writes one from outside a test).
`page-view.php`'s Edit button is the second of that template's originally-stubbed buttons to go
live (History was the first), gated on the new `canWrite` var `PageTemplateRenderer` now passes
(alongside `isOwner`) rather than on merely being signed in.

`docs/architecture-api.md` Table 1 corrected from `island` to what actually shipped — same
doc-vs-code discipline applied to `/admin/*` and `/{path}/history` in earlier steps.

## The palette (⌘K) — build order step 7's missing half

`GET /api/v1/search?q=` (`SearchController::suggest()`) + `assets/js/palette.js` — the second gap
from steps 7/8 the user asked about directly (the editor, closing step 8's gap, landed just
before this). Same visibility/grant rules as the SSR `/search` route (the identical
`Index::search($term, $principal)` call), reachable anonymously.

**Progressively enhances the existing `.wk-search` form instead of the mockup's separate
modal-overlay button.** `design/mockup/WikiPalette.dc.html`/`Wiki.dc.html` show a `<button>` that
opens a full-screen `.wk-pal-back`/`.wk-pal` modal with filters, an AI-answer box and a footer.
This build instead turns the `<input>` already sitting in every signed-in template's top bar into
an inline typeahead dropdown. Deliberately, not a shortcut: no markup has to exist only for
JavaScript, and "the script fails to load" needs no special-casing — the form is already a
complete, working plain GET to `/search`, exactly as it always was. `.wk-pal-row`/`.wk-sel` are
extracted from the mockup's stylesheet (see `assets/css/wiki.css`'s own header note); `.wk-pal-drop`
(the dropdown container) is authored fresh, since the mockup has no equivalent — its result rows
live inside a modal, not an inline dropdown.

**Wired into five templates carrying the shared `.wk-top` bar** (`page-view.php`,
`search-results.php`, `admin-users.php`, `editor.php`, `history.php`) — **not**
`layout-public.php`, which has no search bar at all (A4: "no palette" is explicit for the
anonymous single-page reader view, and always has been, independent of this feature landing).

**`docs/architecture-api.md` Table 4 gained a row, not a restriction — caught in review.** The
first version wired the palette into `search-results.php` (itself anonymously reachable, unlike
`layout-public.php`) without checking whether `/api/v1/search` belonged on Table 4, the documented
list of what's reachable without signing in. It does: A1 already described "⌘K... calls
`/api/v1/search`" as the design from before this endpoint existed, and `/search` itself already
carries the identical "public pages only" guarantee on that table — `/api/v1/search` is the same
guarantee in JSON, not a new exposure. Added explicitly rather than left implicit, so a future
reader doesn't have to re-derive it the way review just did.

**`Index\Sqlite::plainSnippet()` — new, alongside the existing `highlightSnippet()`.** `search()`'s
raw snippet value carries two sentinel control bytes (`\x02`/`\x03`) marking match boundaries,
designed for exactly one consumer: `highlightSnippet()`'s escape-then-substitute into `<mark>`
tags for the SSR page. The JSON endpoint is a second consumer with no HTML to substitute into —
without `plainSnippet()`, the raw control bytes would have gone straight into a JSON response,
which is a strictly worse leak than the SSR path's markers-into-unescaped-HTML risk this project
already fixed once tonight. `testJsonSuggestSnippetHasNoSentinelMarkersOrHtml` catches it directly.

**`palette.js` has no automated test coverage — stated here explicitly, not left to read as an
oversight.** Every other component landed this session with tests; this one doesn't, because
there's no browser harness in this project and building one for ~150 lines of progressive
enhancement would be disproportionate to what it's worth. What *is* tested, and is the part that
actually matters for correctness: the plain SSR `/search` form the palette enhances keeps working
exactly as before (`SearchTest`'s existing coverage), and the JSON endpoint it calls is fully
tested independent of the JS (`testJsonSuggestReturnsPlainDataForTheGivenTerm`,
`testJsonSuggestObeysVisibilityForAnonymous`, the sentinel-marker test above). `node --check` only
proves the file parses as valid JavaScript, nothing behavioral. A future browser-level test harness
(Playwright or similar) would be the right place to cover keyboard navigation, debounce timing and
the dropdown's open/close behavior — not built here.

## GET/POST /new — the create half of the write UI

`Controller\NewPageController` + `templates/new.php` — the counterpart to the editor
(`Controller\EditorController`), completing the create/edit/history/restore loop the user asked
for directly. Same raw-document textarea as the editor, same reasoning (no per-field form to
silently drop a field it doesn't show), for the same file format — a plain text `path` field
plus one big textarea, prefilled with a minimal scaffold, not a segmented
`reports:{modality}:{site}:{yymmdd}-{name}` builder with live index validation like the mockup's
`WikiCreate.dc.html` shows. That builder is real, separate scope; a plain field is what unblocks
creating a page from the browser today.

**`Reporion\Support\DocumentFormat` extracted from `EditorController`, as its own docblock
predicted the second caller would justify.** `NewPageController` needed the identical
encode/parse pair; duplicating it a second time was worse than extracting it once. `Storage\FlatFile`'s
own private `encodeDocument()`/`parseDocument()` are deliberately left untouched — this class
exists for editors, not for storage's actual write path, and `FlatFile`'s methods are stable,
tested code with no reason to touch.

**The extraction surfaced a real, pre-existing constraint that shaped the scaffold's exact
text.** `DocumentFormat::parse()`'s regex (mirroring `FlatFile`'s own) requires at least one line
inside the frontmatter fences — a genuinely empty `---\n---\n\n` block does not match at all. This
was already true of `FlatFile::parseDocument()` before this commit; `DocumentFormatTest` is simply
the first test to notice it. That's why `NewPageController::SCAFFOLD` ships as
`"---\ntitle: \nvisibility: private\n---\n\n"` (two real lines) rather than an empty frontmatter
block — an empty scaffold would fail to parse the moment someone submitted it completely
unchanged, before they'd typed anything.

**The path-collision behavior needed surfacing, not inheriting silently — the discriminating test
in this slice.** `Storage\FlatFile::create()` appends `-2`, `-3` on a path collision
(docs/FORMATS.md §1) and returns the path it actually allocated. The redirect after a successful
create follows `PageRecord::$path` (what was actually created), never the submitted form value —
redirecting to the submitted path would 404 the instant a collision happened, since that exact
path was deliberately not the one written.
`testCreatingAPageThatCollidesRedirectsToTheAllocatedPathNotTheSubmittedOne` is the test that
would catch a regression here.

**The "New" nav link is wired into `page-view.php` only, a known and named gap, not an
oversight.** `PageTemplateRenderer` now also computes `canCreate` (`$principal?->hasAnyWriteAccess()`)
alongside `canWrite`/`isOwner`, deliberately a different question from `canWrite($record->path)` —
a viewer or a wrong-namespace editor can read a given page but must not see a link implying they
can create pages anywhere. `search-results.php`, `templates/history.php`, `templates/editor.php`
and `templates/admin-users.php` don't carry this link yet — reaching `/new` from any of those four
means typing the URL. Follow-up, not fixed here.

## GET /{ns}: — namespace index

`Controller\NamespaceController` + `templates/namespace.php`, built on the already-complete
`Index\Sqlite::listSubnamespaces()` and `listNamespace()`. Ported from
`design/mockup/WikiNsIndex.dc.html`: sub-namespace cards with page counts, and a plain pages
table. Deliberately not ported: bulk select/move/tag/export/visibility (no backend for any of
those actions exists), "recent activity here" (needs an audit log, not built), and the
"namespace description" panel (would read an `_index` page convention this project doesn't have).
**`design/mockup/WikiTree.dc.html`'s persistent sidebar is a separate, chrome-level concern, not
part of this route** — stated explicitly so "namespaces" doesn't later look half-done because the
sidebar was never built; it wasn't in scope for a namespace-index *page*, only for global
navigation chrome, which nothing in this session has touched yet.

**Same invariant-9 shape as `PageController::view()`, extended to an aggregate.** A namespace
with zero visible sub-namespaces and zero visible pages for the calling principal 404s exactly
like a namespace that was never created — `listSubnamespaces()`'s existing leak-prevention
property (visibility clause applied *inside* the aggregate, before `GROUP BY`, from the slice that
built it) is what makes this safe: a non-zero count for a sub-namespace the caller cannot see
would otherwise leak its existence through the controller layer even with the query itself
correct.

**Two real bugs caught by advisor review, both fixed before commit, neither caught by the test
suite as it stood:**

1. `Index\Sqlite::listSubnamespaces()`'s prefix-offset arithmetic used `strlen($ns) + 2` — a byte
   count — to index into SQLite's `SUBSTR`/`INSTR`, which count UTF-8 *characters*. Every existing
   fixture was ASCII, so nothing caught it. `Storage\FlatFile::assertValidPath()` only rejects `/`
   and empty segments; it does not fold to ASCII the way `Support\Slug::normalize()` does, and
   `NewPageController` passes the submitted path straight through — so a namespace like
   `rapoarte:măgurele` is genuinely creatable from the browser today. Fixed to `mb_strlen()`;
   `testSubnamespaceGroupingHandlesAMultibyteNamespaceSegment` (tests/Visibility/VisibilityMatrixTest.php)
   is the discriminating test — it puts the multibyte character in `$ns` itself (the value the
   offset arithmetic measures, not merely a child segment), and was run red against `strlen()`
   before being confirmed green against `mb_strlen()`.
2. The route shipped with no way to reach it — all HTTP tests constructed `/reports:mri:` by
   hand. `page-view.php`'s and `history.php`'s breadcrumb segments were plain `<span>`s; fixed both
   (each non-final crumb segment now links to `/{accumulated-prefix}:`) and did the same in
   `namespace.php` itself so a child namespace's crumbs reach its parent.
   `testCrumbsLinkToEachAncestorNamespaceIndex` (tests/Http/PageViewTest.php) covers the page-view
   side. This is the second time this session a write/read route shipped without every reasonable
   entry point wired to it (the first was `/new`'s nav link, above) — noted as a pattern, not
   re-litigated further here.

**Known, named gap: no `layout-public.php` split for this route.** `PageController::view()`
switches to the public chrome for an anonymous caller viewing a `public` page (invariant 9's "two
audiences" principle); `NamespaceController` does not — an anonymous visitor sees the internal
`wk-top` chrome (search palette, branding) on a namespace index, even though the listing predicate
itself is correctly public-pages-only. `docs/architecture-api.md`'s Table 1 and Table 4 rows for
`/{ns}:` are corrected to say this plainly rather than silently claim the two-audience split that
isn't there yet. Building that split is real, separate work — a decision about whether a
namespace index belongs on the public surface at all, not a one-line template swap — left for
whenever the public-site work resumes.

**`phpunit.xml` referenced a `tests/Import` directory that didn't exist**, breaking the bare
`vendor/bin/phpunit` invocation for anyone who didn't already know to pass
`--exclude-testsuite import` (the importer is explicitly out of scope for this "full frontend"
request). Restored as an empty directory with a `.gitkeep` rather than removing the suite
declaration, since the importer work will need it back.

## fix: CRLF from a browser textarea broke both /new and /edit

A real user hit this live: creating a page through `/new` failed with `RuntimeException: The
document must start with a "---" frontmatter block.` on content that was visibly well-formed.
Cause: a browser `<textarea>` submits CRLF line endings on form POST regardless of what was typed
or the OS — normal HTML forms behavior — and `Support\DocumentFormat::parse()`'s
`^---\n(.*?\n)---\n\n?(.*)$` regex only matches a bare LF. Confirmed via `git stash` on the fix
(red), then restored (green), reproducing the user's exact error string before touching anything
else.

**This broke every real save through `/edit` too, not just `/new`** — both controllers call the
same `DocumentFormat::parse()`. The test suite didn't catch it because every existing `/new` and
`/edit` HTTP test builds its POST body with `http_build_query()`, which produces LF-only output;
`tests/Http/NewPageTest.php::testCreatingAPageWithCrlfLineEndingsFromABrowserSucceeds` and
`tests/Http/EditorTest.php::testSavingWithCrlfLineEndingsFromABrowserSucceeds` build the raw
urlencoded body directly to keep the CRLF, closing that gap. `POST /api/v1/pages` and
`PUT /api/v1/pages/{path}` (`Controller\PagesApiController`) take separate `meta`/`body` JSON
fields, not a raw document string — not affected by this bug at all.

Fix: normalize `\r\n`/`\r` to `\n` at the top of `DocumentFormat::parse()`, mirroring
`Storage\FlatFile::normalizeText()`'s own approach. **`FlatFile::parseDocument()` carries the
identical regex and was deliberately left untouched** — every call site there parses content
`FlatFile::encodeDocument()` itself wrote, which already runs `normalizeText()` (LF-only) and
`Yaml::dump()` (also LF-only) before this ever sees it; there is no browser-textarea boundary on
that path, so there is nothing to fix — recorded here so the next reader doesn't find the twin
regex and assume it was missed.

## feat: page delete — the kebab menu's one live item

`Controller\PageController::confirmDelete()`/`delete()` + `templates/page-delete-confirm.php`,
wired into the page-view kebab menu. The user asked for it directly (no click-to-delete existed —
only `DELETE /api/v1/pages/{path}`, unreachable from a browser form) and asked explicitly to
respect the mockup's design, so this is a closer port of `WikiPage.dc.html`'s page-actions menu
than `page-view.php`'s own docblock previously called for.

**Built as a real `<details>`/`<summary>` disclosure, not the mockup's onClick-toggled JS
component** — needs no JavaScript at all, consistent with every other write action in this app.
Of the mockup's seven menu items (rename, move, duplicate, save-as-template, visibility, sign,
revert, delete) only Delete has a backend; the other six are still not scaffolded — same "a button
pointing nowhere is worse than no button" rule as before.

**Two deviations from what was actually approved, both for the same underlying reason — recording
them explicitly since they're visible departures from a specific decision:**

1. **No new color token was added, despite the user approving "add a danger token."**
   `tokens.css` already had `--state-error` (used for diff removals) — CLAUDE.md says not to
   invent new colours, and adding a second red token when a semantically-identical one already
   existed would have been exactly that. `.wk-mi-danger` and the new `.btn-danger` both reuse
   `--state-error` instead.
2. **A confirmation step was added that the user didn't ask for and an earlier version of this
   plan explicitly argued against** (reasoning: revert/deactivate/sign have no confirm dialog
   anywhere in this app, so delete shouldn't need one either). Advisor review caught the flaw in
   that reasoning: revert and deactivate are both reversible from inside the app; delete is not —
   the only way back is `data/trash/` on disk until `trash:purge` runs, and there is no restore UI
   at all. With a bare `<details>` menu, one stray click on the kebab and one on the item would
   have been enough to remove a page with no in-app undo. `GET /{path}/delete` now renders a plain
   confirmation page (reusing `.card`, the same primitive the login screen uses) before the actual
   `POST /{path}/delete` fires. Not the mockup's `.dialog` overlay — that primitive is JS-toggled
   in the mockup and has no captured CSS values anywhere in this repo (see `assets/css/wiki.css`'s
   header note on `.wk-menu`/`.wk-mi` for the same problem), so building it would mean inventing
   both its behavior and its look from nothing; a plain page needs neither.

**`page.delete`'s `%d`-placeholder lang key already existed in `lang/en.php` before this slice** —
clearly planned in advance for exactly this label. That's why `PageTemplateRenderer` and
`PageController` both now take `int $trashPurgeDays` (threaded from
`$config['pages']['trash_purge_days']`, which already existed in `conf/local.php.example`) instead
of hardcoding "30" — using the key's placeholder for its intended purpose, not new scope.
`tests/Http/HttpTestCase.php`'s shared config fixture gained a `pages.trash_purge_days` key for
the same reason the file's own docblock already documents: "a new key only needs adding here."

**Known, named gap, not addressed here:** rename, move, duplicate, save-as-template, visibility-
flip and revert-to-last-signed remain unbuilt menu items — each needs its own service and its own
slice, same reasoning as always. Copy/duplicate and move/rename came up as a direct follow-up
question in the same conversation and are next in line for exactly this reason.

## feat: Workbench icon nav rail — chrome slice 1/5, page-view.php only

The user asked to port `design/mockup/WikiPage.dc.html` completely, with visible-but-inert
placeholders for unbuilt actions — then, mid-scoping, asked specifically for `design/mockup/Wiki.dc.html`'s
"Workbench" app-shell variant (icon rail + worklist sidebar + Report/Edit/History/Compare/Patient/Print
tabs), not just the document content. That variant's tabs are client-side state in the mockup —
a direct conflict with CLAUDE.md's "no SPA router" and the "Why not SPA-everything" reasoning
(docs/architecture-api.md §1). Resolved explicitly with the user before writing anything: build the
same visual chrome, but every "tab" is a plain link to a real, already-existing route — see the new
**A5** callout in docs/architecture-api.md. Split into 5 slices (rail, tab strip, worklist sidebar,
theme toggle, status bar), one at a time; this entry is slice 1, wired into `page-view.php` only.

**`assets/fontawesome.css` and `assets/fonts/*.woff2` — sitting untracked and unused all
session — are now wired in, with the user's explicit go-ahead first (asked directly, since adding
an icon-font dependency is exactly what CLAUDE.md's "ask before adding a dependency" covers).**
Moved to `assets/css/fontawesome.css` (matching where `tokens.css`/`wiki.css` already live) and its
`@font-face` fixed from an absolute `/static/fonts/...` path (wrong for this app's actual layout and
for whatever `basePath` it's mounted under) to `../fonts/fa-solid-900.woff2`, relative to the CSS
file's own URL — resolves correctly regardless of mount point, the same reasoning `Request::basePath`
itself exists for. The bundled subset is curated for clinical/demographic icons (mars/venus,
birthday-cake, hospital, x-ray, radiation, stethoscope, id-card...) rather than generic chrome —
strongly suggests it was prepared for the metadata panel this session hasn't built yet, not the
rail. Four rail icons substitute a different glyph than the mockup's Phosphor icon because this
subset has no plus/tag/plug/sliders glyph at all: new-report uses `fa-file-medical`, tags uses
`fa-sticky-note`, integrations uses `fa-sync-alt`, admin uses `fa-hospital`. `templates/rail.php`'s
own docblock has the full mapping. Only `fa-solid-900.woff2` is committed here — the other
untracked font files sitting alongside it (Inter, JetBrains Mono, Space Grotesk weights) have no
`@font-face` anywhere in the linked CSS at all (`--font-body: Inter, system-ui, ...` is a stack
name, not a self-hosted face); they stay untracked until something actually references them,
same "don't commit what nothing uses" reasoning as everywhere else this session.

**`templates/rail.php` is `include`d, not `View::render()`'d** — it shares its caller's
already-extracted scope rather than being handed its own vars, the same relationship
`templates/page-view.php` has to nothing else in this codebase today (there was no shared-partial
precedent to follow; every other template is fully self-contained). `Http\PageTemplateRenderer`
computes `railActive`/`railEditHref` for `page-view.php`, not the template itself — advisor review
flagged the first draft for computing a route (`'/' . $path . '/edit'`) inside the view, the one
place in this codebase that never happens; every other var already comes from a controller.

**Two real bugs, both caught by advisor review before commit:**

1. `body.wk { height: 100vh; overflow: hidden }` was scoped to the pre-existing, previously-inert
   `wk` marker class — which is on `<body>` in *every* signed-in template, not just the one being
   ported. Only `page-view.php` gained the `.wk-body`/`.wk-col` structure that makes anything
   scrollable inside that shell; the other six screens would have had their content silently
   clipped past the viewport with no way to reach it (a long revision list on `history.php`, a long
   account list on `admin-users.php`, both invisible, neither producing an error). Fixed by
   introducing a separate opt-in class, `wk-shell`, added to a template's `<body>` only in the same
   commit that ports it to the rail's grid structure — `assets/css/wiki.css`'s comment states this
   explicitly so the next slice doesn't reintroduce the same gap.
2. The rail's Editor (pencil) icon was gated only on "is there a single current page to point at"
   (`$railEditHref !== null`), not on `canWrite` — so a viewer with no write grant saw a
   live-looking edit link for a page they cannot save, a straight regression from the old Edit
   button's `if ($canWrite)` gate. `PageTemplateRenderer` now sets `railEditHref` to `null` whenever
   `canWrite` is false, not only when there is no page at all.
   `testRailEditorIconIsInertNotLiveForAViewer` (tests/Http/PageViewTest.php) pins this down; it
   was caught by `testEditLinkIsAbsentForAViewer` (tests/Http/EditorTest.php) failing on a full
   suite run — the discriminating case (an existing test, not a new one) is exactly the class of
   check this project's testing agreement asks for before adding a new listing/gate anywhere.

**Remaining for this multi-slice project:** the tab strip (Report/Edit/History/Compare/Patient/Print,
slice 2), the worklist sidebar (slice 3 — `Index\Sqlite` already has every column the mockup's
filters need: modality via `page_modalities`, "mine" via `updated_by`, no schema change required),
the theme toggle (slice 4 — `tokens.css` already has a `.theme-light` variant, nothing switches it
yet; cookie-based, not localStorage, to survive JS being off), and the status bar (slice 5 — the
mockup's version is mostly fictional stats: HL7 order queue, embeddings count, backup schedule,
none of which exist in this app; likely a much smaller real subset or skipped entirely). `namespace.php`,
`editor.php`, `history.php`, `admin-users.php`, `search-results.php`, `new.php` and
`page-delete-confirm.php` still have the old flat `.wk-top` chrome, not the rail — a known,
temporary, and expected state while this ships one template at a time, not a regression.

## feat: document tab strip — chrome slice 2/5 (page-view, editor, history)

The user approved slice 1 and asked to continue straight through the remaining slices, with one
explicit instruction: drop the standalone Edit/History buttons once the tab strip covers them
("stick to the design"). Scoped wider than a literal "just the tab strip" reading: the tab strip
only makes sense across all three real destinations (Report/Edit/History) at once — shipping it on
`page-view.php` alone would mean clicking "Edit" lands on a screen with no tab strip to click back
with. So this slice ports rail + tabs to `page-view.php`, `editor.php` and `history.php` together,
not just the CSS on one screen.

**`Http\ChromeVars::forPath()` extracted** once `Controller\EditorController` and
`Controller\HistoryController` needed the identical isOwner/canCreate/canWrite/railEditHref
computation `Http\PageTemplateRenderer` already had for `Controller\PageController` — one formula,
three callers, instead of three copies drifting apart the moment one of them gets a fix the others
don't. `templates/tabs.php` reuses the same `railEditHref` value `templates/rail.php` does for its
Edit tab: one gate, two places it renders, verified with its own test
(`testEditTabIsInertNotLiveForAViewer`) rather than assumed to be covered by the rail's equivalent
test.

**Dropped, on the user's explicit instruction, now redundant with the tab strip's Report tab:**
`page-view.php`'s standalone Edit and History buttons (kebab/Delete menu stays — no tab represents
it), `editor.php`'s Cancel link, `history.php`'s "Back to page" button. Worth stating plainly since
it's a real behavior change hiding in a chrome refactor: Cancel was the *discard* affordance, and
the Report tab does the same discard-by-navigating-away — but the label no longer says so. Not a
regression (Cancel never warned either), just a wording change a future reader could otherwise
mistake for an accidental removal.

**One real bug, caught by advisor review before commit: `editor.php`'s Save button could end up
past the viewport with nothing scrollable to reach it.** `body.wk-shell`'s `overflow: hidden` (slice
1) is fine for `page-view.php`/`history.php`, whose content is normal block flow inside
`overflow: auto` `.wk-col`. `editor.php`'s `.wk-edit` is a flex column relying on `flex: 1` against a
bounded-height ancestor — `.wk-col` alone gives it nothing to flex against. Fixed by porting three
`.wk-col:has(.wk-edit)` rules verbatim from `Wiki.dc.html` (the mockup uses `:has()` itself; not
introduced here) that turn `.wk-col` and its children into the right flex chain when a document
editor is present. **One necessary deviation from the verbatim mockup CSS**: the mockup's own
exclusion list only carries `.wk-dtabs` because Bench's real layout has no top bar at all — this
app keeps `.wk-top` for the working palette search the mockup doesn't have, so it needed its own
`flex: none` exclusion the mockup never had to write. Not verified in an actual browser (no browser
access in this environment) — reasoned through the selector chain and the pre-existing `.wk-edit`/
`.wk-edit-main`/`.wk-ta` flex rules; worth a real-browser check on a short window before trusting it
further.

**Remaining for this multi-slice project:** worklist sidebar (slice 3), theme toggle (slice 4),
status bar (slice 5). `namespace.php`, `admin-users.php`, `search-results.php`, `new.php` and
`page-delete-confirm.php` still have the old flat `.wk-top` chrome — none of them have a natural
tab-strip destination the way view/edit/history do, so they'll likely get the rail only, no tabs,
in whatever future slice ports them (not part of the original 5-slice plan, which only covers the
document screens and worklist/theme/status chrome — porting the remaining five templates is a
separate follow-up to name explicitly when it comes up, not silently assumed into slice 3+).

## feat: worklist sidebar — chrome slice 3/5 (page-view, editor, history)

`Index\Sqlite::listWorklist(string $ns, ?User $principal, int $limit = 20)` — the same rows and
visibility predicate as `listNamespace()` (D29's `page_modalities`/`updated_by` columns already
covered whatever a future filter needs; no schema change), ordered most-recently-updated first
instead of alphabetically, capturing the worklist's "what's active here right now" framing versus
the namespace index's "browse everything" framing. `templates/worklist.php` renders it as the
Bench layout's middle grid column — `.wk-body-worklist` (52px/336px/1fr), a second opt-in modifier
alongside `wk-shell`/`wk-body`, so a template with the rail but no single page's namespace to scope
a worklist to (`/new`, `/search`) can stay 2-column even once ported.

**`Http\ChromeVars::worklist()` follows the same one-formula-three-callers pattern as
`forPath()`** — the namespace is derived from `$path` the same way
`PageController::delete()`'s post-delete redirect already does (segments minus the last one), and
an empty namespace (a genuinely top-level page with no colon) works with no special-casing:
`listWorklist('', $principal)` just matches pages whose own `ns` is empty, the same as any other
namespace string. This method needs `IndexInterface`, which `Http\PageTemplateRenderer` didn't
have before this slice — added to its constructor, `Kernel::boot()`'s only call site updated.

**Still not verified in an actual browser (no browser access in this environment) — now carrying
three slices of unverified flex/grid layout, not just slice 2's.** `bin/reporion serve` +
`/{path}/edit` at a normal window size would confirm: Save is reachable, the new 336px worklist
column doesn't squeeze the editor textarea unusably, and the `@media (max-width: 980px)` collapse
actually hides the sidebar. Reasoned through the CSS chain and confirmed the markup/class wiring by
rendering real HTTP responses, but neither replaces an actual layout check — flagged explicitly
again rather than let the gap go quiet after two slices of repeating the same disclosure.

**Filter chips (modality/date-range/"mine" in the mockup) are not built** — this slice ships the
plain listing only. Same reasoning as every other "ship the real subset, name the gap" slice this
session: the chips would need query-param-driven links (no client-side state, per A5) and a
decision about which of the three is worth the first cut; deferred rather than guessed at.
Visibility icons (lock/link in the mockup) aren't ported either — no matching glyph in the
Font Awesome subset this app loads (`templates/rail.php`'s docblock has the same constraint) — a
plain `.wk-vis` text marker shows the visibility word instead, only for non-public pages.

**Performance measured, not assumed, per advisor review**: `listWorklist()`'s `ORDER BY updated
DESC LIMIT :limit` has no covering index (`pages_ns` covers the `WHERE ns = ?` half only) —
`EXPLAIN QUERY PLAN` confirms `USE TEMP B-TREE FOR ORDER BY`. Seeded 4 000 pages into one namespace
(the scale D-something's import plan expects) and measured 0.92ms average per call — comfortably
inside the <50ms page-view budget with room for everything else a request does. No index added;
adding one would be an index-schema change (CLAUDE.md: ask before), and the measurement says
there's nothing to justify asking for yet. Revisit if a real install's per-namespace page count
ever gets an order of magnitude larger than 4 000.

**A real bug in the test, not the code, caught while writing the ordering test**: the shared
`pidsFrom()` helper in `tests/Visibility/VisibilityMatrixTest.php` sorts its result alphabetically
— correct for every existing set-equality assertion in that file, but it silently turned
`['p-newest', 'p-new', 'p-old']` into `['p-new', 'p-newest', 'p-old']` for the one test that needed
order preserved, and a first draft nearly chased that as an index bug before noticing the helper
itself was the wrong tool for this assertion. `testWorklistOrdersMostRecentlyUpdatedFirstAndRespectsLimit`
extracts pids directly instead of reusing `pidsFrom()`.
