# Deploying on lighttpd + PHP-FPM

Two invariants (D23). Both are asserted by `bin/reporion doctor`:

1. The document root is **`public/` only**. `data/`, `conf/` and `plugins/` live outside it.
2. Every request that is not a real file rewrites to `public/index.php`. lighttpd has no
   `.htaccess`, so this is server config — there is no in-app fallback.

## Layout

    /var/www/html/reporion/                 (any path will do; the examples use this one)
      public/        <-- document root
        assets/      <-- symlink to ../assets, NOT a real directory
      src/ conf/ data/ plugins/ templates/ assets/ bin/ vendor/

> **`public/assets` is a symlink, not a copy.** `assets/` lives at the repo root per this
> project's own layout, outside the document root (D23) — no build step copies it in. This
> means `server.follow-symlink` must not be disabled (lighttpd's own default is to follow
> symlinks, so this only matters if something has hardened it to `"disable"`) — confirm with a
> direct request for a known asset, e.g. `curl -I .../assets/css/tokens.css`. Same class of
> easy-to-miss requirement as `ffi.enable` below — undocumented until this was actually deployed
> and every stylesheet 404'd with no obvious cause.

## lighttpd.conf

    server.modules += ( "mod_rewrite", "mod_fastcgi", "mod_setenv" )

    $HTTP["host"] == "reports.example.ro" {
        server.document-root = "/var/www/html/reporion/public"

        # front controller
        url.rewrite-if-not-file = ( "^/(.*)$" => "/index.php/$1" )

        # never serve dotfiles or leftovers
        url.access-deny = ( "~", ".inc", ".md", ".sqlite", ".json" )

        fastcgi.server = ( ".php" =>
            (( "socket" => "/run/php/php8.1-fpm.sock",   # version-specific: see `ls /run/php/`
               "broken-scriptfilename" => "enable" ))
        )

        setenv.add-response-header = (
            "X-Content-Type-Options" => "nosniff",
            "Referrer-Policy"        => "same-origin",
            "X-Frame-Options"        => "DENY"
        )
    }

    # Static assets: a year when the URL is versioned (?v=…, Support\Asset — every
    # stylesheet and script link), a week otherwise (the fonts the CSS points to).
    # Without this, browsers re-check CSS and fonts on page loads, and over the VPN
    # each re-check delays the fonts (font-display: optional then keeps the system
    # font on that page). /media/ is not here: PHP serves it, with its own private
    # cache headers, because who may see an image depends on who is asking.
    $HTTP["url"] =~ "^/assets/" {
        $HTTP["querystring"] =~ "^v=" {
            setenv.add-response-header = ( "Cache-Control" => "public, max-age=31536000, immutable" )
        } else {
            setenv.add-response-header = ( "Cache-Control" => "public, max-age=604800" )
        }
    }

**Mounted in a subfolder** (this box: `/reporion/`, `/etc/lighttpd/conf-available/50-reporion.conf`),
the same rule with the prefix — and `mod_setenv` loaded:

    server.modules += ( "mod_rewrite", "mod_setenv" )

    alias.url += ( "/reporion/" => "/var/www/html/reporion/public/" )

    $HTTP["url"] =~ "^/reporion/" {
        url.rewrite-if-not-file = ( "^/reporion/(.*)$" => "/reporion/index.php/$1" )
    }

    $HTTP["url"] =~ "^/reporion/assets/" {
        $HTTP["querystring"] =~ "^v=" {
            setenv.add-response-header = ( "Cache-Control" => "public, max-age=31536000, immutable" )
        } else {
            setenv.add-response-header = ( "Cache-Control" => "public, max-age=604800" )
        }
    }

Check with `curl -sI 'https://…/reporion/assets/css/wiki.css?v=1' | grep -i cache-control`.

**Root mode** (D43: the archive unpacked inside an existing web root, so the URL maps to the
repository root rather than `public/`). The root's `index.php` runs `public/index.php`; only
`index.php`, `install.php` and `assets/` may be served, everything else goes to the front
controller (which answers 404):

    server.modules += ( "mod_rewrite" )
    $HTTP["url"] =~ "^/reporion/" {
        url.rewrite-once = (
            "^(/reporion/(?:index\.php|install\.php|assets/).*)$" => "$1",
            "^/reporion/([^?]*)(\?.*)?$" => "/reporion/index.php/$1$2"
        )
    }

`install.php` prints this with the real paths (and the nginx equivalent; Apache reads the shipped
`.htaccess` files), and its browser probe refuses to finish while `data/` or `conf/` is served.
Prefer the `public/` alias above: nothing outside `public/` is ever reachable, with no rule to
keep right.

## PHP-FPM pool

    php_admin_value[memory_limit] = 256M          ; dompdf on a long report
    php_admin_value[max_execution_time] = 300     ; Admin -> Maintenance / Tags pages:summarize/pages:tag/
                                                   ; index:vectors/integrity:verify run through FPM, not
                                                   ; only in CLI, and call set_time_limit(300) themselves
    php_admin_value[upload_max_filesize] = 32M
    php_admin_value[post_max_size] = 32M
    ; optional hardening — if set, it must also list the directories of the plugin binaries
    ; (is_file()/is_executable() on /usr/bin/findscu or /usr/bin/groff fail outside it) and the
    ; system temp path; test a PDF export and each plugin's own test screen after setting it
    ;php_admin_value[open_basedir] = /var/www/html/reporion:/tmp:/usr/bin:/usr/local/bin
    php_admin_flag[expose_php] = off
    php_admin_value[ffi.enable] = 1               ; real fsync() needs FFI — see below

> **`max_execution_time` must be at least as long as the longest browser-triggered task.**
> `AdminMaintenanceController`/`AdminIndexController` call `set_time_limit(300)` before a long
> maintenance run (`pages:summarize`, `pages:tag`, `index:vectors`, `integrity:verify`,
> `index:rebuild`), but a pool directive set with `php_admin_value` overrides what the running
> script asks for — `set_time_limit()` cannot raise it back. Set the pool's own value to 300 s or
> more (not 120, which only covered the *CLI* `index:rebuild`'s cousins before these admin runs
> existed) and raise `request_terminate_timeout` to match, or these runs are killed mid-batch with
> no error beyond a generic 504.

> **`ffi.enable` is not optional.** `Support\Fsync` calls libc's `fsync()` via FFI on every
> atomic write (CLAUDE.md invariant 7) — without it, every page save throws. Confirmed
> empirically: plain CLI script execution (`php script.php`, `php -r`) trusts FFI regardless of
> this setting, but **any HTTP-serving SAPI does not** — that includes PHP-FPM here *and* PHP's
> built-in dev server (`php -S`), which silently 500s every write until you pass
> `-d ffi.enable=1` at startup (`bin/reporion serve` and `composer serve` do this). `php_admin_value` in an FPM pool can set this even though `ffi.enable` is a
> `PHP_INI_SYSTEM` directive — pool config is applied at worker startup, not per-request.
> `bin/reporion doctor` cannot verify this (it runs in the CLI): save a page through the web
> after restarting PHP-FPM. Also check the FPM `php.ini` (`/etc/php/8.1/fpm/`) loads `ffi`, `gd`,
> `iconv` and `intl`, not only the CLI one.

## The AI assistant's streaming (phase 15)

The assistant's answer streams as Server-Sent Events (`POST /api/v1/ai/complete` with
`stream: true`). lighttpd buffers a FastCGI response whole unless told not to, which turns the
stream into one late block; let it through as it comes:

    server.stream-response-body = 2

(global, lighttpd ≥ 1.4.40). Without it the assistant still works — the text just arrives at the
end. A local model can take a minute: keep `ai.timeout` (Admin → AI) below the pool's
`max_execution_time` (a `php_admin_value` cannot be raised at run time) and FPM's
`request_terminate_timeout`, and count one busy worker per user asking — the assistant runs one
request per user at a time — when setting `pm.max_children`.

## The groff plugin

`plugins/groff` (D42) typesets an ordinary Markdown page (never a report) with **groff**. Install the full package, not
`groff-base` (it lacks the ms macros and the PDF device):

    apt-get install groff
    ls /usr/share/groff/current/tmac/s.tmac    # the ms macros
    command -v gropdf                          # the PDF device

The binary is found by the full path in the plugin's `groff` setting (default `/usr/bin/groff`). The
font family must have the glyphs of the pages' language (ă â î ș ț): install a font for groff (see
`man grops`/`gropdf` on adding a font to the `devpdf` device) and name its family in the `font` setting.
groff writes into a private temp directory under the system temp path. Details: `plugins/groff/README.md`.

## The DICOM plugin (dcmtk)

`plugins/dicom` (D39) shells out to **dcmtk** — install it on the server, not just a client:

    apt-get install dcmtk     # or build from source; dcmtk >= 3.6.4 (for findscu's --extract-xml)

Three binaries, found by full path, never by PATH: each tool's own plugin setting (`findscu`,
`echoscu`, `storescu`), else the *dcmtk directory* setting + its name, else next to `findscu`. A
wrapper script works (it must pass the arguments through and keep the tool's exit code and output).
A dcmtk outside its usual prefix may need the `DCMDICTPATH` / `LD_LIBRARY_PATH` settings. The
owner's *Test the PACS* page lists the resolved paths and `--version` of each:

- **`findscu`** — C-FIND against each site's PACS: the worklist and a report's PACS-tab search.
- **`echoscu`** — C-ECHO, the PACS row's *Test* button and `GET /x/dicom/echo`.
- **`storescu`** — C-STORE, sending a signed report's DICOM SR to its PACS (`POST
  /x/dicom/send/{pid}`, phase 22b) when a site's row ticks *send SR*.

No inbound DICOM port is opened — the plugin only ever calls out (D39: never listens, never
C-MOVE/C-GET). PHP needs `proc_open` (not disabled by `disable_functions`) to run them; identifiers
sent as a C-FIND query go into a private 0700 temp file, never a command line or a URL, so `ps`
output and `request.log` stay clean.

## The embedding server (Similar reports)

Phase 34e's *Similar reports* needs one OpenAI-compatible server reachable from this box with a
`POST /embeddings` route — the same server already configured for the assistant works if it serves
an embedding model (e.g. `nomic-embed-text`), or a dedicated slot among the six in Admin → AI.
Configured as `ai.embed_server` + `ai.embed_model` (one model for the whole instance, not one per
server, docs/FORMATS.md §3d). The same egress rule as any AI call applies: a server outside the
private network needs the owner's acknowledgement before de-identified text is sent to it.
`index:rebuild --vectors` / `index:vectors` (Admin → Maintenance) call it in batches — expect
minutes, not seconds, on a large archive (see the `max_execution_time` note above).

## Checks

    sudo -u www-data bin/reporion doctor

Asserts: PHP >= 8.1; required extensions (`pdo_sqlite`, `sqlite3`, `mbstring`, `intl`, `zlib`,
`dom`, `xml`, `iconv`, `gd`, `ffi`), Argon2id and pdo_sqlite FTS5 available; at least one active
**owner** account exists in `data/users/` (D35 superseded the old single
`auth.owner_password_hash` check — create one with `bin/reporion user:create --owner`);
`auth.session_secret` and `site.timezone` set in `conf/local.php` (with an empty session secret no
cookie is issued or accepted, so nobody can sign in); `conf/local.php` not world-readable
(warning); `data/` and `data/audit/` writable by the user running it (a missing `data/` fails —
create it first, INSTALL.md "Create `data/`"); `settings.yaml`/`tags.yaml` parse; **nothing
outside `public/` is served** (`/.git/config` and `/composer.json` fetched from `site.base_url`;
warns when the address is unreachable, fails on a leak); the rewrite reaches the front
controller (`GET /` below 400); and, as a warning, that the most recent `integrity:verify` run is
recent and found everything intact. Exit 1 only on FAIL.

Not asserted: `ffi.enable`/`open_basedir`/extensions **in PHP-FPM**, and the plugin binaries.

## Backup (D22)

    SNAP=$(date +%F)
    rsync -az --delete --link-dest=../latest --exclude='index.sqlite*' \
      -e ssh /var/www/html/reporion/data/ backup@host:/backups/reporion/$SNAP/
    ssh backup@host "ln -sfn $SNAP /backups/reporion/latest"
    # the session secret and paths (once: ssh backup@host mkdir -p /backups/reporion/conf)
    rsync -a -e ssh /var/www/html/reporion/conf/local.php backup@host:/backups/reporion/conf/local.php.$SNAP

Run it as the user that can read `data/` (`www-data`, or root). Dated snapshots with `--link-dest`
cost almost nothing and mean a mistaken purge is recoverable. Keep the backup target on an
encrypted volume. `index.sqlite*` is left out because it is rebuilt from disk
(`bin/reporion index:rebuild`); `integrity:verify --backup=<dir>` checks that every signed
revision exists byte for byte in a snapshot.
