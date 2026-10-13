# Test doubles audit, where a fake may hide an integration failure

Audited 2026-10-11 on `fa58340`. Scope: every test double in `tests/` (no PHPUnit mocks
exist; the doubles are hand-written classes, injected closures and stand-in servers), plus
anything that keeps a real integration from running. Findings only; fixes are under
**Recommendations** and none were applied.

## Inventory

| Double | Stands in for | Used by | Verdict |
|---|---|---|---|
| `tests/Storage/RecordingIndex.php` | `Index\Sqlite` (writes recorded, reads stubbed empty) | Storage tests, `RevisionsTest`, `PatientCsvTaskTest`, `PagesApplyPatientCsvCommandTest`, `AdminIndexTest` | OK: see §6 |
| `tests/Http/InMemoryUserStore.php` | `Auth\FlatFileUserStore` | `SessionTest` only | OK: login/grant flows in `tests/Http` use the real store |
| `tests/Ai/FakeServer.php` + `tests/fixtures/ai/fake-openai.php` | an OpenAI-compatible server | `tests/Ai`, `AdminAiTest`, maintenance tasks | Real HTTP, good; contract gaps §5 |
| `hipobridge\Plugin::$transport` closure | HippoBridge FHIR over HTTP | `HipobridgeTest` | Gap §3 |
| `dicom\Plugin::$runner` closure (`fakeFindscu`) | dcmtk `findscu`/`echoscu`/`storescu` + a PACS | `DicomTest`, `DicomSendTest`, `DicomSrTest` | Gap §2, §4 |
| shell-script `findscu` | `findscu` | `DicomScuParallelTest` | OK (tests lane scheduling only) |
| Hand-built "crashed" disk states | a process killed mid-write | `FlatFileTest`, `PageAndNamespaceTest`, `MoveRestorePurgeTest` | Gap §7 |
| `Kernel::boot()->handle(new Request)` | `public/index.php` + `Response::send()` | all `tests/Http` | Low, §8 |

## Findings, most severe first

### 1. `tests/Service` and `tests/Audit` never run in `composer test` (high). FIXED

_Fixed: `phpunit.xml` now has `service` and `audit` suites. The text below is the finding as originally written._

`phpunit.xml` lists 11 suites; `tests/Service/` (13 files) and `tests/Audit/` (1 file) are in
none of them. `phpunit --list-tests` finds no `Reporion\Tests\Service` or
`Reporion\Tests\Audit` test. The full pre-merge run therefore skips 77 tests, including
`RevisionsTest`, the maintenance tasks (`PatientCsvTaskTest`, `TagTaskTest`, …) and the
audit log's path-hash test (invariant 8). This is not a double, but it hides failures the
same way: break any of them and the suite stays green. Run explicitly today they pass
(`OK (77 tests, 273 assertions)`).

### 2. The real-dcmtk PACS test always skips on this host, with a false reason (high)

`DicomScuTest` resolves `command -v findscu` and requires `echoscu`, `dcmqrscp`,
`dcmqridx` and `dump2dcm` **in the same directory**. On this host `/usr/local/bin/findscu`
is first on `PATH` and it is **pynetdicom's** `findscu` (a Python entry-point script), with no
`dcmqrscp` next to it; dcmtk 3.6.6 is installed in `/usr/bin`. Result: the only test of
`Scu` against a real PACS is skipped every run as "dcmtk is not installed", and every other
PACS test goes through `fakeFindscu`.

Run against `/usr/bin` with a clean `TMPDIR`, it passes (`OK (4 tests, 25 assertions)`,
three runs), so it is hiding no current `Scu` bug, but nothing would notice if one appeared.
One run out of six also failed once with `unreachable`: probably `dcmqrscp` not yet
listening when the first query went out (the setup does not wait for the port).

### 3. The HIS client's real single-request path has no test (medium)

`HipobridgeTest` injects `Plugin::$transport` in every test. Injecting a transport also
sets `Client::$parallel = false`, so the plugin-level flows (worklist, start a report,
priors import) never run through either real transport. `HipobridgeClientParallelTest`
covers `getMany()` over `curl_multi` against a real server, but **nothing calls `get()`
without a transport**, so `Client::httpTransport()` (`file_get_contents` + stream
context, status parsing from `$http_response_header`, the `[0, '']` → `unreachable`
mapping, `follow_location: 0`) is untested. Production runs two different HTTP stacks,
and only one of them has a test.

The fake is also lenient: `$this->his[$key] ?? $this->his[strtok($key, '?')]` answers a
request whose query string is wrong as if it were right, so a broken FHIR search
parameter (wrong `date`, `_count`, patient key) cannot fail a test. The FHIR shapes are
hand-written from `hippoclient.py`, and no recorded response checks them.

### 4. Plugin-level PACS logic only ever meets the fake's matching rules (medium)

`fakeFindscu` reimplements C-FIND matching in PHP: exact string compare on
`ModalitiesInStudy`, its own `*` wildcard for `PatientName`, `StudyDate` ranges by string
compare, every element emitted as `tag="0000,0000" vr="LO"`. Real PACS differ:
`ModalitiesInStudy` is multi-valued (`CT\SR` matches a `CT` query), name matching may be
case- or `^`-component-sensitive, and a `SpecificCharacterSet` other than ISO-8859-1 may
come back. `Pacs` (worklist marking, multi-exam assignment, bulk link) is tested only
against these semantics. `DicomScuTest` (§2) covers `Scu` against `dcmqrscp`, but does
not exercise `Pacs`.

`DicomSendTest::testRealStorescuToARealStorescp` resolves `storescu`/`storescp` through
`PATH`, which on this host means **pynetdicom on both ends**: the "real dcmtk" send
test has never talked to dcmtk here. (`DicomSrTest`'s `dsr2html`/`dsrdump` exist only in
`/usr/bin`, so that one is genuinely dcmtk.)

### 5. The fake AI server always finishes cleanly (medium)

`OpenAiCompatibleProvider::stream()` treats end-of-stream without `data: [DONE]` as a
normal finish. Only `stream_get_meta_data()['timed_out']` raises. The fake always sends
`[DONE]`, and it has no behaviour that drops the connection mid-answer, so the case where
the server or proxy closes early is never exercised. If the provider does take that path,
a truncated conclusion comes back as a complete answer. The fake also never sends:
`: keep-alive` comment lines (OpenRouter), `\r\n` line endings, `finish_reason:
"length"` (a cap hit mid-sentence), or `delta.reasoning_content`. The parser handles the
first two by construction, but no test says so.

### 6. `RecordingIndex`: low, documented as fine

`FlatFile` calls only `index()` and `remove()` on its index (checked: no read call in
`src/Storage/`), so the stubbed reads cannot change Storage behaviour. `Revisions` and
`PatientCsvTask` take no index. `FlatFile` + real `Sqlite` is covered by
`HttpTestCase::createPage()` and 61 test files, plus
`SqliteTest::testRebuildIsEquivalentToIncrementalIndexing`. `AdminIndexTest` uses
`RecordingIndex` on purpose, to create index drift. One residual risk: the reads return
`[]`/`null`/`false` rather than throwing, so a future `FlatFile` that starts reading
from the index would pass the Storage tests silently.

### 7. Crash tests simulate the crash; they don't cause one (medium)

CLAUDE.md asks for crash tests that "kill the process between journal write and rename".
The Storage tests instead **build the intermediate disk state by hand** (e.g.
`FlatFileTest` ~l.286, ~l.376, ~l.490; `PageAndNamespaceTest` ~l.104–159;
`MoveRestorePurgeTest` ~l.89) and then assert that replay recovers. There is no
`pcntl`/`posix_kill`/`proc_open` in `tests/Storage`. The hand-built states encode the
author's belief about the write order. If `FlatFile` reorders a write (meta before rev,
journal line after the first rename), the tests keep building the *old* intermediate
states and stay green, while the new window goes untested.

### 8. HTTP entry point bypassed: low

Every HTTP test calls `Kernel::boot($config)->handle(new Request(...))` and inspects the
`Response` object. `public/index.php`, `Request::fromGlobals()` with real server vars, and
`Response::send()` (`header()` calls, streamed bodies) run in no test except
`RequestTest`'s unit checks. `send()` uses `header("$name: $value")` with replace, from an
`array<string,string>`: a response can carry only one `Set-Cookie`. No route sets two today.

## Side observation from the audit (live host)

`/tmp/reporion-dicom-544c80b7846ea48e` (owner `www-data`, 2026-09-30) is a findscu query
directory that production never cleaned up. It probably comes from a worker killed
mid-query, since `cleanup()` runs in `finally`. Such a directory can hold `query.dcm` with
patient keys. It was not opened during this audit. It should be inspected by its owner and
removed. Because of it, the `glob(sys_get_temp_dir() . '/reporion-dicom-*')` assertions in
`DicomScuTest` fail on this host whenever that test does run.

## Recommendations

1. Add `service` and `audit` testsuites to `phpunit.xml`, or replace the list with one
   `<directory>tests</directory>` suite so a new folder cannot fall out again.
2. `DicomScuTest`: resolve each dcmtk tool on its own (or take a `DCMTK_BIN` env var), so
   the test refuses a non-dcmtk `findscu`. Skip loudly with the path it found. Wait for
   `dcmqrscp`'s port as `FakeServer` does. Give the test its own temp dir instead of
   globbing the shared `/tmp`. Pin `DicomSendTest`'s real-send test to dcmtk the same way.
3. HIS: add a `get()` test with no injected transport against the existing `php -S` fake
   (200, 401, 404, connection refused). Make `HipobridgeTest`'s fake match the full
   path+query, or assert the query it was asked for.
4. PACS: run the `Pacs`-level worklist and link tests once against `dcmqrscp` with a
   multi-valued `ModalitiesInStudy` study. If the fake stays, it should match
   multi-valued modalities the way a PACS does.
5. AI: add a `fake-openai.php` model that closes the stream mid-answer without `[DONE]`.
   Decide whether that is an error (likely) and assert it. Add one that sends comment lines
   and `finish_reason: "length"`.
6. Storage: add one real crash test per multi-file write. Fork a child (`pcntl_fork`, or
   `proc_open` of a small PHP script) that stops at a hook between journal and rename, kill
   it, then replay. Keep the hand-built states as fast cases.
7. Optional: one smoke test that drives `public/index.php` through `php -S` (login → page →
   logout) so `send()` and `fromGlobals()` are exercised.
