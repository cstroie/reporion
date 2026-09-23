-- migrations/001_init.sql — the derived index (D4, D29).
-- This file is the ONLY place the index schema is defined.
-- Deleting data/index.sqlite and replaying migrations + index:rebuild must
-- reproduce it exactly (that equivalence is a test).

PRAGMA journal_mode = WAL;
PRAGMA synchronous  = NORMAL;
PRAGMA foreign_keys = ON;

CREATE TABLE schema_meta (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
INSERT INTO schema_meta (key, value) VALUES ('schema_version', '1');

CREATE TABLE pages (
  pid               TEXT PRIMARY KEY,           -- ULID from meta.json
  path              TEXT NOT NULL UNIQUE,       -- colon path
  ns                TEXT NOT NULL,
  title             TEXT NOT NULL,
  rev               INTEGER NOT NULL,
  status            TEXT NOT NULL CHECK (status IN ('draft','signed','archived')),
  visibility        TEXT NOT NULL CHECK (visibility IN ('private','unlisted','public')),
  -- denormalised frontmatter (the facet set)
  site              TEXT,
  device            TEXT,
  accession         TEXT,
  study_date        TEXT,                       -- ISO 8601
  protocol          TEXT,
  summary           TEXT,
  patient_key       TEXT,                       -- sha256(cnp) when known (D11)
  patient_key_weak  TEXT,                       -- sha256(name|born|sex)
  -- bookkeeping
  updated           TEXT NOT NULL,
  updated_by        TEXT NOT NULL,
  bytes             INTEGER NOT NULL,
  mtime             INTEGER NOT NULL,           -- for reconciliation
  body_sha          TEXT NOT NULL,
  meta_json         TEXT NOT NULL,              -- full frontmatter
  import_batch      TEXT
);
CREATE INDEX pages_ns           ON pages(ns);
CREATE INDEX pages_status       ON pages(status, visibility);
CREATE INDEX pages_site         ON pages(site, study_date DESC);
CREATE INDEX pages_study        ON pages(study_date DESC);
CREATE INDEX pages_patient      ON pages(patient_key, study_date DESC);
CREATE INDEX pages_patient_weak ON pages(patient_key_weak, study_date DESC);
CREATE INDEX pages_accession    ON pages(accession);

-- multi-valued facets (D29)
CREATE TABLE page_modalities (
  pid      TEXT NOT NULL REFERENCES pages(pid) ON DELETE CASCADE,
  modality TEXT NOT NULL,
  PRIMARY KEY (pid, modality)
);
CREATE INDEX page_modalities_m ON page_modalities(modality);

CREATE TABLE page_regions (
  pid    TEXT NOT NULL REFERENCES pages(pid) ON DELETE CASCADE,
  region TEXT NOT NULL,
  PRIMARY KEY (pid, region)
);
CREATE INDEX page_regions_r ON page_regions(region);

-- tags and synonyms
CREATE TABLE tags (
  tag       TEXT PRIMARY KEY,
  grp       TEXT,
  icd10     TEXT,
  canonical TEXT                                -- non-null = synonym of canonical
);
CREATE TABLE page_tags (
  pid TEXT NOT NULL REFERENCES pages(pid) ON DELETE CASCADE,
  tag TEXT NOT NULL,
  PRIMARY KEY (pid, tag)
);
CREATE INDEX page_tags_tag ON page_tags(tag);

-- links, priors, templates, protocols
CREATE TABLE links (
  src      TEXT NOT NULL REFERENCES pages(pid) ON DELETE CASCADE,
  dst_path TEXT NOT NULL,
  dst_pid  TEXT,                                -- NULL = broken link report
  kind     TEXT NOT NULL CHECK (kind IN ('link','prior','template','protocol','media'))
);
CREATE INDEX links_src     ON links(src);
CREATE INDEX links_dst     ON links(dst_pid);
CREATE INDEX links_broken  ON links(dst_path) WHERE dst_pid IS NULL;

-- revision log mirror (source of truth stays meta.json)
CREATE TABLE revisions (
  pid   TEXT NOT NULL REFERENCES pages(pid) ON DELETE CASCADE,
  n     INTEGER NOT NULL,
  ts    TEXT NOT NULL,
  by    TEXT NOT NULL,
  note  TEXT,
  bytes INTEGER NOT NULL,
  kind  TEXT NOT NULL,                          -- create|edit|resign|move|import|revert
  sha256 TEXT,
  signed INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (pid, n)
);
CREATE INDEX revisions_ts ON revisions(ts DESC);

-- redirect stubs left behind by moves
CREATE TABLE redirects (
  from_path TEXT PRIMARY KEY,
  to_pid    TEXT NOT NULL,
  created   TEXT NOT NULL
);

-- full text (D5) — contentless: the disk owns the source
CREATE VIRTUAL TABLE fts USING fts5(
  title, summary, body, tags,
  pid UNINDEXED,
  content = '',
  tokenize = "unicode61 remove_diacritics 2"
);

-- import review queue (architecture-import §3)
CREATE TABLE import_review (
  batch  TEXT NOT NULL,
  pid    TEXT,
  source TEXT NOT NULL,
  field  TEXT NOT NULL,
  reason TEXT NOT NULL,
  value  TEXT,
  resolved INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX import_review_batch ON import_review(batch, resolved);

-- vectors are OPT-IN and live in migration 002 (needs a loadable extension):
--   CREATE VIRTUAL TABLE vec USING vec0(pid TEXT PRIMARY KEY, chunk INTEGER,
--                                       embedding FLOAT[1024]);
