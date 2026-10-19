# Design rules

Where the interface decisions are collected, one place, so a new screen follows them instead of
rediscovering them. It does not replace the sources: the mockup (`design/`) is the visual contract,
`docs/ui-notices.md` details the notice card, `docs/DECISIONS.md` holds the product decisions. Each
rule here says where it came from. **Add a rule when the owner decides one** (date it), and fix
the rule, not just the screen, when the two disagree.

Sections: [Principles](#principles) · [Layout and navigation](#layout-and-navigation) · [Page head](#page-head) ·
[Panels](#panels-wk-panel) · [Action rows](#action-rows-wk-actions) · [Forms](#forms) · [Notices and messages](#notices-and-messages) · [Buttons](#buttons) ·
[Checkboxes and radio buttons](#checkboxes-and-radio-buttons) · [Switches](#switches) · [Groups](#groups-group-group-fill-group-stack) ·
[Badges and status](#badges-and-status) · [Breakpoints and containers](#breakpoints-and-containers) · [Tables and small screens](#tables-and-small-screens) ·
[Colour, themes and palettes](#colour-themes-and-palettes) · [Icons](#icons) · [Tags, chips and counts](#tags-chips-and-counts) · [Typography and utilities](#typography-and-utilities) · [States](#states) ·
[Text and language](#text-and-language) · [Reading text](#reading-text) · [Print and export](#print-and-export) ·
[Left out on purpose](#left-out-on-purpose)

## Principles

- **The mockup is the visual contract.** Match its markup, class names and tokens rather than
  reinventing a layout; if a screen must differ, change the mockup first so the two never drift
  (`design/README.md`, `CLAUDE.md`).
- **Server-rendered HTML for documents; islands only where `docs/architecture-api.md` says so.**
  A rarely used admin form is a plain POST + redirect that works with JavaScript broken. No SPA
  router, no bundler (`CLAUDE.md`, `design/README.md` on Admin → Users).
- **Do not invent colours.** Use the palette tokens (`assets/css/tokens.css`); a new shade is a
  derived token, not a hex in a rule (`CLAUDE.md`).
- **Fix the rule, not the symptom.** A breakpoint bug is fixed in the rule that causes it, not by
  adding a second menu; two rules for one component are merged (roadmap, phase "Chrome and visual
  polish").
- **One control, one look.** The same thing is drawn the same way everywhere: one visibility badge
  and one visibility picker (2026-10-02), one notice card (2026-10-14).

## Layout and navigation

- **Shell B, "Reading room", without its floating dock** (2026-09-25). Two layers:
  - **Top nav, site-wide**: brand, search showing the current path (⌘K palette), ↑ up one
    namespace, + New (only for someone who can write somewhere), 📌 quick navigation, appearance
    (theme and palette), account (Profile, Admin for owners, Sign out). The ☰ namespace drawer, the
    namespace-index button and the Admin button were removed (2026-10-15).
  - **Page header, page-specific**, shared by every route of one page except `/edit`: crumbs and
    copy-id, title, badges, then the tab row (**Report · Edit · Revisions · Patient**) with the ⋯
    menu on the right. Each tab is a plain link; the active one is underlined. **Export ▾** sits
    beside ⋯ for every reader. A tab or menu entry joins the row once its backend exists, not before.
- **The reading column is centred and takes the container width of the breakpoint** (see
  Breakpoints and containers), padding included: 510 / 700 / 950 / 1200 / 1450 px
  (`.wk-panes[data-pad="read"] .wk-panebox`). The design README's 920 px predates it.
- **The editor (`/edit`) is full-bleed**: no page header or tab row, a viewport-high grid (text left,
  Assistant rail right, each scrolling on its own), a one-line crumbs row replacing the header
  (2026-09-29). Below `lg` (1024 px) it is an ordinary scrolling page . Sign, Export and ⋯ are on the report
  view only.
- **The editor's right rail is an accordion** of native `<details name="editor-rail">`, one open at
  a time; default is the checklist, else the Assistant, else the reference (2026-10-02). **Tools**
  (2026-10-16) is its last section, always there: text clean-ups that run in the browser on the
  selection (else all the text in front) through `editor-format.js`, one undo step each, hidden on a
  text page (D40). The editor's own: clean up dictated text, units and degrees, align Markdown tables; a plugin adds more
  through the `editor_tool` slot (`plugins/dokuwiki`: DokuWiki links, formatting, tables). A tool is a
  pure function (text, from, to) → an edit with `converted`/`skipped`, built on `editor-format.js`'s
  `proseTool`/`mask`, with a node test, a `data-edit-tool` button and a status line; it never calls
  the server. A conversion that is only needed while an archive is migrated belongs in a plugin, so it
  can be switched off.
- **Not built**: the mockup's `.wk-dock`, a list column, a Workbench tab strip above the document, a
  status bar.
- **Admin is one route per tab**, with the shared tab row `templates/admin-tabs.php`; not one tabbed
  island.

## Page head

Every page below the start page opens the same way (`.wk-doc` > `.wk-doc-head`):

```html
<div class="wk-doc">
<header class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => 'Admin'], ['label' => 'Users']]) ?>
<div class="wk-doc-titlerow"><hgroup><h1 class="wk-doc-title">Users</h1><p class="wk-dim">One-line lead, if the page needs one.</p></hgroup><div class="wk-actions">…</div></div>
</header>
…panels…
```

- **Breadcrumb** (`Http\Breadcrumb::render`) first: the path from the root to this page, the last
  item the page itself (no link). A page at the top of the app, such as Recent changes or Statistics,
  has a one-item trail.
- **One `<h1 class="wk-doc-title">` per page**, in `.wk-doc-titlerow`; `.wk-actions` beside it holds the
  page-level actions (see Action rows). A page's section headings are the panels' `h2`s.
- **Badges** (status, visibility) go under the title in `.wk-badges`, as on a report.
- **A lead** is one `<p class="wk-dim">` in an `<hgroup>` with the title, only when the page needs it.
- **One head everywhere** (decided 2026-10-17, owner's decision, after an audit found the page view's
  `<header class="wk-doc-head wk-pagehead">` and 25 other screens' `<div class="wk-doc-head">` differing):
  the head is always `<header class="wk-doc-head">`; the gap below it (`--space-6`) belongs to
  `.wk-doc-head` itself (`.wk-pagehead` no longer has a rule); the `<h1>` is always inside
  `.wk-doc-titlerow`, never bare; a lead sits in an `<hgroup>` with the title, not as a `<p>` after the
  head. Plugin screens follow the same shape. The start page's date line (`<p class="wk-crumbs">`) is
  the one exception: it is a greeting, not a trail.
- **The breadcrumb has no root crumb** (decided 2026-10-17): the "Spaces" level is not in the trail
  (the quick-nav has it); a trail starts at the first namespace level. Every crumb is preceded by the
  `›` separator, the first included (`.wk-crumbs li::before`), so a trail reads `› docs › notes › fonts`.
- **Badges only for the state of the thing** (decided 2026-10-17): a report, account or group shows
  status, visibility, roles; list and tool screens (namespace, recent, stats, join, worklists) have none.
- **A screen that is a step of something on a namespace** (the bulk Move, Tag and Delete confirm) has
  the full head like any screen: the namespace trail, then the action as the last crumb, the `<h1>` and
  its explanation as the lead; the body stays a narrow column (`wk-narrow`). The confirm pages of a
  *report* (sign, move, delete, visibility) take the page header partial and write only an `<h2
  class="wk-sec-title">` for the action (decided 2026-10-17).
- **Width modifiers on `.wk-doc`**: `wk-narrow` (720px, a confirm or check page, the visibility one
  included: its three cards and the public confirmation fit, checked 2026-10-17; the odd `771.5px`
  `wk-vis-doc` is gone); `wk-join` and `wk-start` only scope child styles. No `wk-m0` on a `.wk-doc`
  (it has no margin of its own).
- **A page that has the page header partial** (`templates/page-header.php`: a report and its tabs
  Report, Edit, Revisions, Patient, and the confirm pages of a report: delete, move, sign,
  visibility) takes its title from it and does not write its own head.
- **The editor and the sign-in page are the exceptions**: the editor has its own crumbs row
  (Layout), sign-in has the brand panel.
- **An error page** uses the standalone variant (`.wk-sec-title`, no breadcrumb) when the app's
  shell is not available.

## Forms

Decided 2026-10-16. One pattern for every field, and the language-key suffix says which part a text is.

- **Label**: a short name only. No `(e.g. …)`, no `(optional)`, no explanation in it. A unit that
  names the quantity may stay: `Time limit (seconds)`.
- **`*_hint` key = the placeholder**: an example value, such as `Dr. Ana Popescu`.
- **`*_help` key = the `<small>` under the input**: what the field does or how to fill it, such as
  `Left empty, reports show your username`. Only when needed; `Optional` is a help.
- **Input and `<small>` sit inside the `<label>`**: label text, input, small, in that order. This
  is what gives the label its click target and keeps the help attached to its field.
  ```php
  <div class="field"><label for="title"><?= $e(t('profile.sig_title')) ?>
  <input class="input" type="text" id="title" name="title" placeholder="<?= $e(t('profile.sig_title_hint')) ?>">
  <small><?= $e(t('profile.sig_title_help')) ?></small></label></div>
  ```
- **Never hard-code a placeholder or a help in a template** (D26): give it a `*_hint` / `*_help` key
  in `lang/en.php`.
- **Colour and size of the help**: `label > small` uses `--color-dimmer` and `--text-2xs`
  (`assets/css/wiki.css`). A field error is not a help: it is a `<small class="wk-field-error">`,
  also inside the label, after the help.
- **Not field help, so not a `<small>`**: a description under a panel heading, a button's `title=`
  tooltip, a hint at the page level. These keep their own keys.
- **What stays outside the label**: checkbox and radio rows (`label.radio` already wraps its
  control) and `group-addon` rows (each addon is a label of its own). A control group with a button
  may sit inside a label that has `for` (see Groups).
- **Where a control has no wrapping label** (everything else does; an audit of the templates on
  2026-10-16 found only these):
  - **A `.group` bar** whose labels are `group-addon`s beside the controls (add a site, choose the AI
    server).
  - **A table cell or row control** (routes, per-alias parameters, devices, plugin columns, the
    tag-merge target, the tags-review rename): the column header is its label and the control carries `aria-label`; its
    placeholder is a `*_hint` all the same.
  - **A bar or surface that is not a form field**: the top bar's search and palette, the editor's
    text areas, the save bar's revision note and the assistant's custom-prompt row (the placeholder
    is their only text), a hidden file input.
  - **The path-builder row** of the New page panel (`reports : modality : site : date : name`):
    four inputs in one composite control, each named by its placeholder and `aria-label`.
- **Placeholder keys all end in `_hint`**, including a placeholder that states a state (the
  plugin secret "set" / "not set") or a default taken from code; one that is only the label again
  (a table row repeating its column name) uses the label's key.
- **`required` blocks signing, never saving** (D7): a half-dictated draft always saves, so a form
  field is not made `required` to stop a save the product allows.

## Notices and messages

Details: `docs/ui-notices.md` (2026-10-14).

- A message to the user (saved, failed, warning, hint) is a **notice card**,
  `templates/partials/notice.php`, never a bare `<p role="alert">`.
- Four kinds, each with its colour, icon and role: `info` (accent, `status`), `ok` (`status`),
  `warn` (`alert`), `error` (`alert`). A `wk-notice` with no kind is avoided.
- **Not notices**: an error beside one field (`.wk-field-error`, `.wk-vispick-err`), the installer's
  flash (it runs before the app's CSS exists), a tag or badge in a table.
- A refused action says why in the user's terms and never leaks a filesystem path to an anonymous
  caller; an anonymous request for what they may not see is a 404, never a 403 (invariant 9).

## Buttons

One `.btn` base (14 px text, 7.5 px radius, an optional leading Phosphor icon, never wraps), plus a
**role** class and an optional **size** class. Counts are from the templates (2026-10-16).

### Roles

| Class | Look | Role | Use for |
|---|---|---|---|
| `.btn-primary` | accent fill, `--color-bg` text, 40% opacity when disabled | **the** action of a form, bar or panel | Save, Sign, Create, Sign in, Apply. One per form or bar |
| `.btn-secondary` | transparent, `--color-divider` outline, hover tints 8% | an action beside the primary, or several equal actions | Cancel, Edit, Export, Check, bulk Move/Tag/Export. The most used (87) |
| `.btn-ghost` | no fill, no outline, `--color-dim` text, text colour on hover | a quiet, low-priority or dismissive action | Discard a draft, Dismiss, Back to start, a row's Edit, "add section" |
| `.btn-danger` | solid `--state-error` fill | the submit of a **confirm page** for a destructive act | Delete page, bulk delete, empty trash |
| `.btn-secondary.btn-danger` | secondary outline and text in `--state-error`; filled only on hover (2026-10-02) | a destructive action **beside ordinary ones** | bulk Delete, Deactivate, token Revoke |

Rules:
- A form has at most one `.btn-primary`; a destructive act is never the primary of a form that also
  saves.
- A destructive button is an outline in the form, solid only on the page that exists to confirm it.
- A ghost button never carries the only way to do something important; it is for what the user can
  skip.
- `.btn-block` makes a button full-width: the sign-in submit only.
- **A `.group`'s action button is primary** whatever its class (see Groups), except `.btn-danger`.
  A secondary inside a group must be asked for (`.wk-ai-toolbar` does).
- **Icon-only** is allowed where the label repeats the icon, and carries the label as `title` and
  `aria-label` (phase 30). The editor's toolbar uses the square `.wk-tbtn` (36 × 33.5 px, no
  outline) for these; it is a toolbar control, not a form button.
- A file input's "Choose file" button is drawn as a secondary.
- Cyberpunk keeps its own error red, so a Delete never reads like the magenta primary.

### Check, then do

A button that **only looks** (asks a server, shows what a run would change) and a button that
**does** sit together, always the same way:

| | Check | The action |
|---|---|---|
| Class | `.btn-secondary` | `.btn-primary` |
| Writes anything | **never** | yes |
| Order | first (left) | last (right) |
| Examples | AI server: **Check** (asks for its models) then **Save**; a maintenance task: **Check** (dry run, `mode=check`) then **Apply** (`mode=apply`); a server's **Test** | Save, Apply |

- **The word is "Check"** for a read-only look at the system's state; "Test" only for trying one
  connection with what is typed in the form.
- **Icons**: a dry run over data uses `ph-magnifying-glass`; asking a server or a connection uses
  `ph-plugs-connected`; the action keeps its own (`ph-check` for Save, `ph-play` for Apply).
- **A destructive Apply is confirmed** before it runs (a ticked confirm box, or `data-confirm`
  on the form, as Empty trash does); Check never needs it.
- The two buttons submit the **same form**; the Check one chooses its own route with `formaction`
  or its mode with `name="mode"`.

### Sizes

| Size | Class | Height | Text | Pairs with |
|---|---|---|---|---|
| normal | `.btn` | `--control-h`, 40 px | `--text-sm`, 14 px | `.input` |
| small | `.btn-sm` | `--control-h-sm`, 28 px | `--text-xs`, 13 px | `.input-sm`, `.wk-inline-input`, `.seg-sm` |

- **Small is for panel headers, table rows, toolbars and the editor's notices** (59 uses); a form's
  own actions are normal size.
- **Pair by height**: an input next to a button gets the same size class (`.input` + `.btn`;
  `.input-sm` + `.btn-sm`), so a row is one height.
- **A finger needs more**: on a coarse pointer (`pointer: coarse`) `.btn-sm`, `.seg-opt` and the
  toolbar's icon buttons grow to 36 px (2026-10-02). There is no large size.

## Checkboxes and radio buttons

One component draws both: `label.radio` around a real, visually hidden `<input>`, then
`<span class="dot">`, then the text. The input keeps focus, keyboard and form submission; the dot
is what is seen.

```html
<label class="radio"><input type="checkbox" name="remove_icon" value="1"><span class="dot"></span>Remove the icon</label>
<label class="radio"><input type="radio" name="choice" value="1"><span class="dot"></span><span>Label <small>detail</small></span></label>
```

- **Shape says the kind**: a **checkbox is a square** dot, a **radio is a round** one (16 px,
  `--radius-sm`; round when the input is `type=radio`). Checked: accent border and tinted fill.
  Indeterminate (a "some ticked" parent): a dash. Focus: a 2 px accent outline.
- **Checkbox** for an independent yes/no or a set of choices; **radio** for exactly one of a few,
  all with the same `name`. A single on/off setting is a checkbox, not a radio.
- **A hint for the choice** is a `<small>` inside the label, after the words, on its own line
  (`.radio > span > small`: `<label class="radio"><input><span class="dot"></span><span>Words<small>Help</small></span></label>`),
  never in the label text. Help for a *set* of rows ("untick to leave it out") is one `<small class="wk-dim">`
  after the rows.
- **A standalone `label.radio` is not a `.field`'s label**: it has its own text, so the field rules
  about wrapping `<input>` apply to its text and `<small>` only.
- **A choice that is a whole card or row** (the visibility picker's three cards, the template
  picker's rows `.wk-tpl-i`) hides its radio and tints the chosen row (`:has(input:checked)`); the
  choice must still be reachable by keyboard.

## Switches

There is **no sliding toggle** in the product. An on/off *setting* is a checkbox (above). What the
mockup and the code call a switch is the **segmented control** `.seg`: a row of options of which
exactly one is on, drawn as a bar with the chosen one filled in accent.

| Use | Markup | Examples |
|---|---|---|
| **A choice that submits** (radios) | `.seg` of `label.seg-opt` each with `<input type="radio">` | Enabled / Disabled for the assistant and a plugin; Sync / Whole in compare; word / line in the diff |
| **A filter that navigates** (links) | `.seg` of `a.seg-opt`, the current one `.seg-on` + `aria-current="true"` | period, site and modality on Statistics and Recent |
| **A choice that is a button** | `.seg` of `<button class="seg-opt">`, the current one `.seg-on` / `aria-pressed="true"` | theme Dark / Light in the appearance menu |

- Give the `<span class="seg">` `role="group"` and an `aria-label` naming the choice.
- Use it for **two to four short options**; more than that is a `<select>`.
- `.seg-sm` is the 28 px version, **for panel headers and table rows**, matching `.btn-sm`; it
  grows to 36 px on a coarse pointer.
- A `.seg` in a filter bar (`.wk-filters`) is flush: options the same width, a straight divider,
  rounded at the ends only.

### Enabled | Disabled

The switch for turning a thing on or off (a plugin, the assistant) is **one markup, on every page**
(`admin-plugins.php`, `admin-ai.php`):

```html
<span class="wk-actions">
<form action="/admin/…/toggle" method="post" data-autosubmit>
<span class="seg seg-sm" role="radiogroup" aria-label="State">
<label class="seg-opt"><input type="radio" name="enabled" value="1" checked>Enabled</label>
<label class="seg-opt"><input type="radio" name="enabled" value="0">Disabled</label>
</span>
<noscript><button class="btn btn-secondary btn-sm" type="submit"><i class="ph ph-check" aria-hidden="true"></i>Apply</button></noscript>
</form>
</span>
```

- **Where**: in the panel's header, inside a `.wk-actions` after the `<hgroup>` (see Action rows);
  the on/off state belongs to the whole panel.
- **`.seg.seg-sm` with `role="radiogroup"`** (not `group`: its options are radios) and an
  `aria-label` naming what is switched; no `title` repeating it.
- **Labels are always** "Enabled" first, "Disabled" second (`admin.plugins.enabled|disabled`), the
  current one `checked`. The radio is named for the setting (`enabled`, `ai_enabled`), value `1` / `0`.
- **It saves by itself**: `data-autosubmit` on the form submits on change (`assets/js/shell.js`),
  so there is no Save button. For a browser without JavaScript the form carries a
  `<noscript>` **Apply** button (`btn-secondary btn-sm`, check icon), and only that.
- The state is shown next to it when it matters: a failure as `.tag-caution` in the same
  `.wk-actions`, before the form.

## Groups (`group`, `group-fill`, `group-stack`)

`.group` joins controls into **one control**: an `inline-flex` row, children touching (1 px
overlap), only the outer corners rounded, the hovered or focused child lifted above its neighbours.
Use it where an input and its action are one thing.

| Class | What it does | Use for |
|---|---|---|
| `.group` | the joined row, as wide as its content | an input + button in a form field: "Suggest" beside a summary, a custom prompt + Send, a tag name + Rename |
| `.group-fill` | `display: flex; width: 100%`: the group spans its container | a whole-row bar: search, and the Admin forms that add a site or choose the AI server |
| `.group-stack` | below 1024 px the row re-flows into a grid (see below) | a group of several labelled controls and a button that would not fit one line on a phone |
| `.group-addon` | a label or icon joined to the controls: bordered, tinted, never interactive | the icon + word before a control ("Site code", a server icon) |
| `.group-date` | a date input at its natural width | a date in a bar |
| `.group-wide` | a free-text control that takes the rest of its line when stacked (in the CSS; no template uses it yet) | |
| `.grow` | opt in: take a share of the leftover width | an input that should fill its group |

```html
<!-- one field: an input and its button -->
<div class="group"><input class="input" type="text" id="summary"><button class="btn" type="button">Suggest</button></div>

<!-- a whole-row bar that stacks on a phone: addon + control pairs, then the button -->
<form class="group group-fill group-stack" action="/admin/sites" method="post">
<label class="group-addon" for="code"><i class="ph ph-buildings" aria-hidden="true"></i> Site code</label>
<input class="input wk-mono" id="code" name="code">
<label class="group-addon" for="name">Name</label>
<input class="input grow" id="name" name="name">
<button class="btn btn-primary" type="submit"><i class="ph ph-plus" aria-hidden="true"></i> Add</button>
</form>
```

- **The action button of a group is primary** whatever its class, except `.btn-danger`; a
  secondary inside a group must be asked for (the AI toolbar does, `.wk-ai-toolbar`). So a group
  holds one action, and it is the group's main one.
- **A group's own element**: `.group` may be the `<div>`, `<span>` or `<form>` itself, as long as
  its direct children are the controls.
- **Each child keeps its own size class**: pair `.input` with `.btn` (40 px) or `.input-sm` /
  `.wk-inline-input` with `.btn-sm` (28 px). Mixing them makes a ragged edge.
- **`.group-stack` is a layout that only exists on small screens** (2026-10-02): 
  - **Below 576 px**: a two-column grid; each addon sits in the first column (at least 44 px wide,
    its text centred) with its control beside it, one pair per line, joined top to bottom; a control
    with no addon, and the button, take the whole line.
  - **576–1023 px**: two addon + control pairs per line (four columns), the button on its own line;
    a `.group-wide` control takes the rest of its line after its addon.
  - **From 1024 px up** it is the plain row.
- **A group is not a layout tool**: it is for controls that act together. To line up unrelated
  fields use `.wk-form-grid`.
- **In a field**: a `.group` may sit inside a `<label for="…">` (the label's own `for` target is
  the input; a click on the button is the button's own), so the field's `<small>` stays with it. A
  label *without* `for` would pass clicks to its first control, so give a field with a group one.

## Panels (`.wk-panel`)

A panel is a raised surface (`--color-surface`, 7.5 px radius, small shadow) that holds one topic
on a page: a settings group, a list, a form. Anatomy, top to bottom:

```html
<div class="wk-panel">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow">Title</h2><p class="wk-dim">What this is for</p></hgroup></header>
… content …
<form> … <footer><button class="btn btn-primary" type="submit">Save</button></footer></form>
</div>
```

- **Header**: `<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow">title</h2><p class="wk-dim">text</p></hgroup></header>`
  (decided 2026-10-16). The title is an `<h2>` (styled as the small uppercase eyebrow) and the
  one-sentence description is the `<p>` of the same `<hgroup>`. The `<p>` is optional; the `<hgroup>`
  and `<header>` are not, even for a title alone, so every panel has the same structure. A count
  next to the title sits inside the `<h2>` (`<span class="wk-count">`); `.wk-panel-title` is gone
  (2026-10-17: the dicom and hipobridge screens used it). In a report tab's panel the title is an `<h3>`.
  - The text says what the panel is for or what it changes, in one or two sentences; it is the
    place for a section's description (a field's own help goes in the field's `<small>`, see Forms).
  - Something that belongs on the header's right (a `.seg`, a small button, a count) is a sibling
    after the `<hgroup>`, inside the `<header>`.
- **Footer**: a panel that ends with buttons ends with them in a `<footer>`, inside the `<form>`
  they submit (decided 2026-10-16): `<form> … <footer>buttons</footer></form>`. The footer is a
  full-width strip at the panel's bottom (a divider above, buttons right-aligned and wrapping), and
  holds the panel's actions only, never fields. Two things besides buttons are allowed in it: the
  **confirm tick** that belongs to a destructive or applying button (`label.radio` + checkbox, left of
  that button: remove a site, Apply a maintenance task) and a short mono line at the left (the
  command a maintenance task runs). A panel with no action has no footer. In a stretched
  panel of a grid (`.wk-panels`) the footer sticks to the bottom (`margin-top: auto`).
- **Where a footer is not used**: a form that *is* a `.group` (add a site, choose the AI server)
  keeps its button as the group's own action. A form in the panel's header (Empty trash, the
  assistant's Enabled | Disabled) is a header action: `span.wk-actions > form` (see Action rows).
- **A panel has one footer**, at its bottom, and the footer strip bleeds to the panel's edges. A
  second action with its own form (Rename beside Save, Delete) gets **its own panel** (Admin → Tags
  does this for Rename), or is a destructive outline button in the single footer when it needs no
  input of its own (Revoke, Deactivate); one button's submit never carries the other's fields.
- Panels stack with `--space-6` between them; a panel does not nest another panel.
- A `.wk-modal` has the same shape: a `<footer>` of actions, right-aligned.

## Action rows (`.wk-actions`)

`.wk-actions` is a row of buttons that act together: flex, `--space-2` apart, never wrapping,
**right-aligned**. It is the one class for such a row wherever it sits; where it sits decides its
size and what it may do.

| Where | Markup | Buttons | Use for |
|---|---|---|---|
| **A panel header** | `.wk-panel-h` > `hgroup` + `div.wk-actions` | `.btn-sm`, mostly secondary | actions on what the panel *shows*: a table's bulk actions (Move, Join, Tag, Export, Delete), with the selection count |
| **A page title row** | `.wk-doc-titlerow` > title + `div.wk-actions` | normal or small | actions on the page as a whole (New, Export); it sits right of the title and, when it no longer fits there, moves to its own line, still right-aligned |
| **The end of a page form** | `div.wk-actions.wk-actions-end` | normal; Cancel first, the submit (primary) last | the Cancel and submit of a page with no panel: a confirm page (delete, move, sign, visibility), the new-report form |
| **A toolbar** | `div.wk-actions` in a bar or card | small | search and filter bars, the Revisions and Timeline controls |

- **In a panel header** the row is a sibling *after* the `<hgroup>`, inside the `<header>`. A header
  holds **one** `.wk-actions`. It is for actions on the panel's content; the actions of a form in
  the panel (Save, Cancel) belong in the panel's `<footer>`, not here.
- **A single link or a count in a header** (CSV, "All mine", a total) is not an action row: it is a
  bare `a.wk-start-more` or `span.wk-count` beside the `<hgroup>`.
- **A bulk action is disabled until it applies**: `data-needs-selection` buttons stay disabled (45%
  opacity) with nothing ticked, and the count (`.wk-mono.wk-dim.wk-text-sm`) appears when something is.
- **`.wk-actions-end`** is just `justify-content: flex-end` (the base already is) with the top margin
  the context sets; it marks a row at the end of a page-level form, the page's equivalent of a
  panel footer.
- **On a phone (below 768 px) the text goes and the icon stays in the actions of a page header, a
  title row or a panel header, and in the page tabs**: the button text sits in
  `<span class="wk-btn-label">`, and the button carries the meaning in `title` (and `aria-label`).
  **A footer's buttons keep their words** (Save, Cancel, Apply): they are the form's own actions and
  a bare check or cross is not enough to act on. (The CSS also hides `.wk-btn-label` in a footer, for
  the rare footer button that opts in.) Every button in these rows has an icon.

## Badges and status

- **Status and visibility are pills** tinted from the palette's own state shades, not uniform grey;
  the value → class → icon table is under Tags, chips and counts.
- **Visibility is drawn by `Support\Visibility::badge()` only**: an icon (lock, link, globe), a
  capitalised label, the level's sentence as tooltip. The nav drawer shows the icon alone and only
  for unlisted and public pages (private is the norm). The control that changes it is
  `partials/visibility-picker.php` (three option cards, the saved one marked "now"), used by the
  editor's Metadata view and `/{path}/visibility`.
- **Publishing is a deliberate, noisy act** (D16): choosing Public opens what everyone will see and
  an acknowledgement, in place; it is audited.
- **No `amended` badge**: a correction is a new signed revision, the status stays `signed` (D3).

## Breakpoints and containers

**Pico's scale, adopted 2026-10-01** (picocss.com/docs/container). A media query cannot read a
custom property, so the numbers are written out: **use only these** (the list is also in the header
of `assets/css/wiki.css`).

| Name | Screen | Container (reading column) | Write it as |
|---|---|---|---|
| xs | < 576 px | 100% | `max-width: 575.98px` |
| sm | ≥ 576 px | 510 px | `min-width: 576px` |
| md | ≥ 768 px | 700 px | `max-width: 767.98px` / `min-width: 768px` |
| lg | ≥ 1024 px | 950 px | `max-width: 1023.98px` / `min-width: 1024px` |
| xl | ≥ 1280 px | 1200 px | `max-width: 1279.98px` / `min-width: 1280px` |
| xxl | ≥ 1536 px | 1450 px | `min-width: 1536px` |

- **Max queries end in `.98`** (`1023.98px`), so a min and a max query of the same step never both
  match at a fractional width.
- **The container is the column, padding included.** `.wk-panes[data-pad="read"] .wk-panebox` is
  100% wide below `sm` and then takes the container width of the largest step reached, centred,
  with `--space-8` (41 px) of padding.
- **What changes at each step**
  - **below sm**: the tabs collapse into one "current ▾" menu; `.group-stack` is one pair per line.
  - **below md**: icon-only buttons in `.wk-actions`, the page tabs and panel footers; the public
    site grid is one column.
  - **below lg**: the editor is an ordinary scrolling page; `.group-stack` stacks; the two-column
    layouts (`.wk-two`) are one column.
  - **below xl**: the sign-in and installer pages lose their brand side; tag words in tables fold to
    icons.
  - **from xl**: a page with a contents list gets it beside the text (below).
- **A table of contents sits beside the text, not instead of it**: the text keeps the width it has
  at `lg` (950 px less the padding, 868 px), and from a container 1087 px wide the contents
  (193–283 px) is added in a second column; the same threshold applies to the Admin panels, which
  keep that column width.
- **Other containers**
  - Public page: `.wk-public-doc`, max 1003 px (1450 px for one with a contents at `xxl`).
  - Modal: `min(440px, 100vw − 2 × --space-4)`, max height 85 vh.
  - Sign-in and installer: a two-column split (1 : 1.618) with the brand left, one column below `xl`.
  - The editor is full-bleed (no container) and the top bar is `--wk-topbar-h`, 59 px.
- **Component queries**: the page's own width, not the screen's, drives a few components
  (`container-type: inline-size`): the metadata grid goes to four columns from 820 px of its own
  width; the document body and the Admin pages use the 1087 px rule above.
- **Container thresholds** (`1087px`, `820px`) are not breakpoints: they are derived widths of a
  component's own container (950 px less padding plus the contents column), see above.
- **Also respected**: `prefers-reduced-motion: reduce` (no animation), `pointer: coarse` (36 px
  touch targets), `print` (its own stylesheet), and `scrollbar-gutter: stable` so a short and a long
  page sit at the same width.

## Tables and small screens

The owner (2026-10-02): keep the phone interface crisp and functional, even at the cost of showing
less.

- **Test every page at 390 px and 768 px**, signed in and anonymous, and again at 1400 px for
  regressions; screenshots with `tools/browser`.
- **Must hold**: no horizontal page scroll; tap targets large enough (the phase asked for 40 px, and
  on a coarse pointer small buttons, switches and icon buttons are at least 36 px); nothing hidden
  behind the two-row top bar or the save bar; the on-screen keyboard never covers the field being
  typed in; every action still reachable, in a menu if not on the screen.
- **Allowed**: an ellipsis on titles, paths, crumbs, chips and cells; icon-only buttons; hiding
  secondary columns, long help text and decorative counts behind a menu or not at all; tables that
  scroll inside their panel or collapse to one line per row.
- **A table becomes cards** with `table.table-cards`; `.wk-card-head` is the row's title,
  `.table-cards-compact` is for page lists (title, then one wrapped line of details),
  `.table-cards-form` for rows of inputs (each label above its control).
- **Top bar**: two rows on a phone (brand, search, account; then the icon buttons), kept tight rather
  than cut to one row. Report text is left-aligned, not justified, on a phone.
- **A `position: fixed` element inside the page column is held by the column's CSS container**:
  slide-in panels are moved to `<body>`.

## Colour, themes and palettes

- **Palettes are Shell C's, not the Reading room's** (2026-09-30): Royal blue (default), Lime,
  Amber, Teal, plus Slate (Pico's named hue) and Cyberpunk (a whole theme with its own stylesheet,
  `assets/css/cyberpunk.css`). Each has a dark and a light theme. Rose is not offered.
- **Theme and palette are per-user display preferences** (a cookie); anonymous and new users get
  royal blue and the system theme.
- **Everything coloured is a token**: `--color-bg`, `--color-text`, `--color-dim`, `--color-dimmer`
  (the help text under an input), `--color-accent`, `--state-ok|warn|error`. A new palette defines
  all of them; `--color-dimmer` is derived from `--color-dim` in each theme block of `tokens.css`.
- **State colours are tokens too** (`--state-ok|warn|error`): every palette defines its own, so a notice or badge needs no per-theme rule (`docs/ui-notices.md`).
- **Scoped class names**: components use the mockup's `wk-` prefix.

## Icons

- **Phosphor** (`ph ph-…`), as in the mockup. A decorative icon has `aria-hidden="true"`. An icon
  and a word are used together on a button; an icon alone only where the label repeats it (`title`
  and `aria-label`).
- **A tab icon follows the same convention** (the site favicon is a Phosphor-style glyph, drawn for
  tab size with a version in its link so a browser replaces the old one).
- **One icon per action, on every page** (observed 2026-10-16; new buttons take the icon of their
  action):

| Action | Icon | Action | Icon |
|---|---|---|---|
| Save, Apply, confirm | `check` | Cancel, Close, Dismiss | `x` |
| Add, New | `plus` | Create (open the editor) | `arrow-right` |
| Edit | `pencil-simple` | Open (read) | `file-text` |
| Delete, Discard | `trash` | Restore | `arrow-counter-clockwise` |
| Sign | `signature` | Copy | `copy` |
| Assistant, Suggest | `sparkle` | Sign in | `sign-in` |
| Check (a dry run over data) | `magnifying-glass` | Check (a connection) | `plugs-connected` |
| Check again, Rebuild | `arrows-clockwise` | Run (Apply a task) | `play` |
| Join | `stack` | Compare / diff | `git-diff` |
| Home | `house` | Back | `arrow-left` |

- **Notices** have their own: `info`, `check-circle`, `warning`, `warning-octagon` (see
  Notices).

## Text and language

- **English chrome, Romanian content** (D26). Every string a user reads is in `lang/en.php`; a
  Romanian UI later is a translation job, not a refactor. No hard-coded string in a template.
- **A `*_hint` / `*_help` suffix has a meaning** (see Forms); other suffixes are free.
- **Names a user types are theirs**: a report's patient name is shown as typed; the patient's path
  and name never reach a URL, a log or an AI prompt (invariant 8), and the UI never puts them there.

## Tags, chips and counts

- **`.tag`** is a small pill for a *state* of the thing in view (status, visibility, role,
  "failed", a plugin's state). Its colour class says how loud:
  `tag-outline` (quiet, the norm), `tag-neutral`, `tag-accent`, `tag-signed` (green), `tag-caution`
  (accent tint), `tag-visible` (solid accent, the loudest).
- **Status and visibility get their class from one place**, `Support\Badges`, never chosen in a
  template:

| Value | Class | Icon |
|---|---|---|
| status `draft` | `tag-neutral` | `note-pencil` |
| status `signed` | `tag-signed` | `signature` |
| status `archived` | `tag-outline` | `archive` |
| visibility `private` | `tag-outline` | `lock` |
| visibility `unlisted` | `tag-caution` | `link` |
| visibility `public` | `tag-visible` | `globe` |

  Visibility steps up from the quiet outline as exposure grows, so a public page stands out.
- **`.tag-word`** wraps the word inside a tag: in a table the word folds away below `xl` and the icon
  stays (a `title` carries the meaning), so a narrow table keeps its columns.
- **`.wk-chip`** (mono, outlined) is a *thing*, not a state: a namespace, a tag name, a server;
  `.wk-chip-on` marks the active one.
- **`.wk-count`** is a number beside a heading (`<span class="wk-count">`), inside the `h2`, or beside
  the `hgroup` in a panel header.

## Answers with citations (Ask the archive)

Decided 2026-10-19 (owner: improve the screen, be creative within the rules): `/ask` is a **composer**, an **answer panel** and a **sources panel**.

- **The composer** is one surface: the question's `<label>` with its textarea and `<small>` help, and a footer strip with the shortcut (`Ctrl + Enter`) at the left and the one primary **Ask** at the right (normal size, spinner through `data-busy`/`aria-busy` while it runs). Below it, until a question is asked, three **example questions** as `.wk-chip`s that fill and send it.
- **A citation `[n]` in the answer is a pill** (`.wk-ask-cite`, mono, accent tint like `tag-caution`) that links to its source; the pill and the punctuation after it never split across a line (`.wk-ask-nb`). Hovering or focusing a pill, or hovering a source, lights both (`.is-hl`); only `[n]` of a source that is listed becomes a pill.
- **Sources sit beside the answer from `xl` (1280 px)**, sticky, and under it below that. A source shows its number, an icon (`file-text` a wiki report, `hospital` one from outside the wiki), the date, the modality as a tag (`tag-outline` for a wiki report, `tag-neutral` for another system's), and its passage; only a wiki report has *Open the report*.
- **The answer panel's header** carries one ghost *Copy* (`ph-copy`, label hidden on a phone, `ph-check` and *Copied* for a moment after); its footer line is the quiet mono *Sent: …* line with the shield icon.
- A source left out (HippoBridge down) is a `warn` notice card above the answer, never a bare line.

## Typography and utilities

- **Sizes** are the `--text-*` tokens: `2xs` 12 px (badges, help under an input), `xs` 13 px
  (secondary), `sm` 14 px (buttons, labels, table cells, nav), `base` 16 px, `md` 18 px, `2xl` 30 px
  (the page title), `3xl` 36 px (sign-in and landing only). Spacing is `--space-1…8`; radii
  `--radius-sm|md|lg`.
- **`.wk-dim`** is secondary text (a description, a timestamp, a hint); never use it for what the
  user must read to act.
- **`.wk-mono`** is for anything the user might copy or compare character by character: a path, a
  code, an id, a number in a column, a command, an address. It is not decoration.
- **`.wk-eyebrow`** is the small uppercase heading style of a panel's `h2`; plain `h2`/`h3` inside
  a document are the reading text's own.
- **`.wk-text-sm|xs`, `.wk-mt-*`, `.wk-mb-*`, `.wk-m0`** are one-off spacing and size nudges; if
  the same nudge appears three times it becomes a class (`.wk-opts` for a stack of checkbox rows,
  `.wk-empty` for an empty state were inline styles and repeated paragraphs).
- **No inline `style=`** except a value only the template knows (a computed width). Everything else
  is a class in `assets/css/wiki.css`.

## States

- **Empty**: a single `<p class="wk-empty">` in the place the list would be, saying what is missing
  and, if useful, how to add one ("No runs yet."). No icon, no card.
- **Busy**: a form that takes time carries `data-busy`; its submit shows a spinner in place of the
  icon and ignores clicks (`aria-busy`). A button that starts work in the page (`data-ai-…`) does the
  same through `assets/js`.
  **Every editor Tool** (the rail's Tools section, core and plugin) shows the spinner on its button's
  icon while it runs and removes it when it is done (2026-10-16, owner's decision): `aria-busy` on
  the `.wk-ai-btn`, set before the work and cleared in a `finally`, a click meanwhile ignored. A tool
  runs in the browser and is usually instant, so the work waits for one paint (`requestAnimationFrame`
  then `setTimeout 0`) to let the spinner show on a long text; there is no artificial minimum time.
- **Confirm**: a risky form carries `data-confirm="message"` (and optionally `data-confirm-title`,
  `data-confirm-label`, `data-confirm-tone`): its submit opens the one modal (`<dialog>`, focus trap,
  Escape) and only its confirm button sends the form. Without JavaScript the form simply submits,
  so a page that needs a confirmation the server cannot do without also has a confirm page.
- **Disabled**: 45% opacity, `cursor: default`; a bulk action is disabled until it has a target.
- **Invalid**: `aria-invalid="true"` on the control, its message in a `.wk-field-error` inside the
  label (Forms).

## Reading text

- **The report is the product**: justified text is not used on a phone; the reference page is a
  quiet document (owner, 2026-10-02): 75% of the report's sizes, no colour, plain headings (no
  capitals, letter-spacing or accent rules), left-aligned (`.wk-ref-page .wk-prose`).
- **A page body is markdown, or plain text shown as typed** (D40); the dialect is CommonMark plus
  tables and definition lists (D17), rendered on the server only.

## Print and export

- **Print CSS is dompdf CSS** (D34): no flexbox, no grid, no `color-mix()`, no `var()` fallback
  chains; tables and block layout with absolute widths in `mm`/`pt`, web-safe or registered fonts.
- **Its own stylesheet** (`assets/css/print.css`), never shared with the screen one.
- **Every change to a print template is checked in a rendered PDF**, not only in a browser.
- **A title may break lines** with `|` in the signature block (`Support\SignatureTitle`); elsewhere
  the lines are joined by ` · ` (2026-10-16).

## Left out on purpose

The mockup shows things the decisions removed; do not build them (`design/README.md`): TOTP/2FA
fields on sign-in (D35), an API-tokens table in Admin → Integrations, an HL7 order bridge, DICOM SR
export and C-GET, a dictation button (D24), measurement macros (D18), resident and review queue
states (D37), an `amended` badge (D3).
