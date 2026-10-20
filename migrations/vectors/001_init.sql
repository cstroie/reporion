-- data/vectors.sqlite (D54, 2026-10-20): the embeddings of *Similar reports*, moved out of
-- index.sqlite (was migrations/index/005_page_vectors.sql, roadmap phase 34e). One embedding
-- per report of what Context::forEmbedding() gives for it (the conclusion, de-identified), by
-- the one model Admin → AI names. A cache (invariant 1): `index:vectors` fills it from disk,
-- and deleting it loses nothing but the model calls to embed again. No foreign key to the
-- index's pages (another file), so an index rebuild keeps the vectors (`sha` says whether one
-- still matches its text); `index:vectors` drops those of pages that are gone.
CREATE TABLE schema_meta (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

CREATE TABLE page_vectors (
  pid    TEXT PRIMARY KEY,
  model  TEXT NOT NULL,       -- the embedding model, as named in Admin → AI
  sha    TEXT NOT NULL,       -- sha256 of model + text embedded: unchanged, not asked again
  dim    INTEGER NOT NULL,
  vec    BLOB NOT NULL        -- dim float32, little-endian, unit length
);
