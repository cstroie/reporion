-- D54 step 2 (2026-10-20): page_vectors moved to its own file, data/vectors.sqlite
-- (migrations/vectors/001_init.sql), the one that grows with the embedding model and
-- costs model calls to rebuild. Storage\Databases copies an index's rows there before
-- this migration runs, so no embedding is asked for again.
DROP TABLE IF EXISTS page_vectors;
