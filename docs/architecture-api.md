# API & page surface

Which URLs render HTML on the server, which are JavaScript islands, and the JSON contract underneath both.

*draft 3 · 23 Sep 2026 · companion to architecture-storage-index.md · multi-user, small-team deployment (D35–D37)*

Two surfaces, one service layer. HTML routes render documents; the JSON API under `/api/v1` serves the interactive parts and every non-browser client. Neither touches storage directly — both call the same services, so there is exactly one implementation of "save a report" in the codebase.

## 1. The split, and the rule that decides it

A route renders on the server if its job is to **show a document**. It becomes a JavaScript island if its job is to **manipulate state faster than a round trip allows**. Everything follows from that, and the test is blunt: if it should survive JavaScript being broken, print correctly, and deep-link, it is server-rendered.

| Route | Kind | Why |
|---|---|---|
| `/` | SSR + island | context-dependent: anonymous gets the public landing page, a signed-in user gets the dashboard (§6) |
| `/{path}` | SSR | the report. First paint is the document, no bundle in the way |
| `/{path}@{rev}` | SSR | a specific revision, rendered from its own bytes |
| `/r/{pid}/{rev}` | SSR | citable permalink for exports (survives renames — pid, not path) |
| `/{ns}:` | SSR, not island | `Controller\NamespaceController`: sub-namespace cards (`Index\Sqlite::listSubnamespaces()`) and a plain pages table (`listNamespace()`), both visibility-filtered before the query (invariant 6); a namespace with nothing visible to the caller 404s the same as one that does not exist (invariant 9). Not built: bulk select/move/tag/export/visibility, "recent activity" (no audit log yet), and a namespace-description panel (would need an `_index` page convention). Renders through the same internal chrome as `page-view.php` for every caller, anonymous included — it does **not** yet switch to `layout-public.php` the way `PageController::view()` does, so an anonymous visitor sees the search palette and internal branding on a public namespace's index; only the listing predicate is enforced. The mockup's persistent tree sidebar (`WikiTree.dc.html`) is a separate, chrome-level concern, not part of this route |
| `/{path}/history` | SSR | revision list + unified diff, both computed server-side. **Built**: `Controller\HistoryController`. Diff is "vs current" per row (a plain `?from=&to=` link, no JS), not an arbitrary-pair compare — the mockup's radio-multiselect "compare selected" isn't built (see below) |
| `/{path}/compare?with=` | SSR | two reports side by side; the AI delta arrives by API afterwards |
| `/{path}/print` | SSR | print stylesheet, letterhead, QR. Must work with JS disabled |
| `/patient/{key}` | SSR | timeline; a document about a person, not an app |
| `/search?q=` | SSR + island | first result page rendered so the URL is shareable; facets then live |
| `/{path}/edit` | **SSR, not island** — `Controller\EditorController`, a single-textarea raw-document form (no autosave/preview/AI rail/JS conflict UI — see below and docs/BUILD_LOG.md) |
| `/new` | **SSR, not island** — `Controller\NewPageController`, a plain text path field + the same raw-document textarea as `/{path}/edit`. The mockup's segmented `reports:{modality}:{site}:{yymmdd}-{name}` builder with live index validation is not built |
| `GET /{path}/delete` | **SSR, not in the mockup** — a confirmation step, `Controller\PageController::confirmDelete()`. Added because deletion has no restore UI anywhere in the app (unlike revert/deactivate, which stay reversible from inside it): the only way back is `data/trash/` on disk until `trash:purge` runs, so a stray click reaching this route must not delete anything on its own |
| `POST /{path}/delete` | **SSR, not island** — `Controller\PageController::delete()`, the one live item in the page-view kebab menu (a native `<details>`/`<summary>` disclosure, no JavaScript). Soft delete only (`Storage\FlatFile::delete()` moves the page to trash, invariant 1). `DELETE /api/v1/pages/{path}` (`Controller\PagesApiController::delete()`) still exists unchanged — same `Storage::delete()`, two routes: one for browsers, which cannot submit a form with method DELETE, one for the JSON API |
| `/admin/*` | island | settings, tags, plugins, index — dense forms, rarely used, no print need |
| `/login`, `/logout` | SSR | a form. Nothing else |
| `/s/{token}` | SSR | share link for an unlisted page, expiry enforced server-side |
| `/export/{path}.{fmt}` | SSR | streams a file; `fmt` ∈ pdf, odt, md |
| `/sitemap.xml`, `/feed.atom` | SSR | public pages only |

*Table 1 — route surface. SSR = server-rendered HTML; island = SSR shell with a mounted JS app inside.*

> **A1 — the palette is the one global island** — ⌘K mounts on every page, SSR or not: a small script, no framework, that calls `/api/v1/search` and navigates. It is the only JavaScript on an otherwise static report page, and it degrades to a plain `/search` form link if it fails to load.

> ⚠︎ **Why not SPA-everything.** Three concrete costs in this app: the signed PDF must come from server-rendered HTML (§5 of the storage & index spec), a locked-down hospital browser must still open a shared report, and a report you open twenty times a day should paint in one request. The interactive screens lose nothing by being islands — they get full client state where it matters.

## 2. Request lifecycle

```
public/index.php
  → Kernel::boot()            config, container, plugin discovery
  → Session::resolve()        principal (user | anonymous)
  → Router::match()           html routes | /api/v1 routes
  → [plugins] request.start
  → Controller                calls SERVICES only — never Storage directly
       Pages, Revisions, Render, Search, Export, Patients, Index, Ai
  → [plugins] response.send
  → Response                  HTML (Template) | JSON (ApiResponse) | Stream
```

No framework. A router, a container, a template function and a JSON responder are a few hundred lines total, and they are the part you will never want to fight. Composer is used for libraries (markdown parser, YAML, ODT writer, PDF engine), not for a stack.

### Runtime, concretely

lighttpd + PHP-FPM on a personal server, TLS terminated upstream, PHP floor **8.1** (D23). lighttpd has no `.htaccess`, so two things are configuration, not code:

```
# lighttpd.conf — document root is public/ ONLY
server.document-root = "/srv/reporion/public"
url.rewrite-if-not-file = ( "^/(.*)$" => "/index.php/$1" )
# data/, conf/, plugins/ live at /srv/reporion/* — outside the docroot, unreachable by URL
```

`bin/reporion doctor` asserts both at install time: that `data/` is not fetchable over HTTP, and that the rewrite reaches the front controller. A misconfigured docroot on this app means patient reports served as plain text, so it is checked rather than documented.

### Auth, multiple accounts (D35–D37)

`POST /login` with a `username` + password (argon2id, verified against `data/users/{username}.json`) sets a signed, HTTP-only, SameSite=Lax session cookie with a long lifetime. Anonymous requests are first-class: they resolve to a read-only context that can see `public` pages, plus `unlisted` ones reached by exact path or share token. There is **no registration route** — accounts are created by an `owner` only, via `bin/reporion user:create` or the admin screen, never self-service.

A signed-in session resolves to a principal carrying `username`, `role` (`owner`, or the set of per-namespace `editor`/`viewer` grants — see `docs/architecture-storage-index.md` §"Visibility inside the query"). Every write route requires, at minimum, an `editor` (or `owner`) grant covering the target page's namespace — not just "signed in".

```
GET  /login                  form
POST /login                  { username, password }     → 302 /
POST /logout                                             → 302 /login
GET  /api/v1/whoami           → { username: string|null, owner: bool, grants: [{namespace, role}] }
```

Owner-only, instance administration (namespaces and grants are per-page-write concerns handled through the normal page routes; this is account management). **Built as plain SSR routes, not this JSON shape** — `GET/POST /admin/users` and `POST /admin/users/{username}/deactivate|reactivate` (`Controller\AdminUsersController`), classic form POST + redirect, no JavaScript. Reasoning: this project's own SSR-vs-island rule ("survive JavaScript being broken... it is server-rendered") fits a rarely-used admin form better than the mockup's tabbed-island design, and stub JSON endpoints with no caller would be exactly the kind of speculative surface CLAUDE.md's working agreement says not to add. The JSON shape below stays documented as the *eventual* machine-callable surface, for whenever a non-browser caller needs it — not built yet:

```
GET    /api/v1/users              list accounts (owner only)
POST   /api/v1/users              create account { username, password, role? } (owner only)
PATCH  /api/v1/users/{username}   change role, grants, or disable (owner only)
DELETE /api/v1/users/{username}   deactivate — accounts are never hard-deleted, disk stays the audit trail
```

## 3. JSON API

Versioned, JSON in and out, `Idempotency-Key` honoured on writes. **No API tokens exist yet**: the CLI and the importer run locally and call the services directly, not over HTTP, and every HTTP caller is either an authenticated user session or anonymous — so there is no separate machine-client identity. If a machine client is ever needed, that is when a token table earns its keep. Collection responses are `{ data: […], page: {…} }`; errors are `{ error: { code, message, fields? } }` with real HTTP statuses.

#### Pages

| Method & path | Does |
|---|---|
| `GET /pages` | list/filter by ns, modality, region, site, status, date — the worklist and namespace index |
| `POST /pages` | create; body `{ path, template?, meta, body? }` → 201 + pid. Requires `editor`/`owner` on `path`'s namespace — checked once `path` is known to be well-formed, not before (a caller with no write access anywhere gets a flat 404 regardless of body content; a real writer whose grant just doesn't cover this namespace still gets 404, not 422, once `path` itself is valid) |
| `GET /pages/{path}` | frontmatter + raw markdown + rendered HTML (`?render=0` to skip) |
| `PUT /pages/{path}` | save revision; requires `base_rev`; 409 on conflict with both bodies. Requires `editor`/`owner` on `path`'s namespace |
| `PATCH /pages/{path}/meta` | frontmatter only — tags, visibility, CNP — without a body edit. Will require `editor`/`owner` on `path`'s namespace, same as `PUT`, once built |
| `POST /pages/{path}/sign` | **built** (`PagesApiController::sign()`). `{ parafa? }` → 200 with the signed record, or `422 { error: { code: 'incomplete', fields: { missing: [...dotted field names] } } }` if a `required`/`required_for: ["sign"]` field is empty. D37: signing follows the write grant — whoever holds `editor`/`owner` on `path`'s namespace signs it as themselves, same 404-not-403 rule as every other write; idempotent per revision — signing an already-signed revision again is a no-op, not a second signature |
| `POST /pages/{path}/move` | `{ to }` — rewrites location, leaves redirect stub, fixes inbound links |
| `POST /pages/{path}/duplicate` | `{ to, keep_meta[] }` — the "new report like this one" path |
| `DELETE /pages/{path}` | soft delete → `trash/`; requires `editor`/`owner` on `path`'s namespace. `?purge=1` is **owner-only** (D3b: a stricter bar than ordinary delete) and audited |
| `POST /pages/{path}/restore` | from trash |
| `POST /pages/{path}/share` | mint/revoke a share token with expiry for an unlisted page |

*Table 2 — page endpoints. `{path}` is the colon path, URL-encoded; `{pid}` also accepted everywhere a path is.*

#### Revisions

`POST /api/v1/pages/{path}/revert { to: 6 }` is **built** (`PagesApiController::revert()`),
same `editor`/`owner` namespace-grant authorization as the rest of the Pages surface. The SSR
history page (`GET /{path}/history`) also has its own `POST /{path}/history/revert` form action —
a classic form POST, not a call to this JSON endpoint, same split as the admin screen's SSR
actions vs. the (also unbuilt) `/api/v1/users` JSON shape. The remaining three shapes below are
**not built** — nothing reads `GET /{path}/history` through a JSON contract yet, since the SSR
page computes its own revision list and diff server-side without one:

```
GET  /pages/{path}/revisions                 list (from meta.json, index-backed)
GET  /pages/{path}/revisions/{n}             raw bytes of that revision
GET  /pages/{path}/diff?from=6&to=7          unified | side-by-side | rendered
```

> **A2 — revert is a forward operation** — Restoring revision 6 writes revision 8 whose content equals 6, byte for byte — not re-encoded, not renormalised. History never loses a step and never rewrites one. `revlog[].kind` records `revert`, distinct from `edit`. Status is never carried forward: reverting to an old `signed` revision produces a fresh `draft` (unless the page is `archived`), because the new revision has no signature record of its own yet — it needs signing again, in its own right, same as any other edit (D3). The mockup's "restore" buttons all mean this.

#### Render — two parsers, one dialect

```
POST /render     { markdown, path? }  → { html, toc, warnings }
```

Any signed-in user can call this — not owner-only, unlike the rest of the Pages surface — because it
compiles caller-supplied markdown with no page lookup: there is no namespace grant to check, and a
viewer previewing a print rendition needs it exactly as much as an editor previewing a draft does.
Still 404, not the rendered output, for an anonymous caller (invariant 9: this route's existence is
not information worth confirming to someone who can't use it either way).

Canonical rendering — page view, print, PDF, ODT, index — is PHP. The editor's live preview runs **marked.js** in the browser instead, because a keystroke-latency preview is worth more than a round trip (D17). Two parsers is a genuine risk, contained by three rules:

- The dialect is **generic CommonMark plus tables**. No footnotes, no custom macros, no HTML passthrough — precisely the subset where marked.js and a PHP CommonMark implementation already agree.

- `tests/RenderConformanceTest` renders the CommonMark spec fixtures *and* a corpus of real reports through both parsers and fails on any difference in normalised HTML. Adding a syntax extension means making that test pass in both, or not adding it.

- The preview is labelled as a preview. The document you sign is the server's HTML, and `/{path}` is what the print and export paths render from.

> ⚠︎ **If the conformance test ever becomes hard to keep green** , that is the signal to drop marked.js and switch the preview to debounced `POST /render` — a two-line change in the editor island, which is why the endpoint stays in the API from day one.

#### Search

```
GET  /search?q=&mod=&region=&site=&device=&status=&tag=&from=&to=
              &mode=fts|vector|hybrid&sort=rank|date&limit=&cursor=
  → { data: [ { pid, path, title, snippet, score, meta } ], facets: {…}, took_ms }
GET  /search/suggest?q=                      palette typeahead: paths, tags, commands
POST /search/saved                           { name, query }
GET  /search/saved
POST /search/ask        { q, filters }       grounded answer over the result set
                                             → { answer, citations[], model }
```

`facets` comes back with every query so the sidebar counts are always consistent with the result set — computed in the same SQLite round trip, not a second call.

#### Patients, templates, tags, media

```
GET  /patients/{key}                 timeline: studies, modalities, sites, priors
GET  /patients/{key}/candidates      near-key possible matches (D11)
POST /patients/merge                 { keys: [a, b] } → writes conf/patient_merges.json

GET  /templates                      list, with usage counts
GET  /templates/{path}               sections + default frontmatter
POST /templates                      save current page as a template

GET  /tags                           with counts, synonyms, ICD-10
POST /tags/{tag}/rename              rewrites frontmatter on N pages + reindex
POST /tags/merge                     { from: […], into }

POST /media                          multipart → { sha256, url, w, h }
GET  /media/{sha}                    content-addressed, immutable, long cache
```

#### Export, index, AI, system

```
POST /export/{path}      { fmt: pdf|odt|md, letterhead, options } → job or stream
POST /export/bulk        { query | pids[], fmt }                  → zip (plugin)

GET  /index/status       counts, drift, last verify/rebuild, schema_version
POST /index/verify       cheap stat pass
POST /index/rebuild      full rebuild (async job)
GET  /jobs/{id}          progress for rebuild, bulk export, import batches

POST /ai/complete        { task, path?, context_opts } → stream (SSE)
GET  /ai/providers       configured providers; empty by default (D15)

GET  /settings           site settings
PUT  /settings
GET  /plugins            registry: version, hooks, enabled
POST /plugins/{id}/toggle
GET  /integrations       AI endpoint + provider status (no tokens — D13)
PUT  /integrations
GET  /audit?from=&to=&action=
```

> **A3 — AI responses stream, and nothing is written implicitly** — `/ai/complete` returns Server-Sent Events so the assistant rail fills progressively. It never writes to the page. Insertion is an ordinary `PUT /pages/{path}` from the editor, attributed to `assistant` in the revision note — so the audit trail distinguishes "the machine wrote this" from "a doctor accepted it".

## 4. What each island actually needs

| Island | Endpoints it uses | Local state it owns |
|---|---|---|
| editor | `PUT /pages`, `/media`, `/templates`, `POST /sign`, `/ai/complete` (when enabled) | buffer, dirty flag, autosave queue, IndexedDB draft, conflict state, marked.js preview |
| palette | `/search/suggest`, `/search` | query, selection, recent |
| search | `/search`, `/search/saved`, `/search/ask` | filters (mirrored into the URL), cursor |
| worklist | `GET /pages` | filter chips, selection for bulk actions |
| admin | `/settings`, `/plugins`, `/tags`, `/index/*`, `/jobs` | form dirty state, job polling |

*Table 3 — island scope. Keeping these lists short is how the frontend stays small.*

> ⚠︎ **The editor is the one that must survive a dropped VPN (D12).** Every keystroke goes to an IndexedDB draft keyed by pid + base_rev; autosave to the server is best-effort and retried. On reconnect it either saves cleanly or raises the 409 conflict flow. This is the single most important piece of client-side engineering in the project — everything else can afford to fail by showing an error.

## 5. Plugin loader

A plugin is a directory with a manifest and a class. Discovery is a filesystem scan at boot, cached; enabling is config, not code.

```
plugins/export-pdf-letterhead/
  plugin.json     { id, name, version, api: 1, hooks: ["export.pdf"], settings: {…} }
  Plugin.php      final class Plugin implements PluginInterface {
                    public function register(Hooks $h, Container $c): void {
                      $h->on('export.pdf', [$this, 'render'], priority: 10);
                    }
                  }
  templates/, assets/
```

Hooks are typed events with a defined contract each: `page.validate`, `page.save`, `page.render`, `page.sign`, `index.fields`, `search.query`, `export.<fmt>`, `ai.context`, `ai.complete`, `auth.login`, `cron`, `request.start`, `response.send`. A plugin receives services from the container and **never** the filesystem or the PDO handle — that is what keeps history uncorruptible (D9).

Plugins may add API routes under their own namespace (`/api/v1/x/{plugin-id}/…`) and mount their own island assets, so a feature can be end-to-end pluggable without core edits. Frontend extension point: a plugin can register a toolbar button, an assistant action, a page-action menu item, or an admin panel — four slots, declared in the manifest.

## 6. The public site

One instance serves two audiences from the same pages. A signed-in user sees an application, scoped to whatever their grants cover; an anonymous visitor sees a small public website — a landing page, a handful of hand-picked documents, and nothing else.

### The landing page is a normal page

`site:home` is an ordinary wiki page with `visibility: public`, edited in the same editor as a report. `GET /` resolves by context:

```
GET /
  signed in  → dashboard (recent, drafts, order queue, index health — index health is owner-only)
  anonymous  → render page `site:home`   (falls back to a built-in stub if absent)
```

That keeps the public face editable without a second templating system, and it means an about page, a contact page, a publications list or a teaching index are all just pages in a `site:` namespace. Anyone with a write grant on `site:` previews the public face at `/?as=public`.

> **A4 — public chrome is a different template, not a different app** — A public request renders the same page HTML inside `templates/layout-public.php`: no palette, no worklist, no page actions, no namespace tree — a title, the document, a footer. The document body markup is identical, so the reader view cannot drift from the report view. The mockup's "public page" screen is this layout.

### Public surface, complete

| Route | Serves |
|---|---|
| `/` | `site:home` |
| `/{path}` | the page if `public`; if `unlisted`, only with the exact path |
| `/s/{token}` | an `unlisted` page via share token, expiry enforced server-side |
| `/{ns}:` | namespace index — public pages only; the listing predicate is enforced, but the page still renders in the internal chrome, not `layout-public.php` (see Table 1's `/{ns}:` row — a named gap, not yet the "two audiences" split) |
| `/search?q=` | public pages only (same predicate); switchable off in settings |
| `/api/v1/search?q=` | **built.** The palette's JSON endpoint (A1) — same `Index::search()` call, same predicate, as anonymous-reachable as `/search` itself and no more. Wired into `search-results.php` only; `layout-public.php` (the single-page anonymous reader, A4) still has no search bar to enhance |
| `/export/{path}.{fmt}` | pdf / odt / md of a public page, if `allow_public_export` |
| `/feed.atom`, `/sitemap.xml` | public pages only |
| `/login` | the form |

*Table 4 — every route reachable without signing in. Everything else is 404, never 403 — a 403 would confirm the page exists.*

> ⚠︎ **Flipping a report to public is a deliberate, noisy act.** `PATCH /pages/{path}/meta` with `visibility: public` first returns a confirmation payload listing exactly what will become visible — patient fields present in frontmatter, attached media, inbound links from private pages — and the UI requires an explicit acknowledgement. The change is audited. For teaching cases the right move is usually `duplicate → pseudonymise → publish the copy`: `POST /pages/{path}/duplicate` takes `pseudonymise: true`, which strips `patient` and rewrites the slug.

### Caching

Public pages are the one place a disk cache carries no risk: render to `data/cache/public/{pid}.{rev}.html` on save, serve with `ETag` and a long `Cache-Control`, invalidate by writing a new revision. Private pages are never cached to disk. If the site is ever exposed beyond the VPN, this also keeps a crawler from touching PHP at all.

## 7. Build order

- `GET /pages/{path}` + the SSR page view. One endpoint, one template, real data on screen.

- `POST /render`, then the print route and PDF/ODT from the same HTML.

- `site:home` + the public layout — early, because it is trivial once the page view exists and it settles the two-audience question before more routes accumulate.

- `GET /search` + palette, once the index is trustworthy.

- `PUT /pages`, `POST /pages`, then the editor island with autosave and conflict handling.

- History, diff, revert, sign.

- Admin, tags, index management.

- Importer against the real archive — before any further screens.

- AI endpoints last, behind the provider interface that ships disabled.

Each step is independently useful: after step 2 the system already replaces a folder of Word documents, which is the point at which you will start finding out what the design got wrong.
