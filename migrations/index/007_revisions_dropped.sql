-- D54 step 5 (2026-10-20): the index's revisions table was written on every index() and read by
-- nothing (it only ever held each page's newest revision, never the whole log). Every revision of
-- every page is in data/reporion.sqlite's `revision` table now, a mirror of the page's attic zip.
DROP INDEX IF EXISTS revisions_ts;
DROP TABLE IF EXISTS revisions;
