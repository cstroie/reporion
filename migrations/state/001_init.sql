-- data/reporion.sqlite (D54, docs/architecture-v2.md §6): the PERMANENT database.
-- Unlike index.sqlite and vectors.sqlite it is not a cache: deleting it loses what only it
-- holds, so it is backed up with data/ and its schema changes are data migrations, each
-- tested against the previous schema with rows in it. Never "delete and rebuild".
--
-- Two kinds of table:
--   mirrors of the attic zip's revlog.json (revision, signature, and page.rev / status /
--     archived_*): the zip is authoritative, db:recover and integrity:verify repair from it;
--   sole copies: everything else.
--
-- Created empty by step 2; Storage writes it from step 4 (the FileTree driver).

CREATE TABLE schema_meta (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

-- one row per page: the pid <-> path binding (the path is where the page file is)
CREATE TABLE page (
  pid          TEXT PRIMARY KEY,                 -- ULID, never changes
  path         TEXT NOT NULL,                    -- colon path; a trashed page's path at deletion
  rev          INTEGER NOT NULL,                 -- mirror
  status       TEXT NOT NULL CHECK (status IN ('draft','signed','archived')),  -- mirror
  archived_at  TEXT,                             -- mirror: the `archived` marker
  archived_by  TEXT,
  archived_rev INTEGER,
  created      TEXT NOT NULL,
  created_by   TEXT NOT NULL,
  file_sha     TEXT NOT NULL,                    -- sha256 of the page file as Storage last wrote it
  file_mtime   INTEGER NOT NULL,
  file_bytes   INTEGER NOT NULL,
  trashed_at   TEXT,                             -- NULL: live; set: its files are in trash/{pid}/
  trashed_by   TEXT
);
CREATE UNIQUE INDEX page_live_path ON page(path) WHERE trashed_at IS NULL;
CREATE INDEX page_trashed ON page(trashed_at) WHERE trashed_at IS NOT NULL;

-- mirror of revlog.json's revlog (FORMATS meta.json `revlog`, with the 2026-10-17 chain)
CREATE TABLE revision (
  pid    TEXT NOT NULL REFERENCES page(pid) ON DELETE CASCADE,
  n      INTEGER NOT NULL,
  ts     TEXT NOT NULL,
  by     TEXT NOT NULL,
  note   TEXT,
  kind   TEXT NOT NULL,                          -- create|edit|resign|move|import|revert
  minor  INTEGER NOT NULL DEFAULT 0,
  auto   INTEGER NOT NULL DEFAULT 0,
  bytes  INTEGER NOT NULL,
  sha256 TEXT NOT NULL,                          -- of the revision's bytes (the zip entry)
  chain  TEXT,                                   -- NULL for entries written before the chain
  PRIMARY KEY (pid, n)
);
CREATE INDEX revision_ts ON revision(ts DESC);

-- mirror of revlog.json's signatures (D3: a re-signed revision keeps every record)
CREATE TABLE signature (
  pid         TEXT NOT NULL REFERENCES page(pid) ON DELETE CASCADE,
  rev         INTEGER NOT NULL,
  by          TEXT NOT NULL,
  ts          TEXT NOT NULL,
  alg         TEXT NOT NULL,
  digest      TEXT NOT NULL,
  parafa      TEXT,
  field_order TEXT NOT NULL,                     -- JSON, as in meta.json
  PRIMARY KEY (pid, rev, ts)
);
CREATE INDEX signature_by ON signature(by, ts DESC);

-- moves (was meta.json `moves`): not revisions
CREATE TABLE move (
  pid       TEXT NOT NULL REFERENCES page(pid) ON DELETE CASCADE,
  from_path TEXT NOT NULL,
  to_path   TEXT NOT NULL,
  ts        TEXT NOT NULL,
  by        TEXT NOT NULL
);
CREATE INDEX move_pid ON move(pid, ts);

-- an old path and the page it now names (was a stub directory holding `redirect`):
-- resolved through the pid, so a chain cannot form
CREATE TABLE redirect (
  path TEXT PRIMARY KEY,
  pid  TEXT NOT NULL REFERENCES page(pid) ON DELETE CASCADE
);
CREATE INDEX redirect_pid ON redirect(pid);

-- attached files (was {page}/media.json, FORMATS §3b); the bytes stay in data/media/
CREATE TABLE media (
  pid    TEXT NOT NULL REFERENCES page(pid) ON DELETE CASCADE,
  sha256 TEXT NOT NULL,
  ext    TEXT NOT NULL,
  name   TEXT NOT NULL,                          -- the human name
  bytes  INTEGER NOT NULL,
  w      INTEGER,
  h      INTEGER,
  added  TEXT NOT NULL,
  by     TEXT NOT NULL
);
CREATE INDEX media_pid ON media(pid, added);
CREATE INDEX media_sha ON media(sha256);

-- comments (was {page}/comments.json, D52): at most 500 a page, text <= 4000, quote <= 300
CREATE TABLE comment (
  id          TEXT PRIMARY KEY,                  -- ULID
  pid         TEXT NOT NULL REFERENCES page(pid) ON DELETE CASCADE,
  by          TEXT NOT NULL,
  ts          TEXT NOT NULL,
  text        TEXT NOT NULL,
  quote       TEXT,
  rev         INTEGER,
  resolved_by TEXT,
  resolved_ts TEXT
);
CREATE INDEX comment_pid ON comment(pid, ts);

-- where a signed report was sent (was meta.json `deliveries`, FORMATS §4b): 200 kept a page.
-- `target` is the entry's `to`; `extra` holds its other scalar fields as JSON
CREATE TABLE delivery (
  pid     TEXT NOT NULL REFERENCES page(pid) ON DELETE CASCADE,
  target  TEXT NOT NULL,
  rev     INTEGER NOT NULL,
  at      TEXT NOT NULL,
  by      TEXT NOT NULL,
  outcome TEXT NOT NULL,
  extra   TEXT
);
CREATE INDEX delivery_pid ON delivery(pid, at);

-- a page's share token (was meta.json `share_token`, FORMATS §4): its hash, never the token
CREATE TABLE share (
  pid      TEXT PRIMARY KEY REFERENCES page(pid) ON DELETE CASCADE,
  hash     TEXT NOT NULL UNIQUE,
  created  TEXT NOT NULL,
  expires  TEXT,
  uses     INTEGER NOT NULL DEFAULT 0,
  max_uses INTEGER,
  note     TEXT
);

-- a user's server-side editor draft (was {page}/autosave.{username}.json, D25, FORMATS §3l):
-- disposable, never a revision, removed by its author's next Save; at most 2 MB
CREATE TABLE draft (
  pid      TEXT NOT NULL REFERENCES page(pid) ON DELETE CASCADE,
  username TEXT NOT NULL,
  base_rev INTEGER NOT NULL,
  raw      INTEGER NOT NULL,
  form     TEXT NOT NULL,
  saved    TEXT NOT NULL,
  PRIMARY KEY (pid, username)
);

-- accession sequences (was data/counters.json, D20): allocated under BEGIN IMMEDIATE
CREATE TABLE counter (
  key   TEXT PRIMARY KEY,                        -- {site}:{MOD}:{yy}
  value INTEGER NOT NULL
);
