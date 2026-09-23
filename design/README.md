# design/ — the visual contract

The HTML mockup in this folder is the reference for every screen. **Match its markup, class
names and tokens rather than reinventing layout.** If a screen needs to differ, change the
mockup first so the two never drift.

### How to view it

`mockup/` holds the source of the interactive mockup. It was authored in a design tool and its
`.dc.html` files need that tool's runtime — **they do not open standalone in a browser**. Two
ways to use them:

- **Read them as markup.** `WikiPage.dc.html`, `WikiEditor.dc.html` etc. are plain HTML
  fragments plus inline styles; `Wiki.dc.html` holds the shell and the whole stylesheet. That is
  the layout and class-name reference, and it is all you need to build the templates.
- **View it rendered** in the design project, or ask for a static HTML export.

The bar at the top of the rendered mockup switches shell proposal, screen, theme and palette —
it is a mockup control, not a product feature.

## Chosen direction

**Shell C — Workbench**: icon rail, list column, document pane with a tab strip.
**Palette**: ink + signal blue (dark) / cool grey-white (light). Tokens in `tokens.css`.

## Screen → route map

| Mockup pane | Route | Kind |
|---|---|---|
| `WikiAuth` | `GET/POST /login` | SSR — username field is back (D35; it had been dropped for the single-owner design, see below) |
| `WikiPublic` | `GET /` (anonymous), `GET /{public-path}`, `GET /s/{token}` | SSR, `layout-public.php` |
| `WikiPage` | `GET /{path}`, `GET /{path}@{rev}`, `GET /r/{pid}/{rev}` | SSR |
| `WikiEditor` | `GET /{path}/edit` | island (marked.js preview, IndexedDB draft) |
| `WikiHistory` | `GET /{path}/history` | SSR (diff computed server-side) |
| `WikiCompare` | `GET /{path}/compare?with=` | SSR + async AI delta |
| `WikiNsIndex` | `GET /{ns}:` | SSR + bulk-action island |
| `WikiWorklist` | `GET /` (owner dashboard) | SSR shell + filter island |
| `WikiCreate` | `GET /new` | island |
| `WikiSearch` | `GET /search?q=` | SSR first page + facet island |
| `WikiPalette` | — (overlay on every page) | global island → `/api/v1/search/suggest` |
| `WikiTimeline` | `GET /patient/{key}` | SSR |
| `WikiPrint` | `GET /{path}/print`, `/export/{path}.pdf` | SSR, `templates/print/report.php` |
| `WikiAdmin` | `GET /admin/*`, `GET/POST/PATCH /api/v1/users` | island — the mockup's users/grants panel is back in scope (D35–D37, see below); build it against per-namespace grants, not the mockup's `@radiology:rw` ACL strings |
| `WikiProfile` | `GET /admin/profile` | island |
| `WikiTokens` | `GET /admin/integrations` | island — **API tokens table still dropped**; no machine clients exist yet (`docs/architecture-api.md` §"JSON API"). Keep only the AI endpoint + provider status |
| `WikiTags` | `GET /admin/tags` | island |
| `WikiErrors` | 404 / 410 / 401 / 409 / empty-namespace states | SSR |

## Users/groups/ACL: back in scope, but not as drawn

D6 and D13 (single user, `visibility` only, no ACL) are superseded by D35–D37 — Reporion is
multi-user now, with per-namespace `editor`/`viewer` grants. That means the mockup's
users/groups/ACL panel is closer to the real product than any other dropped pane. Build it
against the actual model, not the mockup's syntax:

- No `@radiology:rw`-style ACL strings — a grant is `{namespace, role}` (`role` ∈ `editor|viewer`),
  matched by namespace **prefix**, not a group name.
- No "groups" as a separate concept — `owner` is instance-wide; everyone else is a set of
  namespace grants directly on their account (`data/users/{username}.json`).
- Self-service registration is still out: accounts are admin-created only (D35). If the mockup
  shows an invite/signup flow, that part still doesn't get built.

## Not in the product

The mockup shows a number of things the decisions removed. Leave them out:

| In the mockup | Why it is gone |
|---|---|
| TOTP / 2FA fields on sign-in | D35 — still no 2FA in the multi-user design |
| API tokens table | No machine clients exist yet (`docs/architecture-api.md` §"JSON API") |
| HL7 order bridge, DICOM SR export, C-GET | later plugins; not core |
| Dictation button | D24 |
| Measurement macros (`@measure(12 mm)`) | D18 — prose only, and D17 pins the dialect to plain CommonMark |
| Resident / review queue states | D37 — whoever holds the write grant signs their own work, still no review step |
| `amended` status badge | D3 — correction is a new signed revision, status stays `signed` |

The panes are kept in the mockup so the layouts stay comparable — but do not build these.

## Print is different

`WikiPrint` shows the intended sheet, but the real template renders through **dompdf**, which
has no flexbox or grid. Use `templates/print/report.php` + `assets/css/print.css` as the
implementation and the mockup only as the visual target.
