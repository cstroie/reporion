-- Roadmap phase 34e (2026-10-08, owner-approved schema change): one
-- embedding per report for *Similar reports* — what Context::forEmbedding()
-- gives for it (the conclusion, de-identified), embedded by the one model
-- Admin → AI names (`ai.embed_server`, `ai.embed_model`). A cache like the
-- rest of this file (invariant 1): `index:vectors` fills it from disk, and
-- deleting it loses nothing but the time to embed again. Not derived per
-- page by index(): no foreign key to pages, so a rebuild keeps the vectors
-- (`sha` says whether one still matches its text); `index:vectors` drops
-- those of pages that are gone.
CREATE TABLE page_vectors (
  pid    TEXT PRIMARY KEY,
  model  TEXT NOT NULL,       -- the embedding model, as named in Admin → AI
  sha    TEXT NOT NULL,       -- sha256 of model + text embedded: unchanged, not asked again
  dim    INTEGER NOT NULL,
  vec    BLOB NOT NULL        -- dim float32, little-endian, unit length
);
