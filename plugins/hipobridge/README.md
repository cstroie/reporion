# hipobridge: HippoBridge (FHIR HIS), v0.6

Reads the hospital information system through [HippoBridge](https://github.com/cstroie/hipobridge)'s
FHIR interface (the same one XRayVision uses). Read only: nothing is ever written to the HIS.

## What it does

- **HippoBridge worklist** (`/x/hipobridge/worklist`, *From HippoBridge* on the guided new-report form): the exams of
  the configured modalities (default CT and MRI) performed in the last few days (default 3).
  *Start* opens the guided form filled from the exam's order: patient name and CNP, date
  and time, modality, region, exam title, referring physician, indication, and records the order
  as `order_ref`. Nothing is written until you create the report. An exam that already has a
  report links to it.
- **Priors** (the *HippoBridge* tab of a report, for writers): finds the report's patient in the HIS (by CNP, else by
  name: you choose when several match), lists their other exams, and imports the ones you tick as
  **archived** pages under `reports:` (the other radiologist's text, with `radiologist`,
  `imported_from` and `order_ref`; never signed here). The report itself gets one new revision
  that fills only what it is missing. CNP, sex, birth year, name, referrer, indication, order,
  exam title and region; the study time and modality only when they agree with the report's path,
  never the site, and adds the imported pages to its `priors`. Its text is never changed. A CNP in
  the HIS that differs from the report's stops everything.
- **Ask HippoBridge** (v0.6): not a page or a button of its own; part of *Ask the archive* (D53) for a radiologist
  with the report write grant (the plugin answers the core hook `ask.sources`; else nothing is added). The question
  is answered from this wiki's finished reports and the HIS's own indexed texts together, in one `ask-archive` call
  of the assistant, cited by number, audited `ai.call`. The HIS passages come from HippoBridge's FHIR full-text
  search (`/fhir/Patient?content=`), with the text from `/fhir/Encounter/{id}?type=checkout` (epicrisis) or
  `/fhir/ImagingStudy/{id}` (imaging). Only content words of the question are sent to HippoBridge (never the
  question, never a name, number or mid-sentence capitalised word); each hit's patient name and CNP are used only to
  de-identify its passages and never reach the prompt, the answer, the audit or a log. A hit without a name is
  dropped. Not behind the visibility rules, which cannot filter the HIS. If HippoBridge fails or refuses the account,
  the answer carries a notice and goes on with the wiki's passages. Not yet checked against a real HippoBridge
  answer; the shapes follow its spec (2026-10-19).
- Dates are shown as *06 Jan 2026, 20:21*; the order's clinical indication is shown under the
  requester when HippoBridge's list answers carry it (`/fhir/Schedule` and
  `/fhir/ServiceRequest?patient=` did not as of 2026-09-29: only `/fhir/ServiceRequest/{id}` does).

## Setup

Admin → Plugins → *HippoBridge (FHIR HIS)*: set it to *Enabled*, then set

| setting | |
|---|---|
| HippoBridge address | e.g. `http://127.0.0.1:44660` |
| Hipocrate user / password | one service account; HippoBridge forwards it to Hipocrate |
| Site code | the site (Admin → Sites) for pages made from HIS data |
| Worklist modalities | HIS codes: `ct`, `irm`, `eco`, `radio` |
| Worklist days back | 1–31; default 7 (one week) |
| Worklist rows per modality | `limit=` sent with each modality's Schedule request; default 200 |
| Prior exam types | which of the patient's exams are offered as priors |

The password is stored in `data/settings.yaml` (mode 0640) and never shown back.

## HippoBridge endpoints used

`GET /fhir/Schedule`, `/fhir/ServiceRequest/{id}`, `/fhir/Patient?q=`, `/fhir/Patient/{id}`,
`/fhir/ServiceRequest?patient=`, `/fhir/DiagnosticReport/{id}`, and for the Ask sources `/fhir/Patient?content=`, `/fhir/Encounter/{id}` and `/fhir/ImagingStudy/{id}`: HTTP Basic auth, GET only.

No URL, query or answer from the HIS is ever logged or put in an error message: they carry CNPs
and names (invariant 8).
