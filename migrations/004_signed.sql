-- Roadmap phase 24a (2026-10-02): a report's first signature — when and by
-- whom — for /stats (turnaround: exam date → first signature) and the start
-- page's "This month" card. meta.json's `signatures[0]`; NULL for a page
-- never signed. Derived from meta.json like every column here: rows indexed
-- before this migration stay NULL until `index:rebuild` (doctor says so).
ALTER TABLE pages ADD COLUMN signed_at TEXT;
ALTER TABLE pages ADD COLUMN signed_by TEXT;
CREATE INDEX pages_signed ON pages(signed_at DESC);
