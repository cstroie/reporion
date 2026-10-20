# Installing Reporion

The steps to put Reporion on a server, in order. Details live in `docs/deploy-lighttpd.md`
(web server and PHP-FPM) and `docs/FORMATS.md` (what goes in `data/`). **Run every
`bin/reporion` command as the web server's user** (`sudo -u www-data …`): commands write to
`data/`, and a file created by another user is one the site can no longer write.

A checklist to tick off before the site goes live is at the end: [Before going live](#before-going-live).

## The web installer (steps 1–5 from the browser)

Steps 1 to 5 can also be done from a browser (D43):

1. Unpack the archive on the web server: either where the address maps to its `public/`
   directory (recommended, step 3), or simply inside an existing web root, e.g.
   `/var/www/html/reporion` served as `/reporion/` ("root mode").
2. Let the web server's user write `conf/` and create `data/` (the installer names the exact
   commands if it cannot), e.g. `sudo chgrp www-data . conf && sudo chmod 2775 . conf`.
3. Open the address. Until `conf/local.php` exists every page leads to `install.php`, which:
   - **Requirements**: checks PHP, its extensions, Argon2id, FTS5, FFI *in the web server's
     PHP* (what `doctor` cannot see), limits, and what it needs to write; anything it cannot fix
     comes with the command that does.
   - **Packages**: runs `composer install --no-dev` as the web user (the server's composer, or
     a downloaded `composer.phar` checked against its published SHA-256); or run it yourself.
   - **Security**: writes the Apache `.htaccess` files, sets modes, then your browser checks
     that `data/`, `conf/`, `composer.json` and `.git/` are **not** served. On lighttpd or nginx
     it prints the configuration to add (root needs to add it); nothing secret is written, and it
     does not go on, while anything private is reachable.
   - **Site and owner**: site name, public address, time zone and the first owner account; writes
     `conf/local.php` (new session secret, mode 0640), `data/settings.yaml`, the account and the index.
     *Create the core prompts* (ticked by default: the rail's Ask action, summary, tags; the radiology set, in Romanian) makes editable prompt pages of
     the default profile from `resources/prompts/`; a second tick adds the prompts of the other features (ask the
     archive, recall, evolution, check before signing, revision note, tags review). Later, for any profile:
     Admin → AI or `bin/reporion ai:prompts-install`. Nothing is sent until an AI server is set up.
   - **Final checks**: the browser probe again and the sign-in page, a real `fsync` from the web
     server's PHP, the index, and every `doctor` check; then *Finish and sign in*.

Everything it does is logged in `data/install.log` (no password, hash or secret). Once finished,
`install.php` only redirects to the site; tick *Also delete install.php* on the last step, or
delete `install.php` and `public/install.php` yourself. An existing instance: one with a
`conf/local.php`: is never offered the installer. An install in progress belongs to the browser
that began it; another browser sees "in progress" (an abandoned one can be taken over after an
hour, but not once its owner account exists; `rm conf/.install-state.json` ends it by hand).

Then carry on with step 6 (instance settings) and step 7 (scheduled jobs, backups).

### Making a release archive

From a clone, on the commit to release:

```sh
tools/release.sh                 # dist/reporion-<version>.tar.gz and .zip; the installer runs Composer
tools/release.sh --with-vendor   # vendor/ included, for a server that cannot run Composer
tools/release.sh v1.2.0          # any tag or commit
```

Without the script: `git archive --format=tar.gz --prefix=reporion/ -o reporion.tar.gz HEAD`.
Either way only committed files go in, under `reporion/`, and `.gitattributes` leaves out what
only development needs (`tests/`, `design/`, `tools/`, `.claude/`, `CLAUDE.md`, `TODO.md`, the
PHPUnit/PHPStan/CS-Fixer/npm files). No `data/`, `conf/local.php` or `vendor/` is ever in the
archive unless asked (`--with-vendor`). Prefer the `.tar.gz`: it keeps the `public/assets`
symlink; a `.zip` extracted on some systems loses it (the installer then re-creates it).

## 1. Requirements

- PHP 8.1+ with `pdo_sqlite` (with FTS5), `sqlite3`, `mbstring`, `intl`, `zlib`, `dom`, `xml`,
  `iconv`, `gd` (images in PDFs), `ffi`, and Argon2id support in `password_hash()` (Debian/Ubuntu
  PHP packages have it). `composer install` refuses a host that lacks one of these, and `doctor`
  (step 5) names what is missing. `zip` is not needed: ODT export falls back to the PclZip bundled
  with PHPWord. The **CLI and PHP-FPM read different `php.ini` files** (`/etc/php/8.1/cli` vs
  `/etc/php/8.1/fpm`): check the extensions and `ffi.enable` for FPM too, not only for the CLI.
- PHP-FPM behind lighttpd (D23): any server that can serve `public/` and route everything
  else to `public/index.php` will do, but the docs cover lighttpd.
- Composer. No Node, no database server, no queue in production (Node is only for the render
  conformance test, D17).

## 2. Code and configuration

```sh
git clone … /var/www/html/reporion && cd /var/www/html/reporion
composer install --no-dev
cp conf/local.php.example conf/local.php
php -r 'echo bin2hex(random_bytes(32)), "\n";'   # → auth.session_secret in conf/local.php (empty = nobody can sign in)
```

`conf/local.php` keeps only what is needed before `data/` can be found: **paths**, and
**secrets** (the session key). Everything else about the instance is edited in Admin → Settings
and kept in `data/settings.yaml` (step 6); values still in `conf/local.php` are only fallbacks.

Ownership: the code may belong to you; `data/` must belong to the web server's user, and
`conf/local.php` be readable by it (and nobody else: it holds the session secret).

### Create `data/`

`data/` is gitignored, so **a fresh clone has none**: create it before the first command. It holds
patient reports, so no access for "others":

```sh
sudo install -d -o www-data -g www-data -m 2770 data
sudo chgrp www-data conf/local.php && sudo chmod 640 conf/local.php
```

Everything inside is then created by the web server's user. **Run every `bin/reporion` command
as that user** (`sudo -u www-data …`): a CLI run as yourself writes `index.sqlite`, its `-wal`/`-shm`
files, `data/users/*.json` and audit lines under your name, and PHP-FPM can no longer write them
(`doctor` catches the audit file, not the index). If it happens anyway:
`sudo chown -R www-data:www-data data`. To use the CLI as yourself, join the group instead
(`usermod -aG www-data you`, `umask 007`): never open the directory to others.

`data/`, `conf/local.php` and `uploads/` are gitignored and must never be committed (D33).

## 3. Web server

The document root is `public/` **only** (D23):`data/`, `conf/` and the code stay outside it.
The full lighttpd and PHP-FPM configuration is in `docs/deploy-lighttpd.md`; the points that
break a site silently when missed:

- **Rewrite** every non-file request to `public/index.php` (lighttpd has no `.htaccess`).
- **`public/assets` is a symlink** to `../assets`: don't disable `server.follow-symlink`.
- **`php_admin_value[ffi.enable] = 1`** in the FPM pool: every save needs a real `fsync()`
  (invariant 7); without it every write fails.
- **`post_max_size` / `upload_max_filesize`** at least the largest pasted image (Admin →
  Settings → Limits, 8 MB by default).
- **Asset caching** (pending on the dev box): stylesheet and script URLs carry `?v=…`, so they
  can be cached for a year; the fonts they load for a week. Mounted in a subfolder, as here:

```
server.modules += ( "mod_setenv" )

$HTTP["url"] =~ "^/reporion/assets/" {
    $HTTP["querystring"] =~ "^v=" {
        setenv.add-response-header = ( "Cache-Control" => "public, max-age=31536000, immutable" )
    } else {
        setenv.add-response-header = ( "Cache-Control" => "public, max-age=604800" )
    }
}
```

Reload lighttpd, then check:
`curl -sI 'http://localhost/reporion/assets/css/wiki.css?v=1' | grep -i cache-control`.

## 4. First owner account

Accounts are created only this way (D35: no self-service sign-up):

```sh
php -r 'echo password_hash("the password", PASSWORD_ARGON2ID), "\n";'
sudo -u www-data bin/reporion user:create --username=<name> --password-hash='<hash>' --owner
```

Quote the hash: it contains `$` characters the shell would expand. More accounts, their grants
(`--grant=reports:mri:editor`, `--grant=reports:viewer`) and password resets are then managed in
Admin → Users.

## 5. Checks

```sh
sudo -u www-data bin/reporion doctor
```

It checks: PHP version, extensions, Argon2id, FTS5; that an owner exists and the session secret is
set; that `conf/local.php` is not world-readable (warning); that `data/` and `data/audit/` accept a
real write as the user running it (a missing `data/` fails and points back to "Create `data/`");
that FFI works **in the CLI**; the time zone; that `settings.yaml`/`tags.yaml` parse; the AI
settings' shape; and: against the **public address** from step 6, that `/.git/config` and
`/composer.json` are not served (the docroot is `public/` only) and that the front controller
answers. Those two warn until the address is reachable (the shipped placeholder
`reports.example.ro` never is) and **fail** on a leaked file or an HTTP error. It also warns
when `integrity:verify` has not run for 8 days.

What `doctor` cannot see: `ffi.enable` and `open_basedir` **in PHP-FPM** (its FFI check runs in
the CLI, which trusts FFI whatever the setting). After the first sign-in, save a page: if it
fails with a 500, that is `ffi.enable`: see `docs/deploy-lighttpd.md`. Plugin binaries (`findscu`,
`groff`) are tested from their own admin screens.

## 6. Instance settings

Signed in as the owner, **Admin → Settings**:

- **Site**: name, tagline, **public address** (printed in every export's verification link:
  set it before exporting anything real), home page for visitors, time zone, icon.
- **Publishing and export**: namespaces with a feed, what visitors may export.
- **Limits**: days in the trash, largest pasted image.
- **Reports**: modality → namespace (`MR = mri` files MR reports under `reports:mri:`).
- **Sites and devices**: each site's printed letterhead and its devices. Leave **accession
  code** empty to continue an existing series: imported accessions read `SCUC-MR-23-1764`, so
  new ones continue as `SCUC-MR-26-…`; setting a code (e.g. `MV`) starts a separate series.

**Report templates** are ordinary pages under `templates:{modality-ns}:` (e.g.
`templates:mri:cerebral-nativ`); the new-report form offers them per modality and copies their
text and exam fields: never patient fields. Create and edit them in the app like any other
page.

## 6b. Optional: plugins and the AI assistant

Everything here is configured in Admin → Settings / Admin → Plugins once signed in as the owner
nothing to edit in `conf/local.php` or on disk.

- **HIS worklist** (`plugins/hipobridge`). Admin → Plugins → *HippoBridge (FHIR HIS)*: address,
  a service account for Hipocrate, site code, which modalities and how many days back. Read only
  (D38): nothing is ever written back to the HIS. Details: `plugins/hipobridge/README.md`.
- **PACS worklist and study linking** (`plugins/dicom`). Admin → Plugins → *DICOM (PACS)*: one
  row per site (host, port, called AE, our AE title, optionally *send SR*). Needs dcmtk's
  `findscu`/`echoscu`/`storescu` installed on the server (≥ 3.6.4, for `--extract-xml`): the full
  path to `findscu` is a plugin setting; `echoscu`/`storescu` are expected next to it. We only
  call out: nothing listens on a port, and no image is ever retrieved (D39). Details:
  `plugins/dicom/README.md`.
- **groff export** (`plugins/groff`). Admin → Plugins → *groff export*: an ordinary Markdown page (never a
  report) as groff ms source or a PDF. Needs the Debian/Ubuntu package `groff` (`apt install groff`; `groff-base` is not
  enough): check `ls /usr/share/groff/current/tmac/s.tmac` and `command -v gropdf`. Install a font
  with the language's glyphs (ă â î ș ț) for groff and name its family in the plugin's *Font family*
  setting. Details: `plugins/groff/README.md`, `docs/deploy-lighttpd.md`.
- **AI assistant** (phase 15 onward). Admin → AI: up to six OpenAI-compatible servers (address,
  API key, and per alias: lite/normal/expert: a model and its parameters), which one is in use,
  and the prompt profile. The API key is kept in `data/settings.yaml` (mode 0640) and never shown
  back; report text is de-identified (names, CNPs, accessions, paths stripped) before it ever
  reaches a server, and a non-local server needs an explicit *report text may leave this network*
  acknowledgement. *Get models* / *Test* on each server card check reachability without sending
  any report text. For **Similar reports**, also pick one server/model as the embedding model,
  then run **Admin → Maintenance → Vectors for Similar reports** (or `bin/reporion index:rebuild
  --vectors`) once. See `docs/architecture-api.md` (`/admin/ai`) for the full settings list.

## 7. Scheduled jobs

As the web server's user. First open its crontab in your editor:

```
sudo crontab -u www-data -e
```

Then add these lines at the end, save and close:

```
# Reporion tasks
37 4 * * *  cd /var/www/html/reporion && bin/reporion index:verify --json > /dev/null
17 5 * * *  cd /var/www/html/reporion && bin/reporion trash:purge
47 5 * * 0  cd /var/www/html/reporion && bin/reporion integrity:verify --json > /dev/null
```

- `index:verify` only reports drift; the fix is **Admin → Index & storage → Rebuild** (or
  `index:rebuild`). Its `--json` report is kept in Admin → Maintenance either way.
- `integrity:verify` (weekly) re-reads every revision, signature and media file; it only reports,
  kept in Admin → Maintenance, and `doctor` warns when it has not run for 8 days or found problems.
  `--backup=<dir>` also checks a mounted backup copy of `data/`.
- `trash:purge` removes pages deleted more than the configured days ago; signed reports stay
  unless purged explicitly (D3b).
- Crashed writes are finished automatically on the next request; an older backlog is listed in
  Admin → Maintenance ("Unfinished writes") for someone to look at first.

- The audit log (`data/audit/YYYY-MM.ndjson`) and the write journal (`data/journal/YYYY-MM-DD.ndjson`)
  are never pruned or rotated by the app. They are small; look at `du -sh data/audit data/journal`
  once a year, and archive old months with the backups.

**Backups** (D22): rsync `data/` over SSH into dated `--link-dest` snapshots on an encrypted
volume: the commands are in `docs/deploy-lighttpd.md`. `data/` is everything: pages, history,
accounts, settings, media, audit. `data/index.sqlite*` can be left out (it is rebuilt from disk).
Back up `conf/local.php` as well (the session secret; losing it only signs everybody out, but the
file also holds the paths). Test a restore once: copy a snapshot to a scratch directory, point
`paths.data` at it, and run `index:rebuild` and `integrity:verify --backup=<dir>`.

## 7b. Moving an instance (staging → production) and archive backups

`bin/reporion backup:create` writes one archive:`data/backups/reporion-backup-<time>.tar.gz`
(D44): of `data/` (pages, history, accounts, settings, media, audit, trash) and `conf/local.php`,
with a checksum of every file. The index, `data/tmp/` and earlier archives are left out. It is
**not encrypted** and holds patient data and the session secret: move it over SSH, keep it as
carefully as `data/`, delete the copies you no longer need. rsync (step 7) stays the routine backup.

On the old machine, as the web user:

```sh
sudo -u www-data bin/reporion backup:create
sudo -u www-data bin/reporion backup:verify data/backups/reporion-backup-<time>.tar.gz
scp data/backups/reporion-backup-<time>.tar.gz you@production:/tmp/
```

On the new machine: install normally first (the web installer or steps 1–5, so there is a
working `conf/local.php`, web server and `vendor/`), then, as the web user:

```sh
sudo -u www-data bin/reporion backup:verify /tmp/reporion-backup-<time>.tar.gz
sudo -u www-data bin/reporion backup:restore /tmp/reporion-backup-<time>.tar.gz --replace
sudo -u www-data bin/reporion index:vectors      # only if Similar reports is used
```

- Restore must run as the owner of `data/` (it refuses otherwise): files written by anyone else
  would not be writable for the web server.
- Nothing is replaced until the whole archive has been read and every file matches its checksum.
  Then the current content of `data/`: including the owner the installer created: is moved to
  `data/.pre-restore-<time>/`, not deleted; remove it when you are satisfied. After the swap the
  command replays the journal, rebuilds the index and runs the integrity check.
- Sign in with the **old** machine's accounts: they came with the archive.
- `conf/local.php` belongs to its machine (paths, session secret). By default the archive's copy
  is left beside as `conf/local.php.from-backup`. `--with-conf` installs it only when it names
  this same data directory; the current file is kept as `local.php.pre-restore-<time>`.
- Check Admin → Settings (the public address and the web-server paths differ on a new machine) and
  run `bin/reporion doctor`.

## 8. Going public

The site sits behind a TLS front server that forwards the subfolder to this box. On that front
server (lighttpd, `mod_proxy`):

```
$HTTP["url"] =~ "^/reporion/" {
    proxy.server    = ( "" => (( "host" => "<this box's address>", "port" => 80 )) )
    proxy.forwarded = ( "for" => 1, "proto" => 1 )
}
```

`proto` makes the session cookie `Secure`. `for` passes the visitor's address along, but
Reporion does not read it yet: the audit log records the front server's address until that is
built. Set the public address in Admin → Settings, then re-run `doctor` (step 5): both HTTP checks should pass.

## 9. Existing archive

The DokuWiki importer was removed 2026-10-09; the archive is already in. If you restore pages
from a backup, run `index:rebuild` and check Admin → Maintenance.

## 10. Updating

```sh
cd /var/www/html/reporion && git pull && composer install --no-dev
sudo -u www-data bin/reporion doctor
sudo -u www-data bin/reporion index:verify
```

Stylesheets and scripts pick up the new version by themselves (versioned URLs). Index schema
changes are applied by the first process that opens the index, so run `doctor`/`index:verify` as
`www-data` (never as yourself, see step 2). If a release note says the index changed, run
**Admin → Index & storage → Rebuild**. Take a backup first.

## Before going live

- [ ] `doctor` passes **as `www-data`, with the public address set**: no FAIL, and the two HTTP
      checks PASS rather than WARN (step 5 and step 8).
- [ ] A page can be created, saved twice and exported to PDF through the web (not the CLI): this
      proves `ffi.enable` and `open_basedir` in PHP-FPM.
- [ ] Visibility: as a signed-out visitor, a private page and a report return **404** (page, search,
      print, PDF, `/api/v1`), and `/data/…`, `/conf/local.php` and `/.git/config` are not served.
- [ ] `index:rebuild` and `integrity:verify` run clean on the real data; the three cron lines of
      step 7 are installed for `www-data`.
- [ ] A backup ran and a restore was tested (step 7).
- [ ] Asset caching rule (step 3) and, if the site is mounted in a subfolder, the alias/rewrite
      pair from `docs/deploy-lighttpd.md`.
- [ ] TLS front proxy (step 8); Admin → Settings has the public address, the sites and devices,
      and the report templates (step 6).
- [ ] Optional plugins/AI configured and tested from their admin screens (step 6b).
- [ ] `ls -l conf/local.php data/users` show mode 640 and the web server's group; no account uses
      a throw-away password.

## Development

`bin/reporion serve` runs PHP's built-in server with the right document root and
`ffi.enable=1`. It sends no caching headers and serves one request at a time, so fonts arrive
late and pages render in the system font there: expected, not a bug. Tests: `composer test`
(`composer test -- tests/Http/` for one suite); the render conformance test needs `npm install`.
