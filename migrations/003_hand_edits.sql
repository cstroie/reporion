-- TODO 13 (2026-09-27): the newest revision a person made, beside the newest
-- revision of all (updated, updated_by). An import, a maintenance run, a tag
-- rename or a move's link fixups write revisions under the operator's name
-- (`"auto": true` in the revlog, or the notes they wrote before the flag —
-- Support\Revlog); the dashboard follows these columns instead, so its
-- "recently changed" and "my drafts" are what people did. NULL: nobody has
-- edited the page by hand (an imported report no one has opened).
-- Derived from meta.json like every column here: `index:rebuild` fills them.
ALTER TABLE pages ADD COLUMN edited TEXT;
ALTER TABLE pages ADD COLUMN edited_by TEXT;
CREATE INDEX pages_edited ON pages(edited DESC);
