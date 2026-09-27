-- Phase 12 (docs/FORMATS.md §12): a multi-exam report's exams, one row each,
-- derived from the frontmatter `exams:` list like page_regions from `region`.
-- Its accessions are searchable and seed the D20 counter, so the 2nd and 3rd
-- exam's numbers are never allocated again.
CREATE TABLE page_exams (
  pid       TEXT NOT NULL REFERENCES pages(pid) ON DELETE CASCADE,
  n         INTEGER NOT NULL,                   -- 1-based, the body's Nth ## exam
  title     TEXT NOT NULL,
  accession TEXT,
  PRIMARY KEY (pid, n)
);
CREATE INDEX page_exams_accession ON page_exams(accession);
