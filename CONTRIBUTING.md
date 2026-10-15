# Contributing to Reporion

Thanks for looking. Reporion handles medical documents, so a few rules are firm.

## Before you start

- Read `docs/architecture-storage-index.md` and `docs/architecture-api.md`, and the invariants in
  `CLAUDE.md`. Breaking one is a bug even when the tests pass.
- **Open an issue first** for anything that adds a dependency, changes the on-disk layout or the
  index schema, adds an API endpoint, or touches signing, revisions or the account/grant model.
- **Never include patient data**: no real report, name, CNP or accession in code, tests, fixtures,
  issues, screenshots or commit messages. Fixtures in `tests/fixtures/` are anonymised.

## Setup

```
composer install
bin/reporion serve
```

PHP 8.1+, `declare(strict_types=1)`, PSR-12, final classes, constructor injection.

## Tests

Run the suites relevant to your change (`composer test -- tests/Storage/`); the full suite
(~3 min) is for before you submit. A change is expected to come with its tests:
storage (round trips, crash recovery), render fixtures (`tests/Render/RenderFixturesTest`), index
(`rebuild` equals incremental), and visibility (a case for every new listing endpoint).

Changes to print templates need a rendered-PDF check, not only a browser one.

## Commits and pull requests

- Small commits with their tests; prefixes `feat:`, `fix:`, `refactor:`, `docs:`.
- When docs and code disagree, fix the docs in the same commit.
- New endpoints get a row in `docs/architecture-api.md`.

## Licence

By contributing you agree that your work is licensed under GPL-3.0-or-later.
