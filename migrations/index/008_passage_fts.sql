-- Library search and ask (2026-11-02, owner-approved schema change): each library source's
-- passages, cut as Support\SourcePassages cuts them (the cut index:vectors embeds, so (pid, n)
-- names one passage here and in passage_vectors), in a full-text table of their own. Ask the
-- notebook, Ask the library and the library's search find passages here by BM25 instead of
-- reading every source. Derived per page by index(), like fts: index:rebuild fills it again and
-- deleting it loses nothing (invariant 1). Not contentless, so snippet() works.
CREATE VIRTUAL TABLE passage_fts USING fts5(
  text,
  pid UNINDEXED,
  n UNINDEXED,
  page UNINDEXED,
  tokenize = "unicode61 remove_diacritics 2"
);
