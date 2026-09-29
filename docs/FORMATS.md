# On-disk and wire formats

The specs describe *what* is stored; this file pins *exactly how*. Anything ambiguous here
becomes a bug the first time two implementations disagree. Every format below is covered by a
test fixture.

## 1. Slug collisions — the case the specs missed

Two exams for the same patient on the same day produce the same path
(`260922-ionescu-maria`). This is common: a CT and an MR, or a repeat study after contrast.

**Rule.** On create, if the path exists, append `-2`, `-3`, … to the *last* segment:

    reports:mri:mioveni:260922-ionescu-maria
    reports:mri:mioveni:260922-ionescu-maria-2

The suffix is allocated inside the same journal-protected create that mints the pid, so two
concurrent creates cannot collide. The suffix is **never** reused after a delete: the trash
still holds the original, and a purge does not free the slug (the redirect table remembers it).
The UI shows the collision explicitly at create time — "a page for this patient and date
exists: `…-maria` (CT, 09:14). Create `…-maria-2`?" — because silently creating a second page
is how a report gets written in the wrong document. **Built** in the new-report form (phase 7):
the check matches the patient by key (strong or weak, D11) and date across all modalities, and
creating needs an explicit confirm.

Different modality or site already differ earlier in the path, so the collision only ever
applies within one namespace.

**A namespace is not a collision** (decided 2026-09-26). A page and a namespace may share a name,
as in DokuWiki: `reports:mri:mioveni` (the site's description) lives in the same directory as the
reports under `reports:mri:mioveni:*`. Creating the page claims that directory with an atomic
`mkdir` of its `rev/` instead of taking `…mioveni-2`; only an existing page or redirect stub gets
the suffix. (Before this, the generic page import turned seven DokuWiki `x` pages beside an `x:`
namespace into `x-2`.)

## 2. `data/journal/YYYY-MM-DD.ndjson`

One line per write intent, appended and `fsync`ed before any page file is touched. Replayed on
boot; a line with no matching `done` is an incomplete write.

```json
{"ts":"2026-09-22T09:41:10.882+03:00","op":"save","pid":"01JB8X4MT7QK2V9Z0C3R5H6ND","path":"reports:mri:mioveni:260922-ionescu-maria","rev":8,"base_rev":7,"body_sha":"9f2c…","actor":"owner","state":"intent"}
{"ts":"2026-09-22T09:41:10.941+03:00","pid":"01JB8X4MT7QK2V9Z0C3R5H6ND","rev":8,"state":"done"}
```

`op` ∈ `create|save|revert|move|delete|restore|purge|sign|import`. Recovery is idempotent: replaying a
`done` line is a no-op, replaying an `intent` re-runs the write from `rev/NNNN.md.gz` if that
file exists, or discards the intent if it does not. An intent whose revision a later write has
already superseded (`meta.json` rev is higher) is closed without touching `current.md`. Every
outcome except `corrupt` appends a `done` line, so nothing is replayed twice.

**When replay runs** (decided 2026-09-26): on every request, the front controller reads what was
appended since last time and replays intents **older than 60 s** — there is no page write lock, so
a younger intent may be a write still in progress. It takes `journal/.replay.lock` non-blocking; a
request that finds it held just carries on. `bin/reporion journal:replay [--min-age=60] [--dry-run]`
does the same for an operator or a deploy (full scan, pages named by pid). Two helper files sit
beside the journal: `.checkpoint.json` (file, byte offset and the intents still open there) and
`.replay.lock`. With no checkpoint — the first request after this shipped, or after it was deleted —
boot replay starts from the journal's current end: intents already open are a backlog of unknown
age, left to `journal:replay --dry-run` and an operator, never replayed unseen by whichever request
comes first.

## 3. `data/counters.json`

Accession sequences (D20) for reports created in the app (`Service\Accessions`): the last number
issued per site + modality + year, keyed `{site code, lower-case}:{MOD}:{yy}`:

```json
{"scuc:MR:26": 1764, "mioveni:CT:26": 412}
```

Allocated under `counters.json.lock`, written atomically, just **before** the page is created —
so a crash between the two leaves a gap in the sequence, never a duplicate. A key used for the
first time is seeded from the highest number already in any page's `accession:` on disk (the same
rule the importer follows, `Support\AccessionFormat`), and every allocation also stays above the
highest number the index holds for that key, so a batch imported since cannot be collided with.
Formatted with `accession.pattern` / `seq_pad` from config: the live archive reads
`SCUC-MR-23-1764` — `{SITE}` is the site code upper-cased (or the site's `accession_code` from
Admin → Settings), `{MOD}` the schema modality code. A gap is acceptable; a duplicate is not.

## 3b. `{page}/media.json` — attached files (D10/D27)

A JSON list in the page's directory, appended by `Storage::attachMedia()` under a lock and written
atomically; it moves, trashes and restores with the page. The bytes live once in
`data/media/{year}/{sha256}.{ext}` (written first, `link()`-once), so a crash between the two
writes leaves an unreferenced file, never an entry without its file — no journal intent.

```json
[{"sha256":"8f2c41…e9","ext":"png","name":"Axial T2.png","bytes":48213,"w":512,"h":512,"added":"2026-09-26T11:14:02+03:00","by":"a.barbu"}]
```

Not revisioned: attaching is not an edit of the report. The index derives `links.kind = media`
rows from it, which is what `GET /media/…` checks access against. Unreferenced blobs are not swept
yet.

## 3c. `data/maintenance/` — maintenance runs

`runs/{yyyymmdd-hhmmss-xxxxxx}.json` — one report per maintenance run, from Admin → Maintenance or
`bin/reporion` (the same JSON `--json` prints; the last 100 are kept). Pages appear by pid only,
never by path (invariant 8). `.lock` — the lock every maintenance write takes (apply runs,
`index:rebuild`). Nothing here is needed to rebuild anything: deleting the directory loses only
the run history.

## 3d. `data/settings.yaml` — this instance's settings (decided 2026-09-26)

Edited from Admin → Settings and Admin → AI (`Service\InstanceSettings`), one section per save, each audited
`settings.change` with the keys that changed. YAML, written atomically; hand edits are fine, the
next save rewrites the file (comments are not kept). It mirrors the config keys the code reads and
is laid over `conf/local.php` at every boot, for the front controller and `bin/reporion` alike;
`conf/local.php` keeps only paths and secrets, plus fallbacks until the first save. An unreadable
file is ignored (the fallbacks apply), never a broken site. It holds the AI server's API key
(`ai.api_key`, the owner's choice 2026-09-27), so it is written `0640`; the admin screen shows only
whether a key is stored.

```yaml
site:
  title: 'Imagistică Mioveni'        # the site name everywhere (t('app.name'))
  tagline: 'Rapoarte imagistice'     # sign-in page
  base_url: 'https://reports.example.ro'   # verification links in exports, feeds, doctor
  home_page: 'site:home'
  timezone: Europe/Bucharest
  icon: 'icon.png?v=82d3d022c3'      # data/site/icon.{png|ico|gif|webp}, set by the upload
sites:                               # printed letterhead + devices, per site code
  mioveni:
    name: 'Spital Orasenesc Mioveni'
    dept: 'Radiologie'
    address: ''
    phone: ''
    devices:
      MV-MR-01: 'Siemens Aera 1.5 T'
feeds:
  namespaces: [docs, teaching]
export:
  allow_public_export: true
  pseudonymise_public: true
  allow_draft_export: false
pages:
  trash_purge_days: 30
media:
  max_bytes: 8388608
reports:                             # the new-report form: modality → namespace segment
  modality_namespaces: {MR: mri, CT: ct, US: us, XR: xr, MG: mg}
ai:                                  # the AI assistant (phase 15), edited in Admin → AI
  enabled: true
  server: 1                          # which of the servers below is in use (1–3)
  prompt_profile: reports            # the prompt pages in use: ai:profiles:{profile}
  namespaces: [reports]              # where the Assistant is offered (prefix match)
  servers:                           # up to three OpenAI-compatible servers
    - name: 'Local'
      endpoint: 'http://127.0.0.1:8080/v1'   # the …/v1 base
      model: 'qwen2.5:32b'
      api_key: ''                    # if the server needs one — never shown back; the file is 0640
      temperature: 0.3               # '' = not sent, the server decides (absent: 0.3)
      top_p: 0.8                     # '' = not sent (absent: 0.8) — Anthropic refuses both, blank one
      max_tokens: 0                  # 0 or '' = the server decides
      timeout: 120                   # seconds
      external_ack: false            # the owner's yes that de-identified text may leave for it
    - {name: 'OpenRouter', endpoint: 'https://openrouter.ai/api/v1', model: '…', api_key: '…', external_ack: true}
    - {name: '', endpoint: '', model: ''}
plugins:                             # Admin → Plugins (docs/architecture-api.md §5)
  enabled: [hipobridge]              # laid over conf/local.php's plugins.enabled
  settings:                          # per plugin id, validated by its plugin.json settings
    hipobridge:
      url: 'http://127.0.0.1:44660'
      username: 'svc-reporion'
      password: '…'                  # type secret — never shown back; the file is 0640
```

Each entry under `sites` may also carry `accession_code` (e.g. `MV`) — `{SITE}` in accession
numbers; empty means the site code upper-cased.

## 3e. `order_ref` and `imported_from` — reports tied to another system (2026-09-29)

`order_ref: '{system}:{Type}/{id}'` (e.g. `hipobridge:ServiceRequest/1761733`) names the order a report
answers in another system — set by a plugin (the guided form's `report.prefill`, or an import). It is
never curated, never duplicated, and `Index::findByOrderRefs()` finds the page for a ref, through the
listing predicate, so a plugin can tell which of its orders already have a report. A report imported
from another system also carries `imported_from` (as the archive importer's pages do) and
`radiologist` — who signed it there; it is `status: archived`, never signed here.

The report such priors are imported for gets one revision that only fills blanks (D38): from the HIS
patient `patient.name`/`cnp`/`sex`/`born`; from the order of the exam it answers `referrer`,
`indication`, `order_ref`, `exam_title` and `region`, plus `study_date` (or its time) only when the
order's day is the path's `yymmdd`, and `modality` only when it maps to the path's modality
namespace. `site` is never written — the path already says it. The body is never touched.

## 4. Share tokens

`meta.json.share_token` stores a **hash**, never the token itself:

```json
{"share_token":{"hash":"sha256:7b1e…","created":"2026-09-22T10:02:00+03:00","expires":"2026-10-22T10:02:00+03:00","uses":3,"max_uses":null,"note":"pentru dr. Neagu"}}
```

The token handed out is 32 bytes of `random_bytes`, base64url, shown **once** at creation.
`GET /s/{token}` hashes the input and compares in constant time. Default expiry 30 days,
configurable; expired tokens are matched and rejected with 404 (not 410 — the existence of the
page is still not disclosed). Revoking sets `share_token` to `null`.

## 5. `_defaults` — namespace-level creation defaults (D6)

A page named `_defaults` in any namespace. Frontmatter only, no body; applied to new pages
created in that namespace and its children, nearest-first, key by key.

```yaml
---
visibility: private
site: mioveni
device: MV-MR-01
modality: [MR]
template: templates:mri:cerebral-nativ
---
```

These are **creation defaults**, not inherited rules evaluated on read. Changing `_defaults`
never changes an existing page.

## 6. Audit lines — `data/audit/YYYY-MM.ndjson`

```json
{"ts":"2026-09-22T09:41:11+03:00","actor":"owner","action":"page.save","pid":"01JB…","path_hash":"sha256:3f9a…","ip":"10.1.4.22","ua":"Firefox/131","rev":8,"outcome":"ok"}
```

`action` ∈ `page.read|page.create|page.save|page.revert|page.sign|page.move|page.delete|page.restore|page.purge|page.publish|media.attach|maintenance.run|settings.change|export|share.create|share.use|ai.call|ai.refused|token.create|token.revoke|profile.change|login|login.fail|password.change|password.reset|index.rebuild`.
Action-specific fields are added to the line (`to` for a revert, `batch` for an import, `format`
for an export; `ai_action`, `provider`, `context`, `ms`, `usage` and on failure `reason` for
`ai.call` — never the prompt or the answer, invariant 8). `login.fail` names the attempted username only when it is username-shaped —
anything else is recorded as `(invalid)`, so a password typed into the wrong field never lands
in the log.

**Built so far (`Audit\AuditLog`):** page create/save/revert/sign/delete from the browser, the
JSON API and the import CLI (`actor: import`), exports, logins, password changes (own, with
`outcome: denied` for a wrong current password) and owner resets (`account` field). `page.read` of non-public
pages is not recorded yet. Recording is best-effort — a failed append goes to the PHP error log
and never fails the write it describes; `bin/reporion doctor` checks the directory is writable.
**Never** the page path in clear (D1) — `path_hash` only. Append-only, outside SQLite, rotated
monthly, never pruned automatically.

## 7. `Idempotency-Key`

Stored in `data/idempotency.sqlite` (separate from the index, since it is state rather than
cache): `key TEXT PRIMARY KEY, actor, route, request_sha, response_json, status, created`.
A repeat of the same key within 24 h returns the stored response verbatim. A repeat with a
different `request_sha` is `422`, not a silent overwrite.

## 8. Frontmatter canonicalisation (for signing)

Before hashing a revision (D3), bytes are canonicalised so a cosmetic edit cannot change the
digest: LF line endings, no trailing whitespace, single trailing newline, frontmatter keys
emitted in `conf/schema` declaration order (recursing into nested object fields, e.g. `patient`;
unknown keys keep their original relative order, appended last), lists in flow style
(`[a, b]`), `null` omitted rather than written. **Scalar quoting is whatever
`Symfony\Component\Yaml`'s dumper decides**, not hand-controlled — its quoting heuristics are a
black box this project doesn't second-guess (a plain string like `RM cerebral` does get quoted,
even though plain YAML would allow it bare). That's a deliberate relaxation of "unquoted where
YAML allows": the property the signature digest actually depends on is *determinism*, not any
particular quote style, and the dumper's choice is a pure function of its input either way.
`Support\Canonical::bytes()` is the only implementation; `tests/Support/CanonicalTest.php`'s
`testReCanonicalisingSignedBytesIsANoOp` is the test asserting that re-canonicalising signed bytes
is a no-op.

`conf/schema/*.json` is ordinary deployed config, not append-only like a page's own history — a
field added, removed or reordered there after a report is signed must never turn an unchanged
document into "does not match". So each `signatures[]` entry also carries `field_order`
(`Support\Canonical::orderSpec()`'s output: field names in the order used, recursing into nested
object fields): `Service\Revisions::signature()` reorders by *that*, not by resolving
`conf/schema/*.json` fresh, when checking a past signature. A signature recorded before this field
existed has no `field_order` and falls back to today's schema, same as before.

## 9. Colon paths in URLs

The path separator is `:` on disk and in text, and stays `:` in URLs —
`/reports:mri:mioveni:260922-ionescu-maria`. Colons are legal in a path segment per RFC 3986
and need no encoding. Namespace URLs carry a trailing colon (`/reports:mri:`) which is how the
router distinguishes a namespace index from a page. `/` inside a path segment is rejected at
slug normalisation.

## 10. Accounts — `data/users/{username}.json`

```json
{"username":"mihai","password_hash":"$argon2id$…","is_owner":false,
 "grants":[{"namespace":"reports:mri","role":"editor"}],"active":true,
 "created":"2026-09-25T10:00:00+03:00","updated":"2026-09-25T10:00:00+03:00",
 "display_name":"Dr. Mihai Popa","title":"Medic primar radiologie"}
```

Disk-authoritative (D36), written atomically by `Auth\FlatFileUserStore`. `display_name` and
`title` are optional (empty string when unset, absent in records written before they existed):
they are the signer block a printed report shows for the account that signed it — a report
falls back to the username when `display_name` is empty. Since phase 13 the account edits them
itself on `/profile`, and an owner still can in Admin → Users.

`tokens` (phase 13, absent before): the account's API tokens, each
`{"id":"k3m9x2qa","name":"dictation script","scope":"write","created":"2026-09-27T10:00:00+03:00",
"last_used":"2026-09-27","hash":"<sha256 of the secret, hex>"}`. The token itself —
`rpn_{base64url(username)}.{id}.{secret}`, `id` 8 base32 characters, `secret` 32 random bytes
base64url — is shown once and never stored. `last_used` is a date, written at most once a day.
A malformed entry is dropped when the record is read (it can no longer authenticate).


## 11. Report body headings (decided 2026-09-27)

Every report body has one heading shape, whether it was imported or created in the app:

```markdown
# {patient name}          D30's name heading: on screen only (the header shows it too)
**Indication**, date…     shared text above the first exam (optional)
## {exam}                 one per exam — "IRM genunchi drept"
### {section}             Indicație, Tehnică, Descriere, Concluzii, Recomandări…
#### {sub-part}           deeper structure inside a section ("Segment cervical")
```

- A single-exam report has one `##`. Several exams done together have one `##` each (roadmap
  phase 12 builds on this), with a conclusion per exam at `###`. An archive report with
  **one shared conclusion** after several exams keeps it as `## Concluzii`, a sibling of the
  exams.
- `exam_title` names the exam(s): the heading's text for one exam, `A + B` for several.
- **What prints.** Exports and the public layout drop the name heading, and also a lone `##`
  whose text is the exam title, which the printed title already shows
  (`Support\ReportName::forExport()`). The patient's name prints once, in the patient block
  (D1 as amended 2026-09-27). The signed-in page view drops the name heading as well: the
  page header already shows it.
- **Getting there.** `bin/reporion pages:normalize-headings` (Admin → Maintenance → *Report
  headings*, `Support\HeadingNormalizer`) rewrites the imported archive's shapes (name at `##`
  or `###`, exam and sections side by side one level below). Only the `#` marks change, never
  the text; the rendered text is identical before and after, and a second run changes nothing.
  It fills a missing `exam_title` from the exam heading(s). Signed reports and any shape the
  rules cannot place are listed by pid, to fix by hand. The new-report form writes
  `# {name}` and `## {exam}` from the start.

## 12. Multi-exam reports — `exams:` (roadmap phase 12, decided 2026-09-27)

Several exams done together for one patient — both knees, three spine regions — are **one
report**: one file, one frontmatter, one signature (D3/D37). A report is multi-exam only when its
frontmatter says so:

```yaml
exam_title: 'IRM genunchi drept + IRM genunchi stâng'   # what exports print as the title
region: [msk]                                           # the exams' regions, once each
exams:
  -
    title: 'IRM genunchi drept'
    region: [msk]
    accession: MV-MR-26-0412
  -
    title: 'IRM genunchi stâng'
    region: [msk]
    accession: MV-MR-26-0413
```

- **The body's exams** are its `##` headings, in order: the Nth is `exams[N-1]`, and what is above
  the first is the shared head (the name heading, the indication). The rule is a line rule, the
  same in PHP (`Support\Exams`) and in the editor: a line of up to three spaces, `##`, then a
  space, a tab or the line's end, outside a fenced code block. `###` and deeper never split.
- **A conclusion per exam**: a `### Concluzii` (or `Concluzie`, any case, with or without
  diacritics) directly under that exam's `##`.
- **Signing** needs the exams whole: as many `##` as entries, each titled (by its entry or its
  heading), each with its conclusion. Anything less is a warning on the page and blocks signing,
  never saving (D7). `summary` stays one per file.
- **Accessions** (D20): one per exam, in `exams[].accession`; no top-level `accession`. The index
  keeps every exam in `page_exams`; `pages.accession` holds the first. The counter's seed reads
  every `accession:` line in the frontmatter, so no exam's number is ever issued again. An
  accession typed whole in search finds its report by any exam.
- **Anchors**: the page view, print and the editor preview give a multi-exam report's top-level
  `##` lines the ids `exam-1`, `exam-2`… (so `/{path}#exam-2`), in both parsers (D17); a setext
  `---` heading is not an exam. The editor
  opens on one with `/{path}/edit?exam=2`.
- **Exports** keep every exam heading and print every exam's accession in the header; the file is
  named by the first.
- A duplicate keeps `exams:` without the accessions.
- A report without `exams:` is never split, whatever its headings.

## 13. Assistant prompt pages — `ai:profiles:{profile}:…` (phase 15, 2026-09-27; table-sourced 2026-09-28)

The AI assistant's actions are ordinary pages (history, grants, search for free). The profile's own
page, `ai:profiles:{profile}`, carries the rail's **first** markdown table — which actions exist,
their order, and their label/tooltip/icon/result — read by `Support\ProfileTable` /
`Service\Ai\Actions::forPage()`. A second table (a "Disabled Actions" / "not implemented" heading,
still a common habit in these pages) is never reached — moving a row out of the first table is how
an action stops appearing, nothing else to flip:

```markdown
---
title: Radiology Reports Profile
visibility: private
---

| ID         | Label      | Tooltip            | Icon        | Result  |
|------------|------------|---------------------|-------------|---------|
| conclusion | Conclusion | Create conclusion   | flag-checkered | append |
| summarize  | Summarize  | Summarize text     | notepad     | show    |

## Disabled Actions

| ID     | Label  | Tooltip       | Icon          | Result  |
|--------|--------|---------------|---------------|---------|
| custom | Custom | Custom prompt | pencil-simple | replace |
```

Each row's id names a page `ai:profiles:{profile}:{id}` — its body is the prompt, and its own
`label`/`tooltip`/`icon`/`result`/`order`/`enabled` frontmatter (the phase-15 shape) is no longer
read; only its body (and `…:system:{id}`, below) matters now:

```yaml
---
title: Conclusion
visibility: private
---

<report>
{text}
</report>
…the prompt…
```

A row with no matching page (or an empty one) contributes nothing — same as it not being in the
table at all.

- **Icon** (the table's Icon column): a filename with an image extension
  (`summary.png`/`.jpg`/`.jpeg`/`.gif`/`.webp`/`.svg`) is served from `assets/img/ai/`; a bare name
  (letters/digits/hyphens, no extension) is a Phosphor icon, rendered `ph-{name}`; anything else — a
  unicode character — is an emoji (`✦` when the column is empty).
- **Result** — `show | append | replace | insert`, what the rail does with the answer: `show` opens
  it in a modal (Insert-at-cursor still offered from there); `replace` replaces the selection, else
  the whole body past its heading(s), never the frontmatter; `append` adds to the end, or merges into
  a matching `###` section the answer starts with; `insert` writes at the cursor.
- `ai:profiles:{profile}:system` is the profile's system prompt (`ai:profiles:default:system` when it
  has none); `ai:profiles:{profile}:system:{action}` is appended for that action.
- Which profile a page uses: the one in use, `ai.prompt_profile`, on the namespaces in `ai.namespaces`
  (§3d, chosen in Admin → AI; 2026-09-27 — before, `ai.profiles` mapped namespaces to profiles, and
  is still read until the next save). Pages elsewhere get no Assistant. Several profiles can exist
  side by side (e.g. `reports` and `reports-short`) and be switched.
- Placeholders, filled only by `Service\Ai\Context` (de-identified, D15/invariant 8): `{text}`
  `{template}` `{previous}` `{previous_date}` `{current_date}` `{current_time}` `{snippets}` `{examples}`
  (frontmatter `ai_examples:`) `{exam}` `{modality}` `{region}` `{age}` `{sex}` `{prompt}` `{action}`.
- They are read whatever the caller's grants (the instance's configuration); changing them is the
  ordinary page rule. `bin/reporion ai:import-prompts` brings DokuLLM's profile over, and now also
  writes the destination's own first table from the source's enabled rows — Admin → AI's page
  listing (`Actions::pages()`) still reads each page's own frontmatter, for showing disabled/unlisted
  actions too.
