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
is how a report gets written in the wrong document.

Different modality or site already differ earlier in the path, so the collision only ever
applies within one namespace.

## 2. `data/journal/YYYY-MM-DD.ndjson`

One line per write intent, appended and `fsync`ed before any page file is touched. Replayed on
boot; a line with no matching `done` is an incomplete write.

```json
{"ts":"2026-09-22T09:41:10.882+03:00","op":"save","pid":"01JB8X4MT7QK2V9Z0C3R5H6ND","path":"reports:mri:mioveni:260922-ionescu-maria","rev":8,"base_rev":7,"body_sha":"9f2c…","actor":"owner","state":"intent"}
{"ts":"2026-09-22T09:41:10.941+03:00","pid":"01JB8X4MT7QK2V9Z0C3R5H6ND","rev":8,"state":"done"}
```

`op` ∈ `create|save|revert|move|delete|restore|purge|sign|import`. Recovery is idempotent: replaying a
`done` line is a no-op, replaying an `intent` re-runs the write from `rev/NNNN.md.gz` if that
file exists, or discards the intent if it does not.

## 3. `data/counters.json`

Accession sequences (D20). Written atomically (temp + rename) inside the create journal window.

```json
{"mioveni":{"RM":{"2026":918},"CT":{"2026":412}},"pitesti":{"RM":{"2026":1204}}}
```

Sequence is per site, per modality, per year; formatted with `seq_pad` from config
(`MV-RM-26-0918`). A gap in the sequence is acceptable and expected (abandoned creates); a
duplicate is not.

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

`action` ∈ `page.read|page.create|page.save|page.revert|page.sign|page.move|page.delete|page.restore|page.purge|page.publish|export|share.create|share.use|ai.call|login|login.fail|password.change|password.reset|index.rebuild`.
Action-specific fields are added to the line (`to` for a revert, `batch` for an import, `format`
for an export). `login.fail` names the attempted username only when it is username-shaped —
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
falls back to the username when `display_name` is empty.

