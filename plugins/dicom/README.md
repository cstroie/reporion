# dicom — patient and exam details from each site's PACS

Queries each site's PACS with DICOM C-FIND (study level), through dcmtk's `findscu`. We only call
out: nothing listens on a port and no image is ever retrieved.

## What it does

- **PACS worklist** (`/x/dicom/worklist`, a button on the guided new-report form): the studies of
  the configured modalities (default CT and MR), or one of them, at every site with a PACS, or one site, over a
  date range (default the last 3 days; at most 31). Opening the page asks no PACS — *Query*
  does. *Start* opens the guided form filled
  from the study: patient name, CNP (the PACS patient id when it is a valid CNP), sex and birth
  year, date and time, modality, site, exam title (study description), referrer, and the study's
  UID and PACS accession number. Nothing is written until you create the report. A study that
  already has a report links to it.
- **PACS tab** on a report: the report's patient at its site — asked for by CNP (the PACS
  PatientID), then by name — the likeliest first (same CNP, then same name). The form above the
  list is prefilled with the report's name and CNP (edit either) and the day the report gives for
  the exam (`study_date`, else the `yymmdd` of its path). The search covers that day ±2 days
  (setting *PACS tab: days around the exam date*, 0 = that day only), the closest study first;
  clear the day to search every date, or empty name and CNP to list the whole day. *Confirm* links it: one new revision that
  fills only what the report is missing (CNP, sex, birth year, study time, exam title, referrer,
  `study_uid`, `pacs_accession`). A different CNP, or a report already linked to another study,
  is refused.

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
  signature; the verification link is the trust anchor. Read-only: nothing is sent to a PACS.
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

The worklist and a study lookup send only the study date or range, the modality and — for one
study — its UID. The report's PACS tab also sends the patient's CNP or name (D39, amended
2026-09-30), to the site's own PACS only, in a query file: never on a command line (visible in
`ps`), in a URL or in a log. The tab's form is a POST for the same reason. Turn it off with the
plugin setting *Search a report's PACS tab by patient*: then only date and modality go out and the
tab lists the day's studies. Ranking a study against the report is done here, after the answer. No query, answer or tool output is logged (invariant 8): errors
are fixed codes (unreachable, rejected, timeout, no-tool, failed).
