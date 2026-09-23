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
