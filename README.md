# Reporion

A flat-file wiki for professional imaging reports. Markdown on disk, SQLite for search,
server-rendered documents, no database server and no build step.

Built for one radiologist with several thousand reports across multiple hospitals, and for the
two things that matter at that scale: **finding a report in a second**, and **still being able
to read it in twenty years** because it is plain text in a plain folder.

## What it is

- **Pages are directories.** `data/pages/reports/mri/mioveni/260922-name/` holds the markdown,
  its revision history and its metadata. `cat` works. `grep` works. `rsync` is a full backup.
- **SQLite is a cache.** Delete `index.sqlite` and rebuild it from the files. Nothing is only
  in the database.
- **History is append-only.** Revisions are never rewritten; signed revisions keep their
  digest, and every export cites the revision it was made from.
- **DokuWiki-style namespaces.** `reports:mri:mioveni:260922-name` — a path you can type.
- **Search that works on clinical Romanian.** FTS5 with diacritic folding plus a synonym table,
  because `coledocolitiaza` must match `coledocolitiază`.
- **Public where you want it.** Private by default; individual pages can be public or unlisted,
  and the landing page is itself just a page.

## Requirements

PHP 8.1+ with `pdo_sqlite` (FTS5), `mbstring`, `intl`, `zlib`, `dom`, `ffi` (for real fsync —
`ffi.enable=1` on PHP-FPM). Any web server that can route everything to `public/index.php`. No
Node, no database server, no queue *in production* — Node is a devDependency used only by
`tests/RenderConformanceTest` to check the PHP and marked.js parsers agree (D17); the server
never touches it.

## Install

```sh
git clone … && cd reporion
composer install
cp conf/local.php.example conf/local.php
php -r 'echo password_hash("…", PASSWORD_ARGON2ID), "\n";'   # into conf/local.php
bin/reporion doctor
bin/reporion serve
```

Deployment: `docs/deploy-lighttpd.md`. Architecture: `docs/architecture-*.md`. Decisions and
their reasoning: `docs/DECISIONS.md`.

## Status

Pre-alpha, built in the open. Not a medical device; it stores and indexes documents you write.
You are responsible for the lawful handling of the data you put in it.

## Licence

GPL-3.0-or-later. See `LICENSE`.
