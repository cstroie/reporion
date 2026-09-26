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

**Layout: Shell B — Reading room, without its floating dock** (`.wk-b-read`, decided 2026-09-25,
replacing the earlier Workbench choice). Two layers:

- **Top nav (site-wide)** — slim bar: ☰ (namespace drawer), search showing the current path (⌘K
  palette), **+ New** (only for users with write access somewhere), **Namespace index**,
  **Admin** (owners only), theme toggle, palette picker, account. The ☰ drawer slides over with the
  current namespace's worklist and namespace links; without JS it is a plain link to `/{ns}:`.
- **Page header (page-specific)** — shared by every route of one page (`/{path}`, `/edit`,
  `/history`, `/compare`, `/timeline`, `/delete`), at the top of the centred reading column
  (`.wk-panes[data-pad="read"]`, max 920px): crumbs + copy-id, title, badges (`.wk-doc-head` from
  `WikiPage`), then a page-local tab row — **Report · Edit · History · Compare · Patient** — with
  the **⋯** menu (revert, delete) on the right. Each tab is a plain link to its route; the active
  one is underlined. **Export ▾** (print preview, PDF) sits beside ⋯ for every reader. More join
  the row once their backends exist, not before: **✨ Assistant** (when an AI provider is
  enabled, D15), ODT / Markdown export, and ⋯ rename / move / duplicate (phase 4).

The mockup's `.wk-dock` is **not** built. No list column, no Workbench tab strip above the
document, no status bar.

**Palettes: Shell C's, not Shell B's.** Reading room's own warm palette is rejected. The three
Workbench highlight palettes carry over verbatim from `Wiki.dc.html`
(`.wk[data-variant="bench"][data-bpal="…"]`, dark + light each), renamed and ranked:

| Product name | Mockup name | `bpal` |
|---|---|---|
| **Royal blue** (default) | ink + signal blue | `azure` |
| **Lime** | slate + lime | `lime` |
| **Amber** | graphite + amber | `amber` |

The palette is a per-user display preference (cookie, like the theme toggle); anonymous and new
users get royal blue. Teal and rose are not offered.

When porting a screen, read the mockup with `variant=read` for layout and `variant=bench` +
`benchPalette` for colour.

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
| `WikiAdmin` — "Users & groups", "Index & storage" and Trash tabs | `GET/POST /admin/users`, `POST /admin/users/{username}/deactivate\|reactivate\|profile\|password`, `GET /admin/index`, `POST /admin/index/rebuild`, `GET /admin/trash`, `POST /admin/trash/{pid}/restore` | **SSR, not island** — each tab its own route with a shared tab row (`templates/admin-tabs.php`); site settings and plugins tabs aren't built |
| `WikiProfile` | `GET /profile`, `POST /profile/password` | **SSR, not island** — own account (grants, signature details read-only) and own password change; no 2FA or API tokens (D35) |
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
- **Built as plain SSR forms, not the mockup's island.** The mockup's `WikiAdmin` is one tabbed
  island across five panels; the users panel shipped as its own `/admin/users` page — classic
  POST + redirect, no JavaScript — because this project's own SSR-vs-island rule ("survive
  JavaScript being broken... it is server-rendered") fits a rarely-used admin form better than an
  island does, and standing up an island-mounting subsystem for one CRUD screen would be a lot of
  new infrastructure for what this needs. Revisit if/when the other four tabs get built and an
  island actually starts paying for itself.

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
