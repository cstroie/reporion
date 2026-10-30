-- data/vectors.sqlite (docs/library.md, phase 7; schema change approved 2026-10-30): the
-- embeddings of library sources' passages, so Ask the notebook and Studio's check find a passage
-- by its meaning (a Romanian question, an English article), not only by its words. A source is
-- cut as Ask the notebook cuts it (Support\SourcePassages), one row a passage, `n` its place;
-- `sha` is the model and that passage's raw text, so a vector whose text has changed is never
-- used for the new one. A cache (invariant 1): `index:vectors` fills it from disk, a source's
-- rows replaced together; deleting the file loses nothing but the model calls. No foreign key
-- to the index's pages (another file); an index removal deletes a page's rows.
CREATE TABLE passage_vectors (
  pid    TEXT NOT NULL,
  n      INTEGER NOT NULL,    -- the passage's place in the source, from 0
  model  TEXT NOT NULL,       -- the embedding model, as named in Admin → AI
  sha    TEXT NOT NULL,       -- sha256 of model + the passage's raw text
  page   INTEGER,             -- the page marker in force where it starts, if any
  dim    INTEGER NOT NULL,
  vec    BLOB NOT NULL,       -- dim float32, little-endian, unit length
  PRIMARY KEY (pid, n)
);
