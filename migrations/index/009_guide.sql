-- The imaging guide (D62, 2026-11-16, owner-approved schema change): the Romanian
-- "Ghid de indicații radioimagistice", loaded from data/guide/guide.csv by guide:load and
-- index:rebuild. Derived like every table here: deleting index.sqlite loses nothing (invariant 1).
-- guide_row: one row per exam of a situation, in the guide's order; guide_fts: one row per
-- situation (its exams and comments joined), so a hit is a situation.
CREATE TABLE guide_row (
  key         TEXT PRIMARY KEY,   -- situation key + '.' + n (the exam's place in the situation)
  situation   TEXT NOT NULL,      -- 12 hex of sha256(chapter|subchapter|situation), stable across renumbering
  ord         INTEGER NOT NULL,   -- the row's place in the CSV
  chapter     TEXT NOT NULL,
  subchapter  TEXT NOT NULL,
  title       TEXT NOT NULL,      -- Situația Clinică
  tip         TEXT NOT NULL,      -- G E M T N X D I A Z
  exam        TEXT NOT NULL,
  indication  TEXT NOT NULL,
  grade       TEXT NOT NULL,
  comment     TEXT NOT NULL,
  other       TEXT NOT NULL,
  dose_min    TEXT NOT NULL,
  dose_max    TEXT NOT NULL,
  therapeutic INTEGER             -- 1 Da, 0 Nu, NULL blank
);
CREATE INDEX guide_row_situation ON guide_row (situation, ord);

CREATE VIRTUAL TABLE guide_fts USING fts5(
  title,
  exams,
  comment,
  chapter,
  subchapter,
  situation UNINDEXED,
  tokenize = "unicode61 remove_diacritics 2"
);
