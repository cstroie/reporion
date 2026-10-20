-- D54 step 4 (2026-10-20): a page's current visibility, as 1.x kept it in meta.json: the
-- frontmatter's `visibility` when a write gives one, else what the page had. A mirror of the
-- frontmatter in the normal case; db:recover takes it from there (private when absent).
ALTER TABLE page ADD COLUMN visibility TEXT NOT NULL DEFAULT 'private'
  CHECK (visibility IN ('private','unlisted','public'));
