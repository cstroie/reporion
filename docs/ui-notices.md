# Notices: messages in a card

One component says "something happened or needs attention" anywhere in the app: the mockup's
`.wk-notice` (an icon and text on a raised surface), coloured by what the message means. Use it
instead of a bare `<p role="alert">`, which is easy to miss. Added 2026-10-14.

## The four kinds

| Kind | Use for | Colour | Icon | Role |
|---|---|---|---|---|
| `info` | a hint or a state worth knowing (pick two reports to compare) | `--color-accent` | `info` | `status` |
| `ok` | something worked (saved, allocated) | `--state-ok` | `check-circle` | `status` |
| `warn` | a problem that does not stop the user (a rendering warning, a stale revision, fields signing needs) | `--state-warn` | `warning` | `alert` |
| `error` | an action failed (a refused save, a bad upload) | `--state-error` | `warning-octagon` | `alert` |

Colours are the palette tokens, so every theme gets its own. Every `wk-notice` in the app now
carries a kind (2026-10-14: about 70 hand-written ones in Admin, the plugins, the join and new-report
screens and the editor's banners were given one, mostly by role and icon: alert is `error`, or
`warn` for "busy", "not configured" and the like; a check icon is `ok`; the rest `info`). A new one
should use the partial, or at least add the kind class to the markup. A `wk-notice` with no kind is
the plain neutral card: avoid it.

## Use it: `templates/partials/notice.php`

Set the variables, then include. The partial escapes everything and unsets its variables.

```php
<?php $noticeKind = 'error'; $noticeText = $error; include __DIR__ . '/partials/notice.php'; ?>
```

| Variable | |
|---|---|
| `$noticeKind` | `info`, `ok`, `warn` or `error` (required) |
| `$noticeText` | plain text, escaped by the partial (required) |
| `$noticeLink` | optional `[href, label]`, after the text |
| `$noticeItems` | optional list of strings: the text becomes a heading line and the items a bullet list under it |
| `$noticeId` | optional `id` on the card, for a script |
| `$noticeClass` | optional extra classes, e.g. a spacing one (`wk-mb-3`) (2026-10-17) |
| `$noticeIcon` | optional Phosphor icon name, when the kind's own (`info`, `check-circle`, `warning`, `warning-octagon`) does not say it (2026-10-17) |

Done this way: the editor's save error (with its link to the existing page) and "Saved (rev N)" (`#editor-saved-notice`), the new-page error, the
Sign screen's stale-revision warning, the timeline's merge result and compare hint, the profile's signature error, the
delete confirmation's error, and the public layout's rendering warnings.

## CSS (`assets/css/wiki.css`, next to `.wk-notice`)

`.wk-notice-info|ok|warn|error` set `--wk-notice-c`; one shared rule draws the 3px left border and
colours the icon from it. `.wk-notice-list` spaces a sentence followed by a list.

To write the markup by hand (where the partial does not fit, such as a list item with
markup in it: the Sign screen's missing fields):

```html
<div class="wk-notice wk-notice-warn wk-notice-list" role="alert"><i class="ph ph-warning" aria-hidden="true"></i><div>
<p>Text</p><ul><li>Item</li></ul></div></div>
```

## Not notices

- An error beside one field: `.wk-field-error` (`templates/new-report.php`), `.wk-vispick-err` (visibility picker).
- The installer's flash (`.wk-install-flash`): `src/Install/` runs before the app's CSS and partials exist.
- A tag or badge in a table: `.tag-signed`, `.tag-caution`.
