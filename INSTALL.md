# Installing Reporion

The steps to put Reporion on a server, in order. Details live in `docs/deploy-lighttpd.md`
(web server and PHP-FPM) and `docs/FORMATS.md` (what goes in `data/`). **Run every
`bin/reporion` command as the web server's user** (`sudo -u www-data …`): commands write to
`data/`, and a file created by another user is one the site can no longer write.

## Still to do on the development box

Deliberately left for later on this server (`redstone`, mounted at `/reporion/`, not public yet):

- [ ] **Asset caching rule** in `/etc/lighttpd/conf-available/50-reporion.conf` — see
      [Web server](#3-web-server). Without it pages still work, but browsers keep re-checking
      stylesheets and fonts, and over a slow link a page may stay in the system font.
- [ ] **TLS front proxy** for `https://eridu.eu.org/reporion/` → this box — see
      [Going public](#8-going-public). Until then the public address answers 404, by design.
- [ ] **`doctor` with the site public** — its "front controller reachable" check fetches the
      public address (roadmap phase 2, the last operator check).
- [ ] **Sites and devices** in Admin → Settings (only `mioveni` is known today) — see
      [Instance settings](#6-instance-settings).
- [ ] **Report templates** under `templates:{mri,ct,…}:` — see [Instance settings](#6-instance-settings).

## 1. Requirements

- PHP 8.1+ with `pdo_sqlite` (with FTS5), `sqlite3`, `mbstring`, `intl`, `zlib`, `dom`, `ffi`,
  and `gd` (images in PDFs). `zip` is not needed: ODT export falls back to the PclZip bundled with PHPWord.
- PHP-FPM behind lighttpd (D23) — any server that can serve `public/` and route everything
  else to `public/index.php` will do, but the docs cover lighttpd.
- Composer. No Node, no database server, no queue in production (Node is only for the render
  conformance test, D17).

## 2. Code and configuration

```sh
git clone … /var/www/html/reporion && cd /var/www/html/reporion
composer install --no-dev
cp conf/local.php.example conf/local.php
php -r 'echo bin2hex(random_bytes(32)), "\n";'   # → auth.session_secret in conf/local.php
```

`conf/local.php` keeps only what is needed before `data/` can be found — **paths** — and
**secrets** (the session key). Everything else about the instance is edited in Admin → Settings
and kept in `data/settings.yaml` (step 6); values still in `conf/local.php` are only fallbacks.

Ownership: the code may belong to you; `data/` must be writable by the web server's user, and
`conf/local.php` readable by it (and nobody else — it holds the session secret):

```sh
sudo chgrp -R www-data data && sudo chmod -R g+rwX data && sudo chmod g+s data
sudo chgrp www-data conf/local.php && chmod 640 conf/local.php
```

`data/`, `conf/local.php` and `uploads/` are gitignored and must never be committed (D33).

## 3. Web server

The document root is `public/` **only** (D23) — `data/`, `conf/` and the code stay outside it.
The full lighttpd and PHP-FPM configuration is in `docs/deploy-lighttpd.md`; the points that
break a site silently when missed:

- **Rewrite** every non-file request to `public/index.php` (lighttpd has no `.htaccess`).
- **`public/assets` is a symlink** to `../assets` — don't disable `server.follow-symlink`.
- **`php_admin_value[ffi.enable] = 1`** in the FPM pool — every save needs a real `fsync()`
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

Accounts are created only this way (D35 — no self-service sign-up):

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

It checks PHP, extensions, FTS5, the session secret, that an owner exists, that `data/` is
writable, and — against the public address from step 6 — that `data/` cannot be fetched over
HTTP and that the front controller answers. Those two warn until the address is set, and fail
while the site is not reachable from outside.

## 6. Instance settings

Signed in as the owner, **Admin → Settings**:

- **Site** — name, tagline, **public address** (printed in every export's verification link:
  set it before exporting anything real), home page for visitors, time zone, icon.
- **Publishing and export** — namespaces with a feed, what visitors may export.
- **Limits** — days in the trash, largest pasted image.
- **Reports** — modality → namespace (`MR = mri` files MR reports under `reports:mri:`).
- **Sites and devices** — each site's printed letterhead and its devices. Leave **accession
  code** empty to continue an existing series: imported accessions read `SCUC-MR-23-1764`, so
  new ones continue as `SCUC-MR-26-…`; setting a code (e.g. `MV`) starts a separate series.

**Report templates** are ordinary pages under `templates:{modality-ns}:` (e.g.
`templates:mri:cerebral-nativ`); the new-report form offers them per modality and copies their
text and exam fields — never patient fields.

## 7. Scheduled jobs

As the web server's user (`sudo crontab -u www-data -e`):

```
30 3 * * *  cd /var/www/html/reporion && bin/reporion index:verify --json > /dev/null
15 4 * * *  cd /var/www/html/reporion && bin/reporion trash:purge
```

- `index:verify` only reports drift; the fix is **Admin → Index & storage → Rebuild** (or
  `index:rebuild`). Its `--json` report is kept in Admin → Maintenance either way.
- `trash:purge` removes pages deleted more than the configured days ago; signed reports stay
  unless purged explicitly (D3b).
- Crashed writes are finished automatically on the next request; an older backlog is listed in
  Admin → Maintenance ("Unfinished writes") for someone to look at first.

**Backups** (D22): rsync `data/` over SSH into dated `--link-dest` snapshots on an encrypted
volume — the commands are in `docs/deploy-lighttpd.md`. `data/` is everything: pages, history,
accounts, settings, media, audit. `data/index.sqlite` can be left out (it is rebuilt from disk).

## 8. Going public

The site sits behind a TLS front server that forwards the subfolder to this box. On that front
server (lighttpd, `mod_proxy`):

```
$HTTP["url"] =~ "^/reporion/" {
    proxy.server    = ( "" => (( "host" => "192.168.3.16", "port" => 80 )) )
    proxy.forwarded = ( "for" => 1, "proto" => 1 )
}
```

`proto` makes the session cookie `Secure`. `for` passes the visitor's address along, but
Reporion does not read it yet: the audit log records the front server's address until that is
built. Then re-run `doctor` (step 5): both HTTP checks should pass.

## 9. Existing archive

Importing the DokuWiki reports is a batch process with a review step — `import:scan`, `convert`,
`meta`, `commit`, and `import:rollback` to undo a batch: `docs/architecture-import.md`
(`docs/architecture-import-pages.md` for non-report pages). After an import, run
`index:rebuild` and check Admin → Maintenance.

## 10. Updating

```sh
cd /var/www/html/reporion && git pull && composer install --no-dev
sudo -u www-data bin/reporion doctor
```

Stylesheets and scripts pick up the new version by themselves (versioned URLs). If a release
note says the index changed, run **Admin → Index & storage → Rebuild**.

## Development

`bin/reporion serve` runs PHP's built-in server with the right document root and
`ffi.enable=1`. It sends no caching headers and serves one request at a time, so fonts arrive
late and pages render in the system font there — expected, not a bug. Tests: `composer test`
(`composer test -- tests/Http/` for one suite); the render conformance test needs `npm install`.
