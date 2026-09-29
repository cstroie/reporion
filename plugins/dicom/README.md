# dicom — patient and exam details from each site's PACS

Queries each site's PACS with DICOM C-FIND (study level), through dcmtk's `findscu`. We only call
out: nothing listens on a port and no image is ever retrieved.

## What it does

- **PACS worklist** (`/x/dicom/worklist`, a button on the guided new-report form): the studies of
  the configured modalities (default CT and MR) at every site with a PACS, or one site, over a
  date range (default the last 3 days; at most 31). *Start report* opens the guided form filled
  from the study: patient name, CNP (the PACS patient id when it is a valid CNP), sex and birth
  year, date and time, modality, site, exam title (study description), referrer, and the study's
  UID and PACS accession number. Nothing is written until you create the report. A study that
  already has a report links to it.
- **PACS tab** on a report: the studies of the report's site and day for its modality — the
  likeliest first (same CNP, then same name). *This is the study* links it: one new revision that
  fills only what the report is missing (CNP, sex, birth year, study time, exam title, referrer,
  `study_uid`, `pacs_accession`). A different CNP, or a report already linked to another study,
  is refused.
- **Test the PACS** (`/x/dicom/echo`, owner): a C-ECHO to each configured PACS, or to one site
  with its *Test* button (on its row in Admin → Plugins, and on this screen). A failure shows the
  reason and echoscu's own verbose log — an echo carries no patient data.

## Setup

1. dcmtk ≥ 3.6.4 on the server (`findscu --version`). `echoscu` must sit next to `findscu`.
2. Admin → Settings → Sites: the sites.
3. Admin → Plugins → *DICOM (PACS query)*: enable, then set the full path of `findscu` and, for
   each site, the PACS host, port and AE title, and **our AE title for that PACS** — the calling AE
   title it identifies us by, each site its own. A site missing any of these is not queried (leave
   the host empty for a site without a PACS).
4. Save, then press *Test* on each site's row (it uses the saved values).

## What is sent

Only the study date or range, the modality and — for one study — its UID. No patient name or CNP
is ever sent to the PACS or put on a command line (visible in `ps`); matching a study to a report
is done here, after the answer. No query, answer or tool output is logged (invariant 8): errors
are fixed codes (unreachable, rejected, timeout, no-tool, failed).
