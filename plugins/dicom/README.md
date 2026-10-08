# dicom — patient and exam details from each site's PACS

Queries each site's PACS with DICOM C-FIND (study level), through dcmtk's `findscu`. We only call
out: nothing listens on a port and no image is ever retrieved.

## What it does

- **Start from a DICOM file** (`/x/dicom/file`, a button on the guided new-report form, callers who
  create reports): pick one file of a study; its header (never the pixel data) fills the guided form —
  patient name, CNP (when PatientID is one), day and time, modality, exam title, referrer, and the
  study's UID and accession, which link the new report to its study. The site is not in a file reliably,
  so the form asks. The file is sent as the request body (up to 30 MB), kept in `data/tmp/dicom/` only
  until the form reads it, then removed (an unused one goes after an hour). Needs no PACS settings.
  `bin/reporion dicom:header <file>` shows which fields a scanner's files actually fill.
- **PACS worklist** (`/x/dicom/worklist`, a button on the guided new-report form): the studies of
  the configured modalities (default CT and MR), or one of them, at every site with a PACS, or one site, over a
  date range (default the last 3 days; at most 31), optionally for one patient — a name or a CNP in
  the same field, neither required. Opening the page asks no PACS — *Query*
  does. *Start* opens the guided form filled
  from the study: patient name, CNP (the PACS patient id when it is a valid CNP), sex and birth
  year, date and time, modality, site, exam title (study description), referrer, and the study's
  UID and PACS accession number. Nothing is written until you create the report. A study that
  already has a report links to it. **Several studies of one patient** (same site, modality and
  day): tick them (at most 8) and *Start one report* — a multi-exam report, one exam per study in
  the order they were done, each with its own title, `study_uid` and `pacs_accession` (regions are
  still yours to pick; each exam also gets Reporion's own accession).
- **PACS tab** on a report: the report's patient at its site — asked for by CNP (the PACS
  PatientID), then by name — the likeliest first (same CNP, then same name). The form above the
  list is prefilled with the report's name and CNP (edit either) and the day the report gives for
  the exam (`study_date`, else the `yymmdd` of its path). The search covers that day ±2 days
  (setting *PACS tab: days around the exam date*, 0 = that day only), the closest study first;
  clear the day to search every date, or empty name and CNP to list the whole day. *Confirm* links it: one new revision that
  fills only what the report is missing (CNP, sex, birth year, study time, exam title, referrer,
  `study_uid`, `pacs_accession`). A different CNP, or a report already linked to another study,
  is refused. A report with several exams is linked from the worklist, not here: this tab
refuses a study that is none of its exams.

**Bulk link** — `bin/reporion pacs:link --site=<code> --actor=<username> [--limit=<n>] [--json]`
(`--dry-run` only reports; the actor is then optional): for every report of the site with no `study_uid`, asks the PACS and
links it when exactly one study on the report's own day matches by CNP (else by the same name) and no
other report holds it. When the day holds several studies that are all one patient (same CNP, or
same name, birth date and sex — a multi-part scan), no study is linked (which one is unknown) but the
report's blank patient fields are filled (`patient_only`). Other ambiguous and unmatched reports are counted for the PACS tab. A name match also imports the CNP when every study of the
day under that name carries the same one (two patients of one name on the day: none is imported).
Reports that already hold a `study_uid` but lack a CNP, accession or institution are refreshed by
asking for that UID (exact, so the CNP is certain); a dry run only counts them. Signed reports are linked too and return to draft (they must be signed again); the
dry run says how many. A dry run queries the PACS as well, so use `--limit` on a big site. CLI only (no
Admin → Maintenance card).
- **DICOM SR export** (`/x/dicom/sr/{pid}`, "Export DICOM SR" in a signed report's Export ▾ menu, for signed-in readers): the report's
  **signed** revision as a DICOM Basic Text SR file (`{accession or pid}-rev{N}.dcm`), for any
  signed-in reader of the report; a draft or an unsigned revision is refused (409), an anonymous
  caller gets 404 (the file names the patient, like the report's own PDF). The document follows
  TID 2000 (the file names template 2000 of the DCMR): titled *Diagnostic Imaging Report* (LOINC
  18748-4), the language, a *Current Procedure Descriptions* container per `##` exam (its title and
  the Tehnică text as Procedure Description items, then one heading container per `###` section:
  Indicație and any text above the exam → History, Concluzii → Impressions, Recomandări →
  Recommendations, anything else → Findings; the heading codes are CID 7001, the paragraphs below
  them CID 7002 TEXT items), and a last *Comment* item (not a CID 7002 element — an extension) with
  `rev N` and the `/r/{pid}/{rev}` link (D3). Completion COMPLETE, verification VERIFIED by the
  signer at the signature time. Patient name (family name first), sex, and — when the CNP is known —
  the CNP as PatientID and its birth date; the pid is the PatientID otherwise, the path is never in
  the file. UIDs are derived from the pid and revision (`2.25.…`), so exporting again gives the same
  bytes; the Study Instance UID is the report's `study_uid` when the PACS tab linked one. The
  institution is the site code (the plugin sees no letterhead). *Procedure reported* (a coded
  procedure) is left out — the exam title is the study description. This is not a DICOM digital
  signature; the verification link is the trust anchor.
- **Send SR to PACS** (`POST /x/dicom/send/{pid}`, a button in the report's PACS tab, for callers
  who may write the report; 2026-10-02, roadmap phase 22b): the same SR stored to the site's PACS
  with dcmtk's `storescu` (next to findscu, `--required`: only the SR's own SOP class proposed), as
  the site's own AE title. Offered only when the report's current revision is **signed**, it is
  **linked** to a study (`study_uid`; in a multi-exam report each exam's — one SR per study, each
  the whole report, with that exam's accession and its own series and instance) and the site's row
  in Admin → Plugins ticks **send SR** (off by default). Never automatic. Sending a revision again
  is the same instance (the PACS keeps one); a corrected, re-signed revision is a new instance in
  the same series. The file goes through a private 0700 temporary directory, never on a command
  line. Each attempt — sent or refused — is a line in the page's `meta.json` `deliveries`
  (FORMATS §4b; no revision, the signature stands) and an audit `report.deliver` line by pid; the
  tab shows the last ones, "Sent: rev N" and, after a correction, "rev M not sent". A refusal shows
  its reason; storescu's log is shown to the owner only.
- **Scanner → device** (`POST /x/dicom/device/{pid}`, the *Scanner* box on a report's PACS tab, owner
  only; 2026-10-07): a linked study keeps the scanner as the PACS names it (`pacs_device`:
  Manufacturer, model, station). When that is not one of the site's devices yet, an owner links it
  once — a new device (a code in the site's own numbering is suggested, the name is yours: "Virtutii
  GE 1.5T") or an existing one. It is stored on the device in Admin → Sites (`| pacs:` after its
  name), and from then on the worklist's *Start* and every study link fill the device by themselves
  (blanks only, never a guess from the name). Others see the box without the form.
- **Test the PACS** (`/x/dicom/echo`, owner): a C-ECHO to each configured PACS, or to one site
  with its *Test* button (on its row in Admin → Plugins, and on this screen). A failure shows the
  reason and echoscu's own verbose log — an echo carries no patient data.

## Setup

1. dcmtk ≥ 3.6.4 on the server (`findscu --version`). `echoscu` must sit next to `findscu`.
2. Admin → Sites: the sites.
3. Admin → Plugins → *DICOM (PACS query)*: enable, then set the full path of `findscu` and, for
   each site, the PACS host, port and AE title, and **our AE title for that PACS** — the calling AE
   title it identifies us by, each site its own. A site missing any of these is not queried (leave
   the host empty for a site without a PACS).
4. Save, then press *Test* on each site's row (it uses the saved values).

## What is sent

A study lookup sends only the study date or range, the modality and the study's UID; the
worklist sends the same, plus the patient's CNP or name when one is typed in its patient field
(2026-10-01: all digits is a CNP). The report's PACS tab also sends the patient's CNP or name (D39, amended
2026-09-30). Either goes to the site's own PACS only, in a query file: never on a command line (visible in
`ps`), in a URL or in a log. The tab's form is a POST for the same reason. Turn it off with the
plugin setting *Search a report's PACS tab by patient*: then only date and modality go out and the
tab lists the day's studies. Ranking a study against the report is done here, after the answer. No query, answer or tool output is logged (invariant 8): errors
are fixed codes (unreachable, rejected, timeout, no-tool, failed).
