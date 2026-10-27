# On-disk and wire formats

The specs describe *what* is stored; this file pins *exactly how*. Anything ambiguous here
becomes a bug the first time two implementations disagree. Every format below is covered by a
test fixture.

## 1. Slug collisions: the case the specs missed

Two exams for the same patient on the same day produce the same path
(`260922-ionescu-maria`). This is common: a CT and an MR, or a repeat study after contrast.

**Rule.** On create, if the path exists, append `-2`, `-3`, … to the *last* segment:

    reports:mri:mioveni:260922-ionescu-maria
    reports:mri:mioveni:260922-ionescu-maria-2

The suffix is allocated inside the same journal-protected create that mints the pid, so two
concurrent creates cannot collide. The suffix is **never** reused after a delete: the trash
still holds the original, and a purge does not free the slug (the `redirect` table remembers it).
The UI shows the collision explicitly at create time:"a page for this patient and date
exists: `…-maria` (CT, 09:14). Create `…-maria-2`?", because silently creating a second page
is how a report gets written in the wrong document. **Built** in the new-report form (phase 7):
the check matches the patient by key (strong or weak, D11) and date across all modalities, and
creating needs an explicit confirm. Since 2026-10-10 "Create" opens the editor on the first free
path (`-2`, `-3`) without writing it; the editor's first Save creates the page there, exclusively
taken meanwhile, it says so with a link instead of quietly moving to the next suffix.

Different modality or site already differ earlier in the path, so the collision only ever
applies within one namespace.

**A namespace is not a collision** (decided 2026-09-26). A page and a namespace may share a name,
as in DokuWiki: `reports:mri:mioveni` (the site's description) is the file `mioveni.md` beside the
directory `mioveni/` that holds the reports under `reports:mri:mioveni:*`. The two never share a
directory, so creating the page does not touch the namespace and does not take `…mioveni-2`; only
an existing page (a live row, a page file or an attic zip at the path) gets the suffix.
*Rewritten 2026-10-20 (D54): the page used to claim the namespace directory with an atomic `mkdir` of its `rev/`.*

**A redirect is not a collision either** (decided 2026-10-12). The `redirect` row a move leaves
holds no text and no history (those went with the page, whose `move` rows record it), so a create at that
name removes the row and takes the path: a page moved to a proper name just to free the old one
can be replaced by a better page there. Links to the old name reach the new page from then on.

**A create *of a path* is exclusive** (decided 2026-10-09). The suffix is for creates that mean
"a new page about this": a second same-day report (after the confirm above), an import, a
duplicate, a join. A create that names its path:`POST /api/v1/pages`, the editor on a new path,
`/new` with the path typed: takes that path or nothing: a page there is `PageExistsException`,
answered `409 exists` by the API and, in the browser, the form again with the text kept and a
link to edit the existing page. A repeated request therefore never leaves a stray `-2`. A bare
namespace is still not "a page there".

## 2. `data/journal/YYYY-MM-DD.ndjson`

One line per write intent, appended and `fsync`ed before any page file is touched. Replayed on
boot; a line with no matching `done` is an incomplete write.

```json
{"ts":"2026-09-22T09:41:10.882+03:00","op":"save","pid":"01JB8X4MT7QK2V9Z0C3R5H6ND","path":"reports:mri:mioveni:260922-ionescu-maria","rev":8,"base_rev":7,"body_sha":"9f2c…","actor":"owner","state":"intent"}
{"ts":"2026-09-22T09:41:10.941+03:00","pid":"01JB8X4MT7QK2V9Z0C3R5H6ND","rev":8,"state":"done"}
```

`op` ∈ `create|save|revert|minor|move|delete|restore|purge|sign|import`. Ops that keep the page's rev
(`move`, `restore`, `purge`, `delete`, `minor`, `sign`: `Journal::KEYED_OPS`) put their op in the
intent's key, so a `done` line closes the right one. Signing is journalled since D54 (2026-10-20): it
rewrites the attic zip's `revlog.json` and then the page's state row. Recovery is idempotent:
replaying a `done` line is a no-op; replaying an `intent` re-syncs the page from its attic zip: the
revision is in the zip and the page file is not: the file is written from the zip's entry; the file
is there and the state row is behind: the row is mirrored from `revlog.json`; the index behind:
reindexed. An intent whose revision is not in the zip is discarded; one a later write has already
superseded (the row's rev is higher) is closed without touching the page file. Every
outcome except `corrupt` appends a `done` line, so nothing is replayed twice. *Was: replay from
`rev/NNNN.md.gz` and `meta.json` (until D54, 2026-10-20).*

**When replay runs** (decided 2026-09-26): on every request, the front controller reads what was
appended since last time and replays intents **older than 60 s**: there is no page write lock, so
a younger intent may be a write still in progress. It takes `journal/.replay.lock` non-blocking; a
request that finds it held just carries on. `bin/reporion journal:replay [--min-age=60] [--dry-run]`
does the same for an operator or a deploy (full scan, pages named by pid). Two helper files sit
beside the journal: `.checkpoint.json` (file, byte offset and the intents still open there) and
`.replay.lock`. With no checkpoint: the first request after this shipped, or after it was deleted
boot replay starts from the journal's current end: intents already open are a backlog of unknown
age, left to `journal:replay --dry-run` and an operator, never replayed unseen by whichever request
comes first.

## 3. Accession counters: the `counter` table of `data/reporion.sqlite`

*Replaces `data/counters.json` (D54, 2026-10-20); the file is no longer read or written.*

Accession sequences (D20) for reports created in the app (`Service\Accessions`, `FileTree::nextAccession()`):
the last number issued per site + modality + year, one row `counter(key, value)` keyed
`{site code, lower-case}:{MOD}:{yy}`:

```
key = "scuc:MR:26"  value = 1764        key = "mioveni:CT:26"  value = 412
```

Allocated in a `BEGIN IMMEDIATE` transaction, just **before** the page is created
so a crash between the two leaves a gap in the sequence, never a duplicate. A key used for the
first time is seeded from the highest number already in any page's `accession:` on disk (`Support\AccessionFormat`),
and every allocation also stays above the
highest number the index holds for that key, so a batch brought in since cannot be collided with.
Formatted with `accession.pattern` / `seq_pad` from config: the live archive reads
`SCUC-MR-23-1764`:`{SITE}` is the site code upper-cased (or the site's `accession_code` from
Admin → Settings), `{MOD}` the schema modality code. A gap is acceptable; a duplicate is not.
The accession is for the team (screen, search, index); it is not printed: an export shows the
PACS's `pacs_accession` instead (§3f, 2026-10-12). Lost with the database, the counters are
re-seeded from the accessions on disk by `db:recover` (docs/architecture-v2.md §10).

## 3b. Attached files: the `media` table (D10/D27)

*Replaces `{page}/media.json` (D54, 2026-10-20).* One row per attached file in `reporion.sqlite`:
`media(pid, sha256, ext, name, bytes, w, h, added, by)`, appended by `Storage::attachMedia()` in one
transaction; it follows the page's pid, so it moves, trashes and restores with the page. The bytes live once in
`data/media/{year}/{sha256}.{ext}` (written first, `link()`-once), so a crash between the two
writes leaves an unreferenced file, never a row without its file: no journal intent.

```json
{"sha256":"8f2c41…e9","ext":"png","name":"Axial T2.png","bytes":48213,"w":512,"h":512,"added":"2026-09-26T11:14:02+03:00","by":"a.barbu"}
```

Not revisioned: attaching is not an edit of the report. The index derives `links.kind = media`
rows from it, which is what `GET /media/…` checks access against. Unreferenced blobs are not swept
yet. The names exist only in the database: `db:recover` cannot bring them back, and `integrity:verify` reports
`media_missing` (a row whose file is gone) and `media_changed` (bytes that no longer hash to their name).

## 3c. `data/maintenance/`: maintenance runs

(Also here: `backup-rsync.json` `{last_attempt, last_success, last_exit, snapshot, files, bytes}` (D49): the rsync backup's status note, epoch seconds, read by `--if-due`, Admin → Settings and `doctor`; a cache of nothing on disk that matters: deleting it makes the next hourly check run a snapshot. `rsync_known_hosts`: ssh's pinned host key of the backup machine.)

`runs/{yyyymmdd-hhmmss-xxxxxx}.json`: one report per maintenance run, from Admin → Maintenance or
`bin/reporion` (the same JSON `--json` prints; the last 100 are kept). Pages appear by pid only,
never by path (invariant 8). `.lock`: the lock every maintenance write takes (apply runs,
`index:rebuild`). Nothing here is needed to rebuild anything: deleting the directory loses only
the run history.

## 3d. `data/settings.yaml`: this instance's settings (decided 2026-09-26)

Edited from Admin → Settings and Admin → AI (`Service\InstanceSettings`), one section per save, each audited
`settings.change` with the keys that changed. YAML, written atomically; hand edits are fine, the
next save rewrites the file (comments are not kept). It mirrors the config keys the code reads and
is laid over `conf/local.php` at every boot, for the front controller and `bin/reporion` alike;
`conf/local.php` keeps only the data path and the session secret; the defaults for everything else are built in (`Kernel::DEFAULTS`), and a section a local.php still carries is only a fallback (`bin/reporion doctor` warns). An unreadable
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
  trusted_proxies: []                # reverse proxies (addresses or CIDR) whose X-Forwarded-For is believed for the client's address; settings.yaml only
sites:                               # printed letterhead + devices, per site code
  mioveni:
    name: 'Spital Orasenesc Mioveni'
    dept: 'Radiologie'
    address: ''
    phone: ''
    letterhead: false                # default off (absent = off, 2026-10-15): true prints the letterhead block on a report's print, PDF, ODT and HTML export; off hides all of it: name/dept/address/phone, the right-hand cell (document kind, PACS accession numbers, study date) and the rule
    devices:
      MV-MR-01: 'Siemens Aera 1.5 T'
      MV-CT-01:                      # a device a PACS scanner is linked to (2026-10-07)
        name: 'Siemens Emotion 16'
        pacs: ['SIEMENS Emotion 16 / CTMV01']   # pacs_device names (Manufacturer Model / Station); a station with several values (`A\B`) also matches a name made on `A`
        inst: ['Somatom16']          # fallback (2026-10-12): texts the DICOM InstitutionName may contain
feeds:
  namespaces: [docs, teaching]
export:
  allow_public_export: true
  pseudonymise_public: true
  allow_draft_export: false
mcp:
  access: local               # D56: off | local (default: private addresses only, Claude Code over the VPN) | token (anywhere, a bearer token only, no OAuth: claude.ai's connector request headers) | on (also OAuth sign-in)
  write_anywhere: false       # create_page/update_page also from outside the local network
  redirect_uris: []           # OAuth return addresses allowed besides Claude's callbacks and http://localhost (settings.yaml only)
  reports: false              # D56 phase 2: /mcp serves reports de-identified (by pid), off by default
  gate: ''                    # the value of the X-Reporion header the web server requires on /mcp (16-128 of A-Za-z0-9_-); PHP never checks it, Admin → Settings shows the server rule
pages:
  trash_purge_days: 30
media:
  max_bytes: 8388608
editor:
  save_stays_open: false             # true: Save returns to the editor at the new revision; Cancel reads Close (2026-10-10)
debug:
  panels: false                      # true: owners see the collapsed Debug panel in some pages' footer (new-report form: the raw findscu exchange)
backup:                              # the periodic rsync backup (D49), edited in Admin → Settings → Backup
  enabled: false                     # true: `bin/reporion backup:rsync --if-due` (cron) makes a snapshot when one is due; a hand-run needs only a target
  transport: ssh                     # ssh | daemon: an rsync daemon (`rsync://host/module/`), not encrypted: a trusted network only
  host: backup.example.org           # host name or IPv4 address (never starts with `-`)
  port: 22                           # 873 for a daemon
  module: ''                         # daemon only: the module's name
  password_file: ''                  # daemon only: a path to a file with the module's password (mode 600); '' = none. The password is never stored
  user: backup                       # ssh: needs an ordinary shell on the target (rename, ln, rm); daemon: the module's user, '' = none
  remote_dir: /srv/backups/reporion  # (daemon: a folder inside the module, '' = its root) absolute or relative to the ssh home: [A-Za-z0-9._/-], no `..`; holds snapshots/{YYYY-MM-DDTHHMMSS}/ + latest, and conf/local.php.{name}
  ssh_key: /var/www/.ssh/reporion_backup  # a path to a private key file, no spaces; '' = the user's default key. The key itself is never stored
  bwlimit: 0                         # KB/s; 0 = none
  include_conf: false                # also copy conf/local.php (it holds the session secret)
  keep: week                         # week | month | all: snapshots older than 7 / 30 days are removed from the target; the newest never
  interval_hours: 24                 # a new snapshot once the last is this old (1–720)
reports:                             # the new-report form: modality → namespace segment
  modality_namespaces: {MR: mri, CT: ct, US: us, XR: xr, MG: mg}
ai:                                  # the AI assistant (phase 15), edited in Admin → AI
  enabled: true
  server: 1                          # which of the servers below is in use (1–6)
  prompt_profile: reports            # the prompt pages in use: ai:profiles:{profile}
  namespaces: [reports]              # where the Assistant is offered (prefix match)
  fallback_profile: default          # the profile for every other page; '' or absent: none there
  profile_map: {}                    # {namespace: profile}, e.g. {reports:mri: mri} (D47, Admin → AI routing table, 2026-10-14: rows of namespace + profile, saved as these four settings)
  embed_server: 1                    # Similar reports (phase 34e): the server of the one embedding model; null: off
  embed_model: 'nomic-embed-text'    # its model name: one for the instance, not one per server
  embed_min_score: 0.5               # 0–1: a report scoring lower is not listed; null or absent: 0.5
  embed_common_min: 5                # a conclusion this many reports share word for word is a stock one, left out; 0: off; null or absent: 5
  servers:                           # up to six OpenAI-compatible servers (phase 33a)
    - name: 'Local'
      endpoint: 'http://127.0.0.1:8080/v1'   # the …/v1 base
      api_key: ''                    # if the server needs one: never shown back; the file is 0640
      timeout: 120                   # seconds
      external_ack: false            # the owner's yes that de-identified text may leave for it
      fallback: ''                   # another slot (1–6) when this one cannot answer: 34f
      model_filter: ''               # which models its lists show: `free`, `qwen|llama` (any case) or /…/i (33c)
      tiers:                         # per model alias; '' = not sent, the server decides
        normal: {model: 'qwen2.5:32b', temperature: 0.3, top_p: 0.8, top_k: '', min_p: '', max_tokens: '', extra: {}}
        lite:   {model: '', temperature: '', top_p: '', top_k: '', min_p: '', max_tokens: '', extra: {}}   # no model: the normal alias, parameters too
        expert: {model: 'qwen3:235b', temperature: '', top_p: '', top_k: 20, min_p: 0.05, max_tokens: 32000, extra: {}}
    - name: 'Cloud'
      endpoint: 'https://llm.example.com/v1'
      api_key: '…'
      external_ack: true
      tiers:
        normal: {model: 'claude-x', extra: {reasoning_effort: low}}   # newer Claude models refuse temperature/top_p/top_k
    - {name: '', endpoint: '', tiers: {}}
                                     # `extra`: a JSON object merged into the request last, ≤ 2 KB,
                                     # never model/messages/stream/stream_options.
plugins:                             # Admin → Plugins (docs/architecture-api.md §5)
  enabled: [hipobridge]              # laid over conf/local.php's plugins.enabled
  settings:                          # per plugin id, validated by its plugin.json settings
    hipobridge:
      url: 'http://127.0.0.1:44660'
      username: 'svc-reporion'
      password: '…'                  # type secret: never shown back; the file is 0640
```

Each entry under `sites` may also carry `accession_code` (e.g. `MV`):`{SITE}` in accession
numbers; empty means the site code upper-cased.

## 3e. `order_ref` and `imported_from`: reports tied to another system (2026-09-29)

`order_ref: '{system}:{Type}/{id}'` (e.g. `hipobridge:ServiceRequest/1761733`) names the order a report
answers in another system: set by a plugin (the guided form's `report.prefill`, or an import). It is
never curated, never duplicated, and `Index::findByOrderRefs()` finds the page for a ref, through the
listing predicate, so a plugin can tell which of its orders already have a report. A report imported
from another system also carries `imported_from` (as the imported archive's pages do) and
`radiologist`: who signed it there; it is `status: archived`, never signed here.

The report such priors are imported for gets one revision that only fills blanks (D38): from the HIS
patient `patient.name`/`cnp`/`sex`/`born`; from the order of the exam it answers `referrer`,
`indication`, `order_ref`, `exam_title` and `region`, plus `study_date` (or its time) only when the
order's day is the path's `yymmdd`, and `modality` only when it maps to the path's modality
namespace. `site` is never written: the path already says it. The body is never touched.

## 3f. `study_uid` and `pacs_accession`: reports tied to a PACS study (2026-09-30)

`study_uid` is the DICOM Study Instance UID of the exam (digits and dots, ≤ 64), `pacs_accession`
the accession number the PACS gave it (DICOM SH, ≤ 16): kept apart from Reporion's own
`accession` (D20). `pacs_accession` is the number the printed report shows (print, PDF, ODT: one per exam in order, a duplicate once, `Support\Exams::pacsAccessions`); Reporion's own `accession` is never printed (2026-10-12). A report with no `pacs_accession` prints no number, and a pseudonymised copy (anonymous export of a public page) prints none. Set by the dicom plugin (D39): from the guided form (`report.prefill`) or by
linking a report to a study in its *PACS* tab, which fills only blanks:`patient.name`/`cnp`/`sex`/
`born`, `exam_title`, `referrer`, `study_uid`, `pacs_accession`, `pacs_institution` (InstitutionName), `pacs_device` (manufacturer model / station: whatever the study-level answer carries; many PACS send these only per series; a report started from a DICOM file carries it from the start, bounded to 64 characters, so its PACS tab can link the scanner to a device) and `modality` / the study time
only when they agree with the report's path. Never curated, never duplicated;
`Index::findByStudyUids()` finds the page for a UID through the listing predicate. A report may carry
both `order_ref` (the HIS order) and `study_uid` (the PACS study). A multi-exam report keeps one
`study_uid` / `pacs_accession` per exam instead (§12).

The dicom plugin's settings in `data/settings.yaml` include a `sites` table (a plugin setting type,
docs/architecture-api.md §5): one row per site code of `sites`:

```yaml
plugins:
  settings:
    dicom:
      findscu: /usr/bin/findscu        # full path; echoscu next to it
      servers:                         # aet: the PACS; calling_aet: us, as that PACS knows us
        mioveni: {host: 192.0.2.50, port: 104, aet: MVPACS, calling_aet: RP_MIOVENI}
        scuc: {host: '', port: 104, aet: '', calling_aet: ''}    # no PACS configured
```

## 3g. `data/tags.yaml`: the tag dictionary (phase 20, 2026-09-29)

Per tag, a group, an ICD-10 code and its synonyms: edited in Admin → Tags (`Service\TagDictionary`),
written atomically, each save audited `tags.dictionary` with the tag. Disk is authoritative
(invariant 1): the index keeps no copy, and the old `tags` table in `index.sqlite` stays unused.
Tags themselves still live in page frontmatter; an entry needs no page and a tag needs no entry.

```yaml
PI-RADS:
  group: diagnosis
  icd10: C61
  synonyms: [pirads, prostate score]
hernie:
  synonyms: [hernia, herniar, hernie de disc]
```

Each entry with synonyms is one D28 group: a search for the tag or any synonym: as the whole
query, or as one of its words: matches any term of the group (`Index\Sqlite::search()`, case,
diacritics and separators folded, `Support\Slug::fold()`). Until the file is first written, the
shipped `resources/synonyms.txt` seeds it (first term of a line the tag, the rest its synonyms); after
that the file is ignored. Merging tags moves the merged ones' details to the target and keeps their
names as its synonyms. A blank entry is removed; an unreadable file reads as empty and is left alone.

`_rejected` (2026-10-15) is not a tag: the list of tags:review proposals the owner ticked "never
suggest" in Admin → Tags, each as `TagProposals::key()`: the proposal's tags, sorted, comma separated,
then ` → ` and the target (nothing after the arrow for a drop). A later run does not make them again.

```yaml
_rejected:
  - 'hernie discală L4-L5 stângă → hernie discală'
  - 'de urmărit →'
```

## 3h. `checklist`: what a template's exam must address (2026-10-02, roadmap phase 26)

A template page may carry a `checklist:`: a YAML list of lines (at most 80):

```yaml
checklist:
  - "# Menisci"                               # a section heading
  - "Menisc medial | menisc medial"           # an item | its keywords, comma-separated
  - "Ligamente încrucișate | LIA, LIP, încrucișat"
  - Revărsat articular                        # an item without keywords
  - Cartilaj: [cartilaj, condral]             # YAML's map form, the same as "Cartilaj | cartilaj, condral"
```

`|` separates the label from its keywords because labels hold commas (as XRayVision's templates
do). Nothing is copied into a report: the editor reads the checklist of each exam's template
(`exams[].template`, else the report's `template` for its one exam) when it opens, so changing a
template's list changes it for every report made from it. The AI prompts get it as `{checklist}`
(the exam in front's list, as "- item" lines). Never printed or exported.

**Ticks.** In the editor's rail each item can be ticked by hand. Ticks are kept in that browser for
that revision only and never saved (D18); they are a working aid, not a record.

**"not mentioned".** An item is marked *not mentioned* when **none** of its keywords occurs in its
exam's text. It is a reminder, nothing more:

- *How it matches.* The exam's text and the keywords are compared in lower case, without
  diacritics (`ă â î ș ț` → `a a i s t`) and with runs of spaces as one, as plain substrings: a
  keyword matches anywhere, also inside a word. Case and diacritics in keywords therefore do not
  matter. Prefer stems:`aort`
  matches *aortă*, *aortei*, *aortic*; `radacin` matches *rădăcina*, *rădăcinii*.
- *Which text.* Only that exam's text: in a report with several exams, each exam's checklist is
  checked against its own `##` part. Rechecked while typing (after a 0.3 s pause).
- *No keywords, no mark.* An item without keywords is never marked; it is only ticked by hand.
- *Presence, not meaning.* A negative finding counts as mentioned:"fără anevrism de aortă"
  satisfies `aort`, which is intended: the item was addressed. Conversely a keyword can be
  satisfied by an unrelated sentence (`radacin` by "conflict disco-radicular"), so an item whose
  keywords also occur in a template's normal lines is flagged only when those lines are deleted.
- *Not a gate.* It never blocks saving or signing, and ticking an item does not clear the mark.

Good items are the ones easy to forget; keywords that the template's own text does not contain
make the item flagged on every new report until the reader writes a line about it.

**Edited as a form** (phase 31, 2026-10-09): the template's Metadata view shows the list as rows
sections, items with their keywords, and marks each item against the template's text as above.
It writes the same lines (`# Section`, `Label | k1, k2`, `Label`); a line it cannot read is kept
as written. The map form is written back as `Label | keywords`.

## 3i. `reference`: the reference page for a template's exams (2026-10-02, roadmap phase 25)

A template page may name one `reference:` page: the classifications, norms and protocols for its
kind of exam, typically under `radiology:` (the knee page, the brain page):

```yaml
reference: radiology:msk:genunchi
```

Like `checklist`, nothing is copied into a report: the report view's *Reference* button opens the
page its exams' templates name (`exams[].template`, else the report's `template` for its one exam)
in a panel sliding in from the right; in the editor, where the right side is the rail with the
Assistant, it is the rail's *Reference* section (an accordion. Reference, Checklist, Assistant
one open at a time). Read and rendered when the report is shown.
A page the reader cannot see, or one that does not exist, gives no button (invariant 6). The
template's Metadata view picks it from the pages under the reference namespaces
(`references.namespaces` in `data/settings.yaml`, Admin → Settings → Reports; default
`radiology`). Moving the reference page (`page:move`, the bulk move) rewrites it in every unsigned
template. Never printed or exported.

## 3i-b. A template page (D45, 2026-10-13)

A page under `templates:` (not `templates:snippets:`) has this shape:

```markdown
# Art: Genunchi          the template's name: what pickers list (the page's title, §3k)
## IRM Genunchi          the exam's name: the report's `## exam` and `exams[].title`
### Tehnica              its sections, inserted into a report by Insert Template
```

- **`#`** is the template's name and may differ from the exam's (*Art: Genunchi* / *IRM Genunchi*).
  It is the page's title, so the new-report form, the editor's template pickers and the DICOM
  worklist's suggestion list the template by it. A frontmatter `title`, if the page has one, still
  overrides it (§3k).
- **`##`** is read when a new report is made (`Support\TemplateHeadings::exam()`): the exam's title
  when the form's title is blank. It counts **only after a `#`**: a template of the old shape (no
  `#`) has `##` that are sections, so it names no exam and its own title is used, as before.
- **`region`** in the template's frontmatter is the exam's default region: the form ticks it when the
  exam has none ticked (`data-region`, `assets/js/new-report.js`), the user may change it, and the
  server uses it when the form sends none. `protocol` and `template` are copied as before.
- **Not copied:** the template's text, its `#`, `checklist` and `reference` (§3h, §3i): they stay on
  the template and are read live for the writer.
- **Insert Template** (editor) drops the `#` and the `##` right after it: the report has both, and
  keeps the sections; a template with no `#` is inserted whole. The revision-0 comparison
  (`/{path}/revisions`) drops the `#` too.
- **A wrong shape warns, never blocks** (D7): `Support\TemplateHeadings::problems()`:`name.missing`,
  `name.many`, `exam.missing`, `exam.many`, `order`, and `bin/reporion doctor` counts the templates
  that are off. A text template (§3j) has no headings to check. Old-shape templates still start
  reports; moving them to the shape is an edit of each template page.
- **The bracket notation** (2026-10-24, what the assistant's `create` prompt reads): `[a / b]` pick
  one; `[normal sentence. / pathological sentence …]` swap the sentence; `[...]` a blank filled from
  the notes; `[sentence.]` with no choice, kept only when the notes describe it. `Support\TemplateLint`
  warns, never blocks (D7), about what it cannot use well: `bracket` (a line's brackets do not
  close), `slash` (a ` / ` choice outside brackets; the size form `[...] / [...] / [...] mm` is not
  one), `dash` (— or –), `heading` (a bracket in a heading stays in the report), `vague` (a choice
  `modificări`, `nu se aplică`, `descriere`, or a bare `[...]` beside a sentence), plus the shape's
  codes as `shape.*`. Shown to a signed-in reader as one notice in the page view, as `lint` in
  `GET /api/v1/pages/{path}`, and by `bin/reporion templates:lint`. Snippets, page templates
  (`templates:pages`) and text templates are not checked.

## 3j. `format`: a page's body format (2026-10-10, D40)

```yaml
format: text        # absent (or anything else): markdown
```

A page body is markdown unless its frontmatter says `format: text`. A text body is shown exactly as
typed: escaped, in a preformatted block that keeps every space and line and wraps long lines
(`Service\Render::body()`, `.wk-plaintext` on screen and in `assets/css/print.css`). No markup is
read from it: no headings, links, images or tables, so a text page has no table of contents, no
links to other pages (none are indexed), no exam tabs, no conclusion summary (it needs a
*Concluzii* heading) and no exam heading checks before signing; RADS tags are still read from the
whole text (`Support\Rads`). A report's name heading is still dropped wherever it always is
(`Support\ReportName`), so a first line `# {patient name}` never reaches a public page. The
Metadata view sets it (*Format*: Markdown / Text); Markdown leaves the key out. It is signed with
the rest of the frontmatter (§8); the index keeps it in `meta_json` only.

## 3j-b. `breaks`: keep single line breaks (2026-10-24, D55)

```yaml
breaks: true        # absent (or anything but true): CommonMark's own rule
```

In markdown a single line break inside a paragraph is a space: `milk⏎eggs` reads *milk eggs*. A page
with `breaks: true` keeps each such line (`<br>`), the way a note is typed. Only how a line ends
changes; the dialect is the same (`Service\Render::toHtml(…, breaks: true)`, `Support\BodyFormat::hardBreaks()`),
and it applies wherever `Render::body()` renders the page: page view, print, PDF, ODT. The notes app
sets it on every note it saves and asks `POST /api/v1/render` for it in its preview; a note started in Reporion gets it from `FrontmatterGuess::forNewPage()` (`Support\Notes::isNote()`: a direct child of `users:{u}:notes`). A text page
(§3j) is unaffected. Signed with the rest of the frontmatter (§8); the index keeps it in `meta_json` only.

## 3k. A page's title is its first heading (2026-10-13; was "`title` from the first heading", 2026-10-10)

```yaml
title: 'Other display'     # optional; absent or blank: the page is titled by its first heading
```

The title of a page is the text of its body's first level-1 heading (`# …`, up to three spaces before
it, closing `#`s dropped, outside fenced code; `Support\TitleFromHeading::first`), unless the
frontmatter has a non-blank `title`, which then overrides it: for a display that differs from the
heading, or a page with no heading (an assistant prompt, a snippet). `Support\PageTitle::of()` is
the one rule. **Nothing writes `title` on the author's behalf**: not the editor, not the API, not
the new-report form, not an automatic save; and a heading edited later changes the title with it. A
text page (§3j) has no headings, so it is titled by `title` or, failing that, listed by its path.

The index keeps the *effective* title in `pages.title` and `fts.title` (`Index\Sqlite::write()`
tree, palette, search, header, tab title, feeds and pickers read that column; a `title` set shows as
is). `GET /api/v1/search` returns that effective title; `GET /api/v1/pages/{path}` returns the page's
own frontmatter in `meta`, so `meta.title` is the override only and is absent for most pages. Readers
that do not go through the index (print, the public render, the trash list, the Publishing preview,
plugins) call `PageTitle::of()` too.

A report's `#` is its patient's name (§11), so a report made in the app has **no `title` key** at all;
its exam is `exam_title`. `Support\ReportName` treats an app-shape report's (`patient` block +
`exam_title`) first `#` as the name heading whatever its spelling.

**Pages saved before 2026-10-13** may carry a `title` the old auto-fill wrote (equal to their
heading then). It stays an override: such a page keeps that title when its heading is edited, until the
Metadata view's *Title* is cleared. Nothing clears them in bulk; a signed revision's frontmatter is
part of its digest (§8) and is never rewritten. After this change, run `index:rebuild` once (as the
owner of `data/`) so the index lists heading-titled pages by their heading.

## 3l. A user's editor draft: the `draft` table (2026-10-12, D25 amended; D54, 2026-10-20)

The editor posts its whole form to the server a minute after a change that was not saved
(`PUT /api/v1/pages/{path}/autosave`), so work survives a lost browser. One row per page and user in `reporion.sqlite`,
`draft(pid, username, base_rev, raw, form, saved)` (it was `autosave.{username}.json` beside the page's `meta.json`);
as JSON the draft reads:

```json
{"v": 1, "base_rev": 7, "ts": "2026-10-12T09:30:00+00:00", "raw": false, "form": "<urlencoded editor form>"}
```

`base_rev` is the revision the form was made on, `raw` whether it came from the raw-document
editor (else the curated one), `form` the form exactly as the editor would POST it. A draft is
**not a revision**: never in the attic or `revlog`, never signed, never indexed, not audited, one state
transaction without a journal line, at most 2 MB (`FileTree::DRAFT_MAX_BYTES`).
Only an existing page has one. It is removed by its author's next Save, minor Save or revert (and
by the editor when the form returns to clean), follows the page (by pid) on move, delete and restore,
and is deleted by a purge. `autosave.*` is an ordinary page name now.
Restore re-renders the editor from the stored form without running any Save side effect; a draft
whose `base_rev` is older than the current revision is still offered, with a warning, and keeps its
`base_rev`, so Save then shows the conflict screen.

## 3m. A page's comments: the `comment` table (2026-10-19, D52; D54, 2026-10-20)

Comments on a page, kept beside it so the report itself is untouched (Notion's comments). One row each in
`reporion.sqlite`, `comment(id, pid, by, ts, text, quote, rev, resolved_by, resolved_ts)`; it was the sidecar
`comments.json` in the page's directory. As JSON a comment reads:

```json
{"id": "01JB…", "by": "mihai", "ts": "2026-10-19T09:30:00+03:00",
  "text": "Verificați lateralitatea.", "quote": "leziune stângă", "rev": 7, "resolved": null}
```

`id` is a ULID, `by` the username, `rev` the revision current when it was written, `quote` the text
selected in the page or the editor (may be empty), `resolved` `null` or `{"by": "…", "ts": "…"}`.
Limits: at most 500 comments per page (`FileTree::COMMENTS_MAX`, the oldest dropped), `text` at most 4000
characters, `quote` at most 300. Written by `Storage::updateComments()` in one state transaction: no journal line, no revision. **Why not front matter:** a signature
covers the whole front matter (§8), so a comment there would need a new revision and a new
signature. A comment is **not** a revision, not indexed, not signed, and never in print, PDF,
ODT, the API's `meta` or the page file. It follows the page's pid: it moves, goes to the
trash and comes back with the page. Comments exist only in the database: `db:recover` cannot bring them back, so they
are as safe as the last backup of `reporion.sqlite` (§14).
Read by `Service\Comments` (page view panel, editor rail section, `GET /pages/{path}/comments`).
Any signed-in
reader of the page adds, resolves and reopens; delete is the author's or an owner's. Audited
(§6) by pid and comment id, never the text.

## 3n. The page file and the attic zip (D54, 2026-10-20)

*Replaces the page directory (`current.md`, `meta.json`, `rev/NNNN.md.gz`). The full account is docs/architecture-v2.md §3–§5.*

A page `a:b:c` is `data/pages/a/b/c.md` (frontmatter, then body; it equals the newest revision, D2) and
its history `data/attic/a/b/c.zip`:

```
0001.md … NNNN.md   each revision's exact bytes, raw DEFLATE
revlog.json         {"v": 2, "pid": "…", "status": "draft|signed|archived", "archived": null|{…},
                     "revlog": [{n, ts, by, note, bytes, sha256, minor, kind, chain}],
                     "signatures": [{rev, by, ts, alg, digest, parafa, field_order}]}
```

- `Storage` writes a `pid` key as the **last** frontmatter key of every page file it saves; an incoming `pid` is
  ignored (the row's is written). `PageRecord->frontmatter` and the index never contain it. A page converted
  from 1.x keeps its bytes (no `pid`) until its next save.
- A zip is built by `Storage\Attic` over `Support\Zip` (plain PHP, no ext-zip). Every write rebuilds the
  container, copies earlier entries byte for byte, and reads the fsynced temp file back before the `rename()`
  (`AtomicWriter::putChecked()`); a failed check leaves the old zip. The minor-edit exception
  (`FileTree::saveMinor()`) replaces only an unsigned newest entry.
- A path segment may not end in `.md`, `.zip` or `.lock`, contain `.tmp-`, or start with `.`
  (`FileTree::isValidPath`); `rev`, `meta.json`, `current.md`, `comments.json`, `redirect` and `autosave.*` are ordinary names.
- A deleted page is `data/trash/{pid}/` holding `page.md`, `attic.zip` and `path` (the path it had); the `page`
  row's `trashed_at` / `trashed_by` say when and who.
- Moves, redirects, deliveries, the share token, the media manifest, comments, drafts and counters are tables of
  `data/reporion.sqlite` (§3, §3b, §3l, §3m, §4, §4b; the `move` table is `{pid, from_path, to_path, ts, by}`, the
  `redirect` table `{path, pid}`: an old path resolves to the pid's current path).
- `bin/reporion integrity:verify` reads zip, page file and rows against each other (codes in
  docs/architecture-storage-index.md and the command's `--help`).

## 4. Share tokens

*Was `meta.json.share_token` (D54, 2026-10-20): now the `share` table of `reporion.sqlite`, one row per page,
`share(pid, hash, created, expires, uses, max_uses, note)`; `PageRecord->meta['share_token']` still has the old shape.*
It stores a **hash**, never the token itself:

```json
{"share_token":{"hash":"sha256:7b1e…","created":"2026-09-22T10:02:00+03:00","expires":"2026-10-22T10:02:00+03:00","uses":3,"max_uses":null,"note":"pentru dr. Neagu"}}
```

The token handed out is 32 bytes of `random_bytes`, base64url, shown **once** at creation.
`GET /s/{token}` hashes the input and compares in constant time. Default expiry 30 days,
configurable; expired tokens are matched and rejected with 404 (not 410: the existence of the
page is still not disclosed). Revoking deletes the row (`share_token` is `null`).

## 4b. `deliveries`, where a signed report was sent (2026-10-02, roadmap phase 22)

*Was `meta.json.deliveries` (D54, 2026-10-20): now the `delivery` table of `reporion.sqlite`,
`delivery(pid, target, rev, at, by, outcome, extra)`, `target` being the `to` below, `extra` the JSON of the other scalar fields.*
Every attempt to deliver the page elsewhere, oldest first, at most
200 kept (`FileTree::DELIVERIES_KEPT`): today the dicom plugin's *Send SR to PACS* (`to: pacs`). `PageRecord->meta['deliveries']` reads:

```json
{"deliveries":[{"to":"pacs","rev":3,"at":"2026-10-02T14:05:11+03:00","by":"cstroie","outcome":"ok","site":"mioveni","study_uid":"1.2.840…","sop_instance":"2.25.…"}]}
```

`outcome` is `ok` or the refusal's code (`refused`, `unreachable`, `rejected`, `timeout`, `no-tool`,
`failed`). Written by `Storage::recordDelivery()` in one state transaction, never through the frontmatter: no
revision is made and a signature stays valid (D3). Only scalar fields; no patient data beyond the
study UID. Also an audit `report.deliver` line (pid, rev, outcome: never the path).

## 4c. `archived`: a page that is not worked on any more (2026-10-02)

A page starts `archived` only when its frontmatter says so at creation (the imported archive's
`status: archived`, and the HIS reports a plugin brings in, D38), and an edit never takes that
away. Nothing archives an existing page: the `pages:archive` command and `Storage::archive()`
were removed 2026-10-09. There is no way back to draft: archived pages show no Sign button
(D38).

## 5. Namespace-level defaults (D6; `_defaults` dropped 2026-10-13)

The only default a namespace gives its new pages is a starting `visibility`, read from its
description page (below). There are no `_defaults` pages: the idea (D6: `site`, `device`,
`modality`, `template` per namespace) was never built, nothing read such a page, and the new-report
form, the path and the template already supply those values (§3i-b, §15). A page named `_defaults`
is an ordinary page with no effect.

**Namespace description page and starting visibility** (phase 16 F, 2026-10-10; `Service\NamespaceDefaults`). The page named
like a namespace (`reports:mri:mioveni` for `reports:mri:mioveni:`), else `{ns}:_index`, is its
description. Its `visibility` is the level the editor's picker starts on for a new page underneath
(nearest ancestor wins; none, or one the creator cannot see, means `private`). It is read when the
editor opens and never written into the new page by anything but Save: `public` still needs the
acknowledgement (D16), and creators without a picker (API, CLI, `/new`, copies, joins,
duplicates) always start `private`. Existing pages never change with it.

The same page's `sort` (2026-10-22; `Support\NamespaceSort`) says how the namespace lists its own
pages: `title` (the page title, else the page name), `name` (the last path segment) or `updated`
(newest first); unset or any other value keeps the listing's default (path in the namespace table, region
then title in the template pickers). It orders the namespace page's table (and its sub-namespace cards by
`title`/`name`), the New-report template picker and the editor's template picker. Text compares as
Romanian (`ș` after `s`), numbers by value, without regard to case. Not inherited: each namespace
names its own. Edited in the Metadata view of the description page, beside `priority`.

## 6. Audit lines:`data/audit/YYYY-MM.ndjson`

```json
{"ts":"2026-09-22T09:41:11+03:00","actor":"owner","action":"page.save","pid":"01JB…","path_hash":"sha256:3f9a…","ip":"10.1.4.22","ua":"Firefox/131","rev":8,"outcome":"ok"}
```

`action` ∈ `page.read|page.create|page.save|page.revert|page.sign|page.presign|page.join|page.move|page.delete|page.restore|page.purge|page.publish|patient.merge|media.attach|maintenance.run|settings.change|tags.dictionary|export|report.deliver|dicom.file|share.create|share.use|ai.call|ai.refused|ai.test|token.create|token.revoke|profile.change|login|login.fail|password.change|password.reset|index.rebuild|comment.add|comment.resolve|comment.reopen|comment.delete`.
`comment.*` (2026-10-19, D52) carry the pid and the comment's `id`, never its text or the path (invariant 8).
Action-specific fields are added to the line (`to` for a revert, `format`
for an export; `ai_action`, `provider`, `context`, `ms`, `usage`, `failover` and on failure `reason`
(and `status`) for `ai.call`, one line per try: a retry's carries `attempt` (2–4, 2026-10-09): never the prompt or the answer, invariant 8; `rules`/`ai` counts for `page.presign`;
the byte count for `dicom.file`). `login.fail` names the attempted username only when it is username-shaped
anything else is recorded as `(invalid)`, so a password typed into the wrong field never lands
in the log.

**Pseudouser actors (D41, 2026-10-12).** `actor` is `system:summarizer|tagger|importer|pacs|admin` (`system:ai` reserved, not yet written) on the `page.save`/`page.create` lines of maintenance tasks (`pages:summarize`, `pages:tag`, `tags:prune`, `pages:apply-patient-csv`, `pacs:link`) and the HippoBridge import. `tags:prune` writes `page.save` with `reason: tag-remove`. Those lines also carry `on_behalf_of`: the username of whoever ran the CLI (`--actor`) or clicked in Admin → Maintenance / the HippoBridge tab. The revision's revlog `by` and the page's `created_by` name the same pseudouser. A pseudouser is not an account (no `data/users/` file); `page.sign` is never one. `maintenance.run` and the maintenance reports still name the human.

**Built so far (`Audit\AuditLog`):** page create/save/revert/sign/delete from the browser, the
JSON API, exports, logins, password changes (own, with
`outcome: denied` for a wrong current password) and owner resets (`account` field). `page.read` of non-public
pages is not recorded yet. Recording is best-effort: a failed append goes to the PHP error log
and never fails the write it describes; `bin/reporion doctor` checks the directory is writable.
**Never** the page path in clear (D1):`path_hash` only. Append-only, outside SQLite, rotated
monthly, never pruned automatically.

## 7. `Idempotency-Key`, not supported

Dropped (owner, 2026-10-09; roadmap 13c): the header is ignored and nothing is stored for it. A
repeated write is guarded where it matters without it: a save carries its base revision (`409`
on a stale one), signing is idempotent per revision, and a repeated `POST /pages` for a path
already taken is `409 exists`, never a second page (§1, 2026-10-09).

## 8. Frontmatter canonicalisation (for signing)

Before hashing a revision (D3), bytes are canonicalised so a cosmetic edit cannot change the
digest: LF line endings, no trailing whitespace, single trailing newline, frontmatter keys
emitted in `resources/schema` declaration order (recursing into nested object fields, e.g. `patient`;
unknown keys keep their original relative order, appended last), lists in flow style
(`[a, b]`), `null` omitted rather than written. **Scalar quoting is whatever
`Symfony\Component\Yaml`'s dumper decides**, not hand-controlled: its quoting heuristics are a
black box this project doesn't second-guess (a plain string like `RM cerebral` does get quoted,
even though plain YAML would allow it bare). That's a deliberate relaxation of "unquoted where
YAML allows": the property the signature digest actually depends on is *determinism*, not any
particular quote style, and the dumper's choice is a pure function of its input either way.
`Support\Canonical::bytes()` is the only implementation; `tests/Support/CanonicalTest.php`'s
`testReCanonicalisingSignedBytesIsANoOp` is the test asserting that re-canonicalising signed bytes
is a no-op.

`resources/schema/*.json` is ordinary deployed config, not append-only like a page's own history: a
field added, removed or reordered there after a report is signed must never turn an unchanged
document into "does not match". So each `signatures[]` entry also carries `field_order`
(`Support\Canonical::orderSpec()`'s output: field names in the order used, recursing into nested
object fields): `Service\Revisions::signature()` reorders by *that*, not by resolving
`resources/schema/*.json` fresh, when checking a past signature. A signature recorded before this field
existed has no `field_order` and falls back to today's schema, same as before.

## 9. Colon paths in URLs

The path separator is `:` on disk and in text, and stays `:` in URLs
`/reports:mri:mioveni:260922-ionescu-maria`. Colons are legal in a path segment per RFC 3986
and need no encoding. Namespace URLs carry a trailing colon (`/reports:mri:`) which is how the
router distinguishes a namespace index from a page. `/` inside a path segment is rejected at
slug normalisation.

## 10. Accounts:`data/users/{username}.json`

```json
{"username":"mihai","password_hash":"$argon2id$…","is_owner":false,
 "grants":[{"namespace":"reports:mri","role":"editor"}],"active":true,
 "created":"2026-09-25T10:00:00+03:00","updated":"2026-09-25T10:00:00+03:00",
 "full_name":"Dr. Mihai Ion Popa","display_name":"Mihai","title":"Medic primar radiologie","stamp":"A12345","radiologist":true,
 "groups":["mri-team"]}
```

Disk-authoritative (D36), written atomically by `Auth\FlatFileUserStore`. `radiologist` (bool, default
`false`, absent in older records) marks the accounts that may sign (D37, amended 2026-10-13); only an
owner sets it (Admin → Users). `grants` holds only what an owner granted: the account's own namespace
`users:{username}` (D46) is implied by the username and never written here. `groups` (D47, absent = none) lists group names (§10b); `FlatFileUserStore` resolves them on read and never writes the result back, so `grants` stays only what was granted to this account. A name with no `data/groups/` file resolves to nothing (`doctor` warns). `radiologist` is true when the account's own flag or any group's is; an account that is not a radiologist has its grants on `reports`/`reports:*` dropped when read. `full_name`,
`display_name` and `title` are optional (empty string when unset, absent in records written before they existed).
`display_name` is the *short name* the app calls the account by (bylines, the greeting, comments, lists; the
username when empty, `User::shortName()`); `full_name` (2026-10-22) is the name a report is signed with and the
signer block prints, with `title` (`User::signatureName()`: `full_name`, else `display_name`, else the username).
Since phase 13 the account edits them
itself on `/profile`, and an owner still can in Admin → Users. `title` may hold `|`, where the printed signature block breaks the line (`Support\SignatureTitle`; one line, joined by ` · `, elsewhere).
`stamp` (optional, at most 40
characters, absent before) is the doctor's stamp code (*parafă*), edited on `/profile` only; it is stored, not yet printed.

`tokens` (phase 13, absent before): the account's API tokens, each
`{"id":"k3m9x2qa","name":"dictation script","scope":"write","created":"2026-09-27T10:00:00+03:00",
"last_used":"2026-09-27","hash":"<sha256 of the secret, hex>"}`. Optional `expires` (an ISO time: past it the token is refused; OAuth's last 30 days, /profile's 30, 90, 365 days or none) and `last_addr` (the address it was last used from, shown on /profile). Optional `namespaces` (D56, 2026-10-27): a list of namespaces the token is limited to (the account narrowed to them, never widened; absent: the whole account). The token itself
`rpn_{base64url(username)}.{id}.{secret}`, `id` 8 base32 characters, `secret` 32 random bytes
base64url: is shown once and never stored. `last_used` is a date, written at most once a day.
A malformed entry is dropped when the record is read (it can no longer authenticate).

`pins` (2026-10-01, absent before): the namespaces the account pinned to its quick-navigation
menu, in the order pinned:`["reports:ct","reports:mri:medima","templates:mri"]`. Namespaces
only, never a page, and never a segment shaped like a report name (`Auth\Pins`, so a patient
path cannot land here); at most 20. Toggled with `POST /profile/pins`. An invalid entry or a
repeat is dropped when the record is read. A pin grants nothing: the namespace index it links
to is visibility-filtered like any other listing.

`views` (2026-10-19, absent before): the account's saved searches, in the order saved:
`[{"name":"MRI genunchi 2026","query":"q=genunchi&modality=mri&sort=date"}]`. `Auth\SavedViews`: at
most 20, a name of at most 60 characters (the same name in any case replaces), `query` only
`/search`'s own parameters (`q`, `sort`, `ns`, `modality`, `region`, `site`, `device`, `status`,
`tag`). Saved and removed with `POST /profile/views`, listed in the quick-navigation menu; not
audited, like a pin. A view grants nothing: every search runs the visibility predicate when opened.


## 10b. Groups:`data/groups/{name}.json` (D47, 2026-10-13)

```json
{"name":"mri-team","display_name":"MRI team",
 "grants":[{"namespace":"reports:mri","role":"editor"}],"radiologist":true,
 "created":"2026-10-13T10:00:00+03:00","updated":"2026-10-13T10:00:00+03:00"}
```

A named set of grants an owner gives several accounts at once. `name` has the shape of a username
(`a-z0-9`, `_ . -` inside, at most 64) and is the filename; `display_name` is optional. `radiologist`
(bool, default `false`) makes every member a radiologist. A group never grants `owner`. Written
atomically by `Auth\FlatFileGroupStore`, disk-authoritative (D36): there is nothing about groups in
`index.sqlite`. An account names its groups in `groups` (§10); a group with members is not deleted
(Admin → Groups refuses). Admin → Users and → Groups write `user.create`, `user.access`,
`user.deactivate`, `user.reactivate`, `group.create`, `group.update` and `group.delete` audit lines;
`user.access` carries the names of the changed fields, never their values.

## 10c. The Markdown dialect (2026-10-15)

A markdown body is **generic CommonMark plus tables plus definition lists**, rendered by PHP alone (`Service\Render`; marked.js was dropped, D17 amended 2026-10-15). A definition list is a `Term` line followed by `: definition` lines, and renders as `<dl><dt><dd>`. Added 2026-10-16 (`Service\Markdown`, a fixture in `tests/fixtures/render/extended-syntax.md`), all rendered the same on screen, in print/PDF and in the ODT:

| Write | Renders |
|---|---|
| `cm^2^`, `H~2~O` | superscript, subscript. One character each side, no space inside: `~5 mm and ~6 mm`, `~~x~~` and `x^2 and y^3` stay as typed |
| `==text==` | highlight (`<mark>`) |
| `- [ ] item`, `- [x] item` | ☐ / ☑ glyph, not a form control; nothing is ticked on the page |
| `https://…`, `www.…`, `a@b.c` | linked without brackets |
| `*[MRI]: Magnetic resonance imaging` (own line, anywhere) | every whole-word, case-sensitive `MRI` in running text (not in links, images or code) becomes `<abbr title>`; the line itself is not shown |
| `::: warning Title` … `:::` | a callout holding any blocks; kinds `note`, `tip`, `info`, `warning`, `caution`, `important` (another word is plain text); the title is optional plain text; callouts do not nest |
| `Table: caption` (paragraph after a table) | the table's `<caption>` |
| a cell `<<` | joins the cell on its left (`colspan`); an empty cell stays a cell; in a row's first cell it is text |

Not in the dialect: footnotes, strikethrough, smart punctuation, attributes (`{.class}`), embeds, math, row spans and multi-line cells. Nothing is stored differently: bodies stay plain markdown text.

## 11. Report body headings (decided 2026-09-27)

Every report body has one heading shape, whether it was imported or created in the app:

```markdown
# {patient name}          D30's name heading: on screen only (the header shows it too)
**Indication**, date…     shared text above the first exam (optional)
## {exam}                 one per exam:"IRM genunchi drept"
### {section}             Indicație, Tehnică, Descriere, Concluzii, Recomandări…
#### {sub-part}           deeper structure inside a section ("Segment cervical")
```

- A single-exam report has one `##`. Several exams done together have one `##` each (roadmap
  phase 12 builds on this), with a conclusion per exam at `###`. An archive report with
  **one shared conclusion** after several exams keeps it as `## Concluzii`, a sibling of the
  exams. This is a supported form (2026-10-22): a last `##` whose text names a conclusion
  (Concluzii, Concluzie, any case, with or without diacritics), after at least one exam, is the
  report's shared conclusion and no exam (`Support\Exams::split()` `shared`, the same in
  `editor-exams.js`). See §12 for what signing asks of it.
- `exam_title` names the exam(s): the heading's text for one exam, `A + B` for several.
- **What prints.** Exports and the public layout drop the name heading, and also a lone `##`
  whose text is the exam title, which the printed title already shows
  (`Support\ReportName::forExport()`). The patient's name prints once, in the patient block
  (D1 as amended 2026-09-27). The signed-in page view drops the name heading as well: the
  page header already shows it.
- **Getting there.** The new-report form writes `# {name}` and `## {exam}` from the start, and
  the form's indication between them as the shared text, `Indicație: {indication}` on one line
  (2026-10-24; none when the field is empty). The assistant's `create` prompt then fills the
  exam's part only.
- **Blanks (2026-10-18, D51).** A part of the text still to be filled is written `[word]`
  (`[dimensiune]`) or a lone `xx`. `Support\WritingChecks` reports each as `blank`, a warning on the
  Sign screen and in the editor's *Checks*, never a block (D7). Not blanks: a link `[text](url)`,
  a reference `[text][ref]` / `[text]: url`, a footnote `[^n]`, a task box `- [ ]` / `- [x]`.
  Recall's *Expand* (D50) writes such blanks where the passages had a size or a side.

## 12. Exams:`exams:` (roadmap phase 12, 2026-09-27; one shape for every report, phase 27, 2026-10-02; shared and per-exam split, D48, 2026-10-15)

A report is one file, one frontmatter and one signature (D3/D37) for **one or more exams** done
together for one patient: one knee, both knees, three spine regions. **Every report lists its
exams** in `exams:`, a single-exam report a list of one. **What the exams share is the report's;
what each has is its own** (D48), and nothing is copied from one level to the other:

```yaml
title: 'Popescu Ion'                  # the report: patient, site, referrer, indication, priors,
site: mioveni                         #   tags, summary, visibility, and what all its exams share:
referrer: 'dr. Ionescu'
indication: Gonalgie.
modality: [MR]                        # one modality for the report
device: mv-mr1                        # one scanner
study_date: 2026-10-02                # one day (the time is each exam's)
exams:
  -
    title: 'IRM genunchi drept'       # the exam: title, time, region, template (always its own),
    time: '09:10'                     #   accession (D20), study_uid, pacs_accession, order_ref,
    region: [msk]                     #   protocol and the modality's own fields (field_strength,
    template: 'templates:mri:genunchi'#   contrast, dlp, birads…)
    accession: MV-MR-26-0412
    study_uid: 1.2.840.113619.2.55.1
  -
    title: 'IRM genunchi stâng'
    time: '09:40'
    region: [msk]
    template: 'templates:mri:genunchi'
    accession: MV-MR-26-0413
```

- **No derived copies.** There is no top-level `exam_title`, `region`, `accession`, `template` or
  `protocol`, and an exam holds no `modality`, `device` or `study_date`. A reader that wants "the
  report's exam title", "all its regions" or "its earliest time" asks `Support\Exams`: `title()`
  (the titles joined by " + "), `regions()` (every exam's, once each), `report()` (modality, device,
  day, earliest time, and the `moment` the index stores as `study_date`). The index derives the same
  values for its columns and facets and adds `exam_title` to its copy of the frontmatter.
- **An exam's template is its own**, a page under `templates:` (D19, D45): metadata and its `##` exam
  name, never its text. It is never copied to another exam, and an exam made without one has none:
  the Metadata view says so (a warning, never a block: D7). Its checklist, references and the
  assistant's `{template}` come from that exam's template alone.
- **Written by every save** (`Support\Exams::normalize()`, called by `Storage` for every report
  path): the current shape only (D48). A top-level value of an older shape that is *present and
  changed* since the revision before (raw YAML, the API, a plugin filling a blank) is an edit and
  goes into the exam (the first one, on a report of several); one that is unchanged or absent is
  dropped. A modality, device or day an older revision kept on its exams only is lifted to the report.
  A date-time written at the top level becomes the day, and its time the first exam's `time`.
- **Read in every shape, for ever** (`Support\Exams::of()` and `report()`; D48): a report saved
  before phase 27 has no `exams:` and its top-level fields are its one exam; a report of phases
  27-28 has a modality, device and date-time on every exam (the date-time becomes the report's day
  and the exam's `time`) and the derived copies at the top; a report of one exam takes an older
  save's top-level title, region, accession and template, a report of several never takes a
  template from the top level (it was the first exam's copy, and is no other exam's). A signed report
  is never rewritten (its frontmatter is part of its digest, D3): it stays in the shape it was signed
  in, a correction is a new revision and is written in the current shape. `Exams::flat()` gives a
  single-exam report's exam at the top level, for writers that fill blanks there.

- **Multi-exam** means two or more: one entry is a single-exam report, never split or checked by
  the rules below, whatever its headings.
- **Edited** in the editor's Metadata view (phase 28b): the report's fields (site, referrer, modality, day, device, indication…), then one card per
  exam: its title, time, regions, protocol and template; its accession and
  PACS study shown, not edited; other fields kept and edited in raw mode. With JavaScript the
  cards add, remove and move exams and keep the text's `##` sections in step
  (`assets/js/editor-meta-exams.js`); without it they are edited in place. The order is posted
  (`fm[exam_order][]`): an exam left out is removed, a new one numbered on save (D20).
- **Written** in the same normal edit, one tab per exam (2026-10-07; raw edit's until then): a
  Head tab for the shared text, then a textarea per `##` section, while the sections and the
  cards are one to one. The tab bar's add/move/remove press the cards' own buttons; a tab's `##`
  heading and its card's title rename each other. Raw edit is the whole document in one textarea.
  Joining the tabs puts a blank line before every heading.

- **The body's exams** are its `##` headings, in order: the Nth is `exams[N-1]`, and what is above
  the first is the shared head (the name heading, the indication). The rule is a line rule, the
  same in PHP (`Support\Exams`) and in the editor: a line of up to three spaces, `##`, then a
  space, a tab or the line's end, outside a fenced code block. `###` and deeper never split.
- **A conclusion per exam**: a `### Concluzii` (or `Concluzie`, any case, with or without
  diacritics) directly under that exam's `##`.
- **A shared conclusion** (2026-10-22): a last `## Concluzii` after the exams replaces the
  per-exam `###` ones: none is asked of the exams then. It must have text (`exams.shared_empty`,
  blocks signing). For a report of fewer than two exams it is only a writing-check warning
  (`shared_single`, never a block). The writing checks read it
  against all the exams together; the page view numbers only the real exams (`exam-N`) and gives
  it its own anchor; the editor keeps such a body in one textarea (no tabs, nothing lost); a join
  of a report that has one is refused (it would be dropped); the summary, tags and RADS read it
  as every conclusion (`Support\Conclusion`).
- **Signing** needs the exams whole: as many `##` as entries, each titled (by its entry or its
  heading), each with its conclusion (or the shared one). Anything less is a warning on the page and blocks signing,
  never saving (D7). `summary` stays one per file.
- **A summary from the conclusion** (2026-09-29, a stopgap; kept in step 2026-10-08): a report
  saved by a user: the editor, `POST`/`PUT /api/v1/pages`: with an empty `summary` gets the first
  sentence of the first paragraph of its first conclusion section (`Support\Conclusion`: a heading
  of any level beginning "conclu…"; a list gives its first item; markdown stripped; a sentence ends
  at `.`/`!`/`?`/`…` before a capital, so "cca. 5 mm" does not cut it). `Support\ConclusionSummary`.
  While the `summary` still **equals what that rule makes of the revision being replaced**, nobody
  wrote it, so a later save refreshes it from the new conclusion; a `summary` typed by hand or
  written by the assistant differs from that and is never touched, and a text with no conclusion
  leaves it as it is. Saving never calls the assistant. `pages:summarize` counts such a stopgap
  summary as none and replaces it: with the assistant's line, or, where there is no `summary` prompt,
  with this same first sentence (so the archive gets summaries with no AI at all); a hand-written or assistant's one stays unless `--overwrite`.
  Imports, maintenance runs and other automatic saves do not fill it.
- **Accessions** (D20): one per exam, in `exams[].accession`; there is no top-level `accession`. The index keeps every exam of every report in `page_exams`; `pages.accession`
  holds the first. The counter's seed reads
  every `accession:` line in the frontmatter, so no exam's number is ever issued again. An
  accession typed whole in search finds its report by any exam.
- **Anchors**: the page view, print and the editor preview give a multi-exam report's top-level
  `##` lines the ids `exam-1`, `exam-2`… (so `/{path}#exam-2`), in both parsers (D17); a setext
  `---` heading is not an exam. The editor
  opens on one with `/{path}/edit?exam=2`.
- **Exports** keep every exam heading and print every exam's PACS accession (`pacs_accession`, not ours: 2026-10-12) in the
  header, none when the exam has none; the anonymous file is named `{pid}-rev{N}`, a signed-in one by the page path.
- **Studies** (2026-10-02): `exams[].study_uid` / `exams[].pacs_accession`: a single-exam report's
  too, since phase 27: , set by the dicom plugin when a report is started from worklist studies
  of one patient: same site, modality and
  day (the path names all three), at most 8, in the order they were done. `findByStudyUids()` looks
  in the exams too (`json_each` over `meta_json`, same visibility predicate), so every study still
  finds its report; the PACS tab refuses a study that is none of a multi-exam report's (which exam
  would it be?), and `pacs:link` leaves such a report alone.
- **Templates** (2026-10-02): each exam carries its own `template` (a page under `templates:`; metadata
  and its `##` exam name, never its text. D19, D45, §3i-b); there is no top-level `template`. The worklist start
  suggests one per study by matching the PACS description to the template titles (most shared words,
  fewer left over wins, a tie suggests nothing); the form lets you change it.
- A duplicate keeps `exams:` without the accessions and studies.
- A report with fewer than two exams is never split, whatever its headings.

## 12b. `joined_from`: a report joined from others (2026-10-02, roadmap phase 29)

Two or more reports of one patient (the same CNP, or name, birth year and sex when one lacks it. D11)
at one site can be joined into one multi-exam report: *Join* on a namespace index or a patient
timeline, then a check screen (`POST /join`, `Service\Joins`), nothing written before *Join*. The new
report is a draft:

```yaml
exams: [ … ]                     # every parent's exams, in the order checked, each keeping its
                                 # accession (D20), template, PACS study, order and date
joined_from:
  - {pid: 01M3…, rev: 4}         # the parents and the revisions joined: their latest
  - {pid: 01M4…, rev: 1}
```

The body is `# name`, the parents' shared text, then each exam's `##` section as it was, and must
read `# name / ## exam / ### sections` (§11): `Support\HeadingNormalizer` sets heading levels it can
place (the check screen says so; only `#` counts change), and a body it cannot place (an exam
heading again at `###`, e.g.) is not joined (2026-10-09). Report
fields where the parents differ (`referrer`, `indication`, `summary`) are picked on the check
screen; `patient` is the parents' together, `tags` and `priors` their union (minus the parents).
The path is the first exam's modality folder (or one of the others', picked), the site, then a
last segment the user edits on the check screen (2026-10-09): it defaults to the earliest exam's day
and what the parents' names share, whole words only (`…-rk` and `…-lk` give `…`), and must start with
that day, be a report name and not be another page's path (a parent's is free). The parents go to the trash (`Storage::delete`; Admin → Trash
restores them, a signed one with its signatures); links to them in unsigned pages: body links,
`priors`: are pointed at the joined report. A parent saved since the check screen was shown stops
the join, nothing written. Audited: `page.join` (the new pid; the parents as `pid@rev`), plus
`page.create` and a `page.delete` per parent. Never curated, never duplicated.

## 13. Assistant prompt pages:`ai:profiles:{profile}:…` (phase 15, 2026-09-27; table-sourced 2026-09-28)

The AI assistant's actions are ordinary pages (history, grants, search for free). The profile's own
page, `ai:profiles:{profile}`, carries the rail's **first** markdown table, which actions exist,
their order, and their label/tooltip/icon/result: read by `Support\ProfileTable` /
`Service\Ai\Actions::forPage()`. A second table (a "Disabled Actions" / "not implemented" heading,
still a common habit in these pages) is never reached: moving a row out of the first table is how
an action stops appearing, nothing else to flip:

```markdown
---
title: Radiology Reports Profile
visibility: private
---

| ID         | Label      | Tooltip            | Icon        | Result  | Model        |
|------------|------------|---------------------|-------------|---------|--------------|
| conclusion | Conclusion | Create conclusion   | flag-checkered | append | expert       |
| summarize  | Summarize  | Summarize text     | notepad     | show    |              |
| quality    | Quality    | Check the report    | seal-check  | show    | Cloud:lite   |

## Disabled Actions

| ID     | Label  | Tooltip       | Icon          | Result  |
|--------|--------|---------------|---------------|---------|
| custom | Custom | Custom prompt | pencil-simple | replace |
```

Each row's id names a page `ai:profiles:{profile}:{id}`: its body is the prompt, and its own
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

A row with no matching page (or an empty one) contributes nothing: same as it not being in the
table at all.

`resources/prompts/` holds the shipped texts (moved from `docs/prompts/` 2026-10-19; installable into any profile: Admin → AI, `ai:prompts-install`): in two targets (2026-10-20): `radiology/` (reports, written in Romanian: every reserved prompt, plus the rail's `create`, `conclusion`, `diagnostic`, `gaps-traps`, `urgent`, `quality` and `rapno` and the profile's system prompt `system.txt`, made `ai:profiles:{profile}:system`: 2026-10-25) and `generic/` (ordinary pages, in English: `summary`, `tags`, `tags-review`, `commit`), each with the rail's `ask` (the only shipped rail action: `grammar`, `shorten`, `patient` and `english` were dropped) and `table.md`, the rows for a
profile's own page. `ask` uses the placeholder `{prompt}` (the doctor's question, the rail shows a
question box) and answers from `{text}` and `{history}`; shown only. The installer's *Create the
proposed prompts* makes them all, never over an existing page (the table page only if the profile
has none).

- **Icon** (the table's Icon column): a filename with an image extension
  (`summary.png`/`.jpg`/`.jpeg`/`.gif`/`.webp`/`.svg`) is served from `assets/img/ai/`; a bare name
  (letters/digits/hyphens, no extension) is a Phosphor icon, rendered `ph-{name}`; anything else: a
  unicode character: is an emoji (`✦` when the column is empty).
- **Result**:`show | append | replace | insert`, what the rail does with the answer, **directly**
  when it ends (2026-10-07), on the textarea in front as it is then (an exam's own pane on a
  multi-exam report); Ctrl+Z undoes, nothing is saved until Save. `show` opens a modal with the
  answer rendered, *Append / Copy / Close*; `replace` replaces the selection, else all of the pane's
  text: the `#`/`##` headings that title it stay unless the answer brings its own; `append` adds to
  the end, or merges into a matching `###` section the answer starts with; `insert` writes at the
  start of the text, below the frontmatter and those headings (not at the cursor).
- **Model** (optional last column, 2026-10-07), which model answers: `lite`, `normal` or `expert`,
  the aliases each server defines in Admin → AI (§3d: `model` is `normal`; an empty `lite`/`expert`
  falls back to it), on the server in use; or `{server}:{alias}`: a server by the name Admin → AI
  gives it (`Server N` when unnamed), case aside: to run on another one. No colon is just the alias;
  blank or unknown is `normal`. A named server that is unknown or not set up fails the action, never
  falls back. Each server keeps its own egress rule (`external_ack`) (`AiConfig::parseModel()`).
- **Reserved prompts** (2026-10-07; moved 2026-10-14): `summary`, `tags`, `evolution`, `presign`,
  `commit`, `tags-review` (2026-10-15), `recall-expand` and `recall-missing` (2026-10-18, D50) `ask-expand` (2026-10-19, D53: *Ask the archive*'s standalone question and search words; placeholders `{question}` and `{history}`; model `lite` unless its page sets `model:`; starting prompt `resources/prompts/ask-expand.txt`) and `ask-archive` (2026-10-19, D53: *Ask the archive*; placeholders `{question}` and `{sources}`, the passages as `<source id="n" date="…">` blocks, de-identified per source report) switch on a feature outside the rail, and live apart, in the profile's `special`
  subnamespace: `ai:profiles:{profile}:special:{id}`. Their control is not rendered without the page
  (`Actions::SPECIAL`, `Actions::special()`). They are never rail actions: a table row with one of
  those ids, or `special`, is ignored. They run on their page's `model:`, else `normal` (`commit`:
  `lite`), and with **no system prompt**, whatever the profile has.
- **Sections** (2026-10-08): a row whose ID is `---` (the other cells blank) draws a thin line in
  the rail before the next action: `create`, `conclusion`, `diagnostic`, then `---`, then `quality`,
  `linter`. One at the top, two in a row or one at the end draws nothing extra. **Named sections** (2026-10-20): a
  `---` row with a Label (and an Icon, same forms as an action's) draws that name as a heading
  instead of the line, at the top too: `| --- | Write | | pencil | |`. Two `---` rows in a row: the
  last label wins. A section with no action after it draws nothing.
- **A prompt page's `model:`** (frontmatter, 2026-10-08): the same alias syntax as the Model
  column; the table's cell wins when it is filled. The only way to put a reserved prompt on another model.
- **A prompt page's `max_tokens:`** (frontmatter, 2026-10-08), that action's answer cap; with the
  server's own `max_tokens` the smaller is sent; none set, the server's alone. There is no built-in
  default (one of 30 for `tags` was dropped the same day): a reasoning model spends its thinking from
  the same budget and answered nothing. Set a small cap only for a non-thinking model.
- **A prompt page's `timeout:`** (frontmatter, 2026-10-10): seconds to wait for that action's
  answer, on any prompt page or reserved prompt; with the server's own timeout the smaller is used.
- **`lite` gets no system prompt** (2026-10-08): an action on the `lite` alias sends only the user
  message: no `…:system`, no `…:system:{action}`: whichever model `lite` stands for.
  - `summary`: *Summarize* in the report tab's metadata panel (and `pages:summarize`): the report's
    **conclusion** when it has one: a heading of any level beginning "conclu…" (Concluzii, Concluzie,
    Conclusion…), case and diacritics aside, to the next heading of the same or a higher level; one per
    exam under the exam's title on a multi-exam report: else the whole text (a conclusion under 20
    characters, "Fără modificări", counts as none; `Support\Conclusion`). One line ≤ 160 characters,
    edited and saved as `summary`. The prompt sees `{text}` as that conclusion.
  - `tags`: *Suggest tags* beside the report tab's tags (2026-10-08), same gate as `summary`: the
    **whole** report as `{text}` (the conclusion often lacks the exam and the region the first two
    tags are). The answer is parsed on the server (`Support\TagList`): `NONE`, or a single item that
    is not an examination type (`TagList::EXAM_TYPES`: radiografie, rx, ct, irm, rm, ecografie, …),
    is no tags; otherwise split on commas (or lines), stripped of quotes, markers and final
    punctuation, lowercased, items over 4 words dropped, mapped to the tag dictionary's own spelling
    when it is an entry or a synonym (case and diacritics aside), de-duplicated, at most 5. The
    dialog offers the report's tags already there followed by those; *Save tags* writes the list
    as `tags`, replacing it (one revision). The page's own `max_tokens:`, if any, caps the answer. In bulk:
    `pages:tag` (`Service\Maintenance\TagTask`, also Admin → Tags): reports with no tags,
    or all with `--overwrite`; an answer with no usable tags leaves the report untouched.
    **RADS categories** (phase 34h, 2026-10-08) are tags too:`rads:{system}-{category}`, system
    one of `birads`, `pirads`, `lirads`, `lungrads`, `tirads`, `eutirads`, `orads` (`rads:birads-4a`,
    `rads:lirads-m`, `rads:lungrads-4x`). `Support\Rads` reads them from the conclusion section(s)
    (the whole text when there is none), no assistant: a category in a clause about an earlier
    study ("anterior BI-RADS 3", "BI-RADS 3 (precedent)") or right after a negation ("nu BI-RADS 4")
    is left out; density letters ("BI-RADS B") are not categories. *Suggest tags* adds them after
    the assistant's tags (outside its 5), and spells a model's "BI-RADS 4A" as the tag; `pages:tag`
    adds them to a report already tagged without them, or whose profile has no `tags` prompt,
    without asking a server (counted `rads`, revision note `tags: rads`, audit reason `rads-tags`).
  - `commit`: the editor's Save (2026-10-10): with "What changed?" left empty on a new revision
    (not a minor edit, not a create) whose body changed on the current base revision, one line for
    the revision note. The prompt gets `{diff}` and no patient header. `{diff}` is a unified-diff
    shape without file headers, line numbers or context: each `@@` line carries the nearest
    heading (`@@ ### Concluzie`); a paragraph changed in a few words is one line in
    `git --word-diff=plain` form (` text [-old-]{+new+} text`); added or removed paragraphs are
    `+line`/`-line`; a paragraph with under 40% of its words kept is a `-`/`+` pair. Both bodies are
    de-identified against the old and the new frontmatter (`Service\Ai\CommitNote`). No call,
    so no note, when nothing changed, over 60% of the words changed (a rewrite, a template's first
    fill), or the diff passes 1500 characters (cut at a hunk). Defaults: model `lite`, `timeout`
    10 s unless the page sets them; one attempt, no retries; any failure leaves the note empty and
    never fails the save. The note is stored `<line> · assisted: commit` (rail actions first:
    `assisted: conclusion, commit`) and the `page.save` audit's `assisted` includes `commit`. API
    `PUT` and bulk tasks never ask it.
  - `tags-review`: tags:review (Admin → Tags, `bin/reporion tags:review`, 2026-10-15), the profile
    serving `reports`, else the first `reports:{x}` whose profile has the page. The one prompt with **no report**: `{tags}` is the tag vocabulary, one
    `tag (pages)` per line, in requests of at most 200 tags grouped by the words they share (a tag in each of its words' groups, a group never split between two requests: one is closed early instead; only a group over 200 on its own is cut; `TagNormalize::groups()`/`chunks()`); `{vocabulary}` the dictionary's tags.
    A tag that may name a patient is never sent (a word of it is a name word of a report's path,
    or it has an identifier's shape: `Context::vocabulary()`), counted `withheld`; `rads:*` tags
    are not sent. The answer is one JSON object per line (or a JSON array):
    `{"op": "merge|generalise|rename|drop", "from": ["tag", …], "to": "tag", "reason": "…"}`,
    each checked (`Support\TagProposals`): every `from` in use, `to` lowercase and at most 4 words,
    a dictionary tag never dropped. Proposals only: the owner ticks them in Admin → Tags. A
    starting prompt is `resources/prompts/{radiology,generic}/tags-review.txt` (2026-10-15; `summary.txt` and `tags.txt` beside it).
  - `evolution`: the patient timeline's Evolution panel: this report plus `{history}`. When
    `{history}` would be empty, no model is asked: the answer is "Date imagistice insuficiente
    pentru evaluarea evoluției." (`Context::NO_HISTORY`, context `no priors`; 2026-10-08).
  - `presign`: the Sign screen's *Check before signing*: the whole report; the answer is read as a
    list (`- …` lines, else its lines; "none"/"nimic" alone is no point), shown as warnings beside
    the rules' (`Support\Laterality`), never a block (phase 34a, 2026-10-08).
  - `recall-expand` and `recall-missing` (D50, 2026-10-18): the editor's Recall panel, one line at a
    time. The line is `{text}` (label "line"); `{recall}` is the passages `Service\Recall` found
    (finished reports, never this patient's, de-identified per source report, unescaped like
    `{snippets}`) as `<exemplu id="…">` blocks; the context chip says "N recalled". The names are
    not `expand` so that no rail action called `expand` is taken. `recall-expand` (*Expand*): the
    answer is shown first, *Replace line* puts it in the textarea (list marker kept; recorded
    `assisted: recall-expand`). `recall-missing` (*What is missing?*): shown only; model `lite`
    unless its page sets `model:`. Starting prompts: `resources/prompts/radiology/recall-expand.txt` and
    `recall-missing.txt` (the installer's *Create the proposed prompts* makes them pages too).
- `ai:profiles:{profile}:system` is the profile's system prompt (`ai:profiles:default:system` when it
  has none); `ai:profiles:{profile}:system:{action}` is appended for that action.
- Which profile a page uses: the one in use, `ai.prompt_profile`, on the namespaces in `ai.namespaces`
  (§3d, chosen in Admin → AI). Pages elsewhere use the fallback
  profile, `ai.fallback_profile` (2026-10-08): a generic one, e.g. `default` with `summarize` and
  `rewrite`, or get no Assistant when it is blank. `ai.profile_map` (2026-10-13, D47) maps namespaces to profiles: `AiConfig::profileFor()` takes the most specific namespace among the map and `ai.namespaces` (the map wins a tie), and only then the fallback. Several profiles can exist
  side by side (e.g. `reports` and `reports-short`) and be switched.
- De-identification (`Service\Ai\Redactor`) takes a report's name from wherever it is written, not
  `patient.name` alone (2026-10-10): also the path's name words (D1) and the `#` heading when it shares a
  word with either: a report whose fields disagree sends none of its spellings.
- Placeholders, filled only by `Service\Ai\Context` (de-identified, D15/invariant 8): `{text}`
  `{template}` `{previous}` `{previous_date}` `{current_date}` `{current_time}` `{snippets}` `{recall}` (D50: `recall-expand` / `recall-missing` only) `{question}` (D53: `ask-expand`, `ask-archive`) `{examples}`
  (frontmatter `ai_examples:`) `{exam}` `{modality}` `{region}` `{age}` `{sex}` `{prompt}` `{action}`, `{diff}` (the `commit` prompt only, 2026-10-10: the same de-identified text as `{text}`, as a diff), and `{history}` (`ask-expand` only, D53: the earlier turns of the Ask conversation as question and answer, de-identified, old `[n]` citations removed; elsewhere:) the patient's other reports the caller can
  read, the latest 8, oldest first, each de-identified and tagged only by date and exam
  (`<report date="YYYY-MM-DD" exam="…">`), for `evolution` (2026-10-07). Every date a prompt
  gets:`{current_date}`, `{previous_date}`, `{history}`: is `YYYY-MM-DD` (2026-10-08); `{language}`:`Romanian`, the
  language of report content (D26), for prompts ported from DokuLLM (2026-10-08). `{vocabulary}`: the tag
  dictionary's tags (Admin → Tags, `data/tags.yaml`), comma separated, not their synonyms, for the
  `tags` prompt to choose from (2026-10-08); "( fără vocabular )" when the dictionary is empty. A reasoning
  model's `<think>…</think>` never reaches the answer (`Service\Ai\ThinkFilter`).
- `{previous}` / `{previous_date}` are the first report in the frontmatter's `priors` the caller can
  read; with none there (phase 34c, 2026-10-08), the patient's latest **earlier** report the caller
  can read that shares a modality with this one and, when this one names regions: a region
  (`PatientStudies`, the timeline's lookup). "Context sent" then says `prior (auto)`. Nothing is
  written: `priors` stays as it was.
- **Data is escaped** (2026-10-08): `<` and `>` in what a placeholder brings: the report, a
  template, a prior, `{history}`'s and the examples' bodies, the header: go in as `&lt;` `&gt;`, so a
  page that is itself a prompt (or a report with `<report>` in it) cannot open or close the prompt's
  blocks; the answer has them turned back (`Service\Ai\EntityFilter`). Placeholders are filled in
  one pass: a `{language}` inside a report stays as written. `{prompt}` (the user's own words) is not
  escaped.
- Every user message about a report starts with a patient header (2026-10-08: only a report: a
  page elsewhere has no patient and no exam), whatever the prompt page says (2026-10-07):
  `patient: 46y, female` / `indication: …` (frontmatter `indication`, de-identified, one line) /
  `exam: …` (the exam in front on a multi-exam report), each line only when known, then a blank line.
  Never the name, nor its initials (D1).
- They are read whatever the caller's grants (the instance's configuration); changing them is the
  ordinary page rule. Admin → AI's page listing (`Actions::pages()`) reads each page's own
  frontmatter, for showing disabled/unlisted actions too.

## 14. Backup archive:`data/backups/reporion-backup-YYYYMMDD-HHMMSS.tar.gz` (D44, 2026-10-12)

A gzip-compressed POSIX **ustar** tar (names over 100 bytes use the prefix field or a PAX `path`
record), written as a stream by `Service\Backup\ArchiveWriter` and read: as untrusted input: by
`ArchiveReader`. The name carries the time only (invariant 8).

Entries, in this order: directory entries, then regular files, then the manifest as the **last**
entry. Only these names are accepted on read:

```
data/…                     the data tree (without the exclusions below)
conf/local.php             when present
manifest.json              last
```

Left out of `data/`: `index.sqlite*` and `vectors.sqlite*` (caches), `snapshot/`, `tmp/`, `import/`, `backups/`, `.pre-restore-*/`, `*.lock`,
`*.part`, symbolic links and special files. **`data/reporion.sqlite` is in the archive (D54, 2026-10-20) as a
`VACUUM INTO` copy taken just before** (`Databases::snapshotState()`), never the live file or its `-wal`: it holds the only copy of
comments, media names, moves and redirects, deliveries, share tokens, drafts and counters. A restore puts it
back like any other file, then `journal:replay`, an index rebuild and `integrity:verify`. Refused on read: absolute names, `..` or empty
segments, backslashes, control characters, links, devices, anything outside the list above, a
file listed twice, any entry after the manifest.

```json
{
  "format": 1,
  "created": "2026-10-12T19:19:59+03:00",
  "paths": {"data": "/var/www/html/reporion/data", "audit": "…/data/audit"},
  "php": "8.1.2",
  "file_count": 35, "dir_count": 14, "bytes": 18123,
  "files": [{"path": "data/settings.yaml", "size": 123, "sha256": "…"}]
}
```

A restore extracts into `data/tmp/restore-<ts>/`, then requires the set of files to equal the
manifest's list and every size and sha256 to match before it moves anything (a truncated or
altered archive is refused). `paths.data` is what `--with-conf` compares to decide whether
`conf/local.php` may be installed.

## 15. What is inferred, and when (2026-10-13)

Several values are worked out from other ones. Each has its own trigger and its own way of telling
"the author wrote this" from "the app derived this"; this table is the one place that lists them
(D8: a derived value is never silently authoritative: a person's value is left alone).

| Value | Worked out from | When it runs | Left alone when | Code |
|---|---|---|---|---|
| **Page title** | the body's first `#`, unless `title` is set (§3k) | whenever a title is shown or indexed: never written | `title` is non-blank | `Support\PageTitle`, `Index\Sqlite::write()` |
| **Exam keys** (an older shape's top-level `exam_title`, `region`, `accession`, `template`, `protocol`; an exam's `modality`, `device`, `study_date`) | the report's `exams[]` and the report's own `modality`, `device`, `study_date` (§12, D48) | every save of a report path, in `Storage` | a top-level value *present and changed since the revision before* is an edit and goes into the exam; an unchanged or absent one is dropped | `Support\Exams::normalize()` |
| **Accession** (`exams[].accession`) | the site's code, modality, year and the counter (D20) | the guided form's first Save; an exam added in the editor, on its Save | the exam has one (never reissued) | `Service\ExamAccessions` |
| **Stopgap `summary`** | the first sentence of the first conclusion section | a user's save of a report (editor, `POST`/`PUT /api/v1/pages`) with a `summary` empty or still equal to what this rule made of the revision being replaced | a hand-written or assistant-written `summary`; a text with no conclusion | `Support\ConclusionSummary` |
| **Assistant `summary`** | the assistant's `summary` prompt | the editor rail; `pages:summarize` for unsigned reports with none (or only a stopgap; no assistant: the stopgap sentence) | a summary that is not a stopgap, unless `--overwrite` | `Service\Maintenance\SummarizeTask` |
| **Tags** | the assistant's `tags` prompt (3–5 tags) | the editor rail; `pages:tag` for unsigned reports with none | existing tags (unless `--overwrite`); signed reports | `Service\Maintenance\TagTask`, `Support\TagList` |
| **RADS tags** `rads:birads-4a`… | the category the conclusion states (D18) | suggested on the Sign screen; added by the rail's tags action and by `pages:tag`: also on tagged reports; **not** on a plain save | tags already there are merged, never removed | `Support\Rads` |
| **Starting frontmatter** of a page that does not exist yet | the path and the text: `private`; on a report path the modality (path segment through `reports.modality_namespaces`), site, date (`yymmdd` leaf), `patient.name` from `#`, `exam_title` from `##` | when the editor or the API is given no frontmatter; nothing is kept until Save. It writes no `title` (§3k) | n/a | `Service\FrontmatterGuess` |
| **New report** | the form: path from name, date, site, modality; per exam, the title from the template's `##` when the form's is blank, the regions from the template's `region` when none is ticked, `protocol`, `template` (§3i-b) | the guided form | the form's own values win | `Service\NewReport` |
| **Starting visibility** of a new page | the nearest ancestor namespace's description page (`{ns}:_index`) | the editor's visibility picker only; API, CLI, `/new`, copies and joins start `private` | n/a | `Service\NamespaceDefaults` |
| **Patient key** | `sha256(cnp)`, else `sha256(name|born|sex)` (D11) | index time only: never in the page | a `patient.key` on disk overrides it | `Support\PatientKey`, `Index\Sqlite` |
| **PACS and HIS fills** | the study or the order: `study_uid`, `pacs_accession`, patient, time, exam title, referrer; `device` from the study's scanner link, else the one device whose `inst:` text the InstitutionName contains | a worklist pick; the PACS tab; `pacs:link`; the HippoBridge tab (D38, D39) | blanks only: nothing present is overwritten; signed reports go back to draft or are skipped, per task | `plugins/dicom`, `plugins/hipobridge`, `Support\Devices::forInstitution()` |
| **Template suggestion** | the PACS description against the template titles (most shared words; a tie suggests nothing) | the worklist start | the form lets you change it | `plugins/dicom/Pacs.php` |
| **Revision note** | the assistant's `commit` prompt (D15) | the editor's Save, when the note is empty: the revision is marked `assisted: commit` | a note the doctor wrote | `Controller\EditorController` |
| **Heading levels** | the report shape `# name / ## exam / ### sections` (§11) | joining reports; the importer (removed) | a body it cannot place is not joined | `Support\HeadingNormalizer` |

**Never inferred, never written without a person's Save:** `title`, `visibility` (except the picker's
start), `status`, signatures. Automatic saves: imports, maintenance runs, plugin fills: write their
revisions as a pseudouser (D41) and do not run the user-save rules above (title, stopgap summary).

**Checked, not inferred** (warnings only, D7): the left/right check on the Sign screen
(`Support\Laterality`), a template checklist's "not mentioned" (§3h), the exams whole before signing
(`Exams::problems()`), a template's shape (§3i-b).

**Why the tests differ.** "The author wrote this" is told per value because the values differ: a
`summary` by equality with what the rule would have made; tags and `template` by being present;
exam keys by having changed since the revision before; `device` and the PACS fields by being blank.
A new inferred value needs its own such test and a row here.
