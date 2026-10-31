# Deploying on lighttpd + PHP-FPM

Two invariants (D23). Both are asserted by `bin/reporion doctor`:

1. The document root is **`public/` only**. `data/`, `conf/` and `plugins/` live outside it.
2. Every request that is not a real file rewrites to `public/index.php`. lighttpd has no
   `.htaccess`, so this is server config: there is no in-app fallback.

## Layout

    /var/www/html/reporion/                 (any path will do; the examples use this one)
      public/        <-- document root
        assets/      <-- symlink to ../assets, NOT a real directory
      src/ conf/ data/ plugins/ templates/ assets/ bin/ vendor/

> **`public/assets` is a symlink, not a copy.** `assets/` lives at the repo root per this
> project's own layout, outside the document root (D23): no build step copies it in. This
> means `server.follow-symlink` must not be disabled (lighttpd's own default is to follow
> symlinks, so this only matters if something has hardened it to `"disable"`): confirm with a
> direct request for a known asset, e.g. `curl -I .../assets/css/tokens.css`. Same class of
> easy-to-miss requirement as `ffi.enable` below: undocumented until this was actually deployed
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

    # Static assets: a year when the URL is versioned (?v=…, Support\Asset: every
    # stylesheet and script link), a week otherwise (the fonts the CSS points to).
    # Without this, browsers re-check CSS and fonts on page loads, and over the VPN
    # each re-check delays the fonts (font-display: swap then paints the system
    # font first and re-paints). /media/ is not here: PHP serves it, with its own private
    # cache headers, because who may see an image depends on who is asking.
    $HTTP["url"] =~ "^/assets/" {
        $HTTP["querystring"] =~ "^v=" {
            setenv.add-response-header = ( "Cache-Control" => "public, max-age=31536000, immutable" )
        } else {
            setenv.add-response-header = ( "Cache-Control" => "public, max-age=604800" )
        }
    }

**Mounted in a subfolder** (this box: `/reporion/`, `/etc/lighttpd/conf-available/50-reporion.conf`),
the same rule with the prefix, and `mod_setenv` loaded:

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
`.htaccess` files, and its printed snippet adds `mod_headers`), and its browser probe refuses to finish while `data/` or `conf/` is served.
Prefer the `public/` alias above: nothing outside `public/` is ever reachable, with no rule to
keep right.

**The asset cache on other servers** (the installer prints it for each server, `Install\ServerConfig`):
nginx gets `location ~ ^/reporion/assets/ { add_header Cache-Control "public, max-age=604800"; if ($args ~ "^v=") { add_header Cache-Control "public, max-age=31536000, immutable"; } }`;
Apache gets the same through `mod_headers` (`a2enmod headers`; a `<LocationMatch "^/reporion/assets/">`
with an `<If "%{QUERY_STRING} =~ /^v=/">`, and the shipped root-mode `.htaccess` carries it too).

## PHP-FPM pool

    php_admin_value[memory_limit] = 256M          ; dompdf on a long report
    php_admin_value[max_execution_time] = 300     ; Admin -> Maintenance / Tags pages:summarize/pages:tag/
                                                   ; index:vectors/integrity:verify run through FPM, not
                                                   ; only in CLI, and call set_time_limit(300) themselves
    php_admin_value[upload_max_filesize] = 64M     ; the library takes files up to library.max_mb (50 MB)
    php_admin_value[post_max_size] = 64M           ; as the request body: keep both above it
    ; optional hardening, if set, it must also list the directories of the plugin binaries
    ; (is_file()/is_executable() on /usr/bin/findscu or /usr/bin/groff fail outside it) and the
    ; system temp path; test a PDF export and each plugin's own test screen after setting it
    ;php_admin_value[open_basedir] = /var/www/html/reporion:/tmp:/usr/bin:/usr/local/bin
    php_admin_flag[expose_php] = off
    php_admin_value[ffi.enable] = 1               ; real fsync() needs FFI: see below

lighttpd limits a request body itself: `server.max-request-size` (in KiB) must allow the library's
largest file too, e.g. `server.max-request-size = 65536` for 64 MiB (docs/library.md).

> **`max_execution_time` must be at least as long as the longest browser-triggered task.**
> `AdminMaintenanceController`/`AdminIndexController` call `set_time_limit(300)` before a long
> maintenance run (`pages:summarize`, `pages:tag`, `index:vectors`, `integrity:verify`,
> `index:rebuild`), but a pool directive set with `php_admin_value` overrides what the running
> script asks for:`set_time_limit()` cannot raise it back. Set the pool's own value to 300 s or
> more (not 120, which only covered the *CLI* `index:rebuild`'s cousins before these admin runs
> existed) and raise `request_terminate_timeout` to match, or these runs are killed mid-batch with
> no error beyond a generic 504.

> **`ffi.enable` is not optional.** `Support\Fsync` calls libc's `fsync()` via FFI on every
> atomic write (CLAUDE.md invariant 7): without it, every page save throws. Confirmed
> empirically: plain CLI script execution (`php script.php`, `php -r`) trusts FFI regardless of
> this setting, but **any HTTP-serving SAPI does not**, that includes PHP-FPM here *and* PHP's
> built-in dev server (`php -S`), which silently 500s every write until you pass
> `-d ffi.enable=1` at startup (`bin/reporion serve` and `composer serve` do this). `php_admin_value` in an FPM pool can set this even though `ffi.enable` is a
> `PHP_INI_SYSTEM` directive: pool config is applied at worker startup, not per-request.
> `bin/reporion doctor` cannot verify this (it runs in the CLI): save a page through the web
> after restarting PHP-FPM. Also check the FPM `php.ini` (`/etc/php/8.1/fpm/`) loads `ffi`, `gd`,
> `iconv` and `intl`, not only the CLI one.

## The AI assistant's streaming (phase 15)

The assistant's answer streams as Server-Sent Events (`POST /api/v1/ai/complete` with
`stream: true`). lighttpd buffers a FastCGI response whole unless told not to, which turns the
stream into one late block; let it through as it comes:

    server.stream-response-body = 2

(global, lighttpd ≥ 1.4.40). Without it the assistant still works: the text just arrives at the
end. A local model can take a minute: keep `ai.timeout` (Admin → AI) below the pool's
`max_execution_time` (a `php_admin_value` cannot be raised at run time) and FPM's
`request_terminate_timeout`, and count one busy worker per user asking: the assistant runs one
request per user at a time, when setting `pm.max_children`.

**Library analyses are longer.** An analysis of a library source too long for one request (docs/library.md, phase 5)
is N+1 requests in one HTTP request: one per part of the source (at most 6), then the analysis. Each request is the
assistant's own, with its retries, so the worst case is 7 × (4 × `ai.timeout` + 14 s); a normal run is 7 × the model's
time. `max_execution_time` (the pool's `php_admin_value`) and `request_terminate_timeout` must allow what the owner
accepts: with a 60 s model, 10 minutes covers a normal long article. The page shows "part k of n" as the events come
(`server.stream-response-body = 2`, above), so an idle timeout in front of PHP does not cut it; a source needing more
than 6 parts is refused before any request (raise *Assistant context for the library* in Admin → Settings → Library, `library.context_tokens`, if the model allows).

**Studio is longer still.** A Studio text of a notebook (docs/library.md, phase 6) whose sources do not fit one request
condenses them source by source, then writes; the comparison asks each source on its own. At most 16 requests in one
HTTP request (refused before the first beyond), so the worst case is 16 × (4 × `ai.timeout` + 14 s) and a normal run
16 × the model's time: with a 60 s model, about 16 minutes. Set `max_execution_time` and `request_terminate_timeout` for
what the owner accepts; the page shows "request k of n" as the events come.

**Scans are read by OCR in the upload's request** (docs/library.md, phase 8): each page is rendered and read by tesseract,
at most 45 s a page and 30 pages, so the worst case is about 30 × 55 s ≈ 28 minutes, a normal page 3–10 s. Install
`tesseract-ocr tesseract-ocr-ron tesseract-ocr-eng`; set `max_execution_time` and `request_terminate_timeout` for what the owner
accepts (a long scan is better added with `sudo -u www-data bin/reporion library:add`, which has no web timeout). tesseract
runs single-threaded (`OMP_THREAD_LIMIT=1`), so one OCR takes one core.

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

`plugins/dicom` (D39) shells out to **dcmtk**: install it on the server, not just a client:

    apt-get install dcmtk     # or build from source; dcmtk >= 3.6.4 (for findscu's --extract-xml)

Three binaries, found by full path, never by PATH: each tool's own plugin setting (`findscu`,
`echoscu`, `storescu`), else the *dcmtk directory* setting + its name, else next to `findscu`. A
wrapper script works (it must pass the arguments through and keep the tool's exit code and output).
A dcmtk outside its usual prefix may need the `DCMDICTPATH` / `LD_LIBRARY_PATH` settings. The
owner's *Test the PACS* page lists the resolved paths and `--version` of each:

- **`findscu`**: C-FIND against each site's PACS: the worklist and a report's PACS-tab search.
- **`echoscu`**: C-ECHO, the PACS row's *Test* button and `GET /x/dicom/echo`.
- **`storescu`**: C-STORE, sending a signed report's DICOM SR to its PACS (`POST
  /x/dicom/send/{pid}`, phase 22b) when a site's row ticks *send SR*.

No inbound DICOM port is opened: the plugin only ever calls out (D39: never listens, never
C-MOVE/C-GET). PHP needs `proc_open` (not disabled by `disable_functions`) to run them; identifiers
sent as a C-FIND query go into a private 0700 temp file, never a command line or a URL, so `ps`
output and `request.log` stay clean.

## The embedding server (Similar reports)

Phase 34e's *Similar reports* needs one OpenAI-compatible server reachable from this box with a
`POST /embeddings` route: the same server already configured for the assistant works if it serves
an embedding model (e.g. `nomic-embed-text`), or a dedicated slot among the six in Admin → AI.
Configured as `ai.embed_server` + `ai.embed_model` (one model for the whole instance, not one per
server, docs/FORMATS.md §3d). The same egress rule as any AI call applies: a server outside the
private network needs the owner's acknowledgement before de-identified text is sent to it.
`index:rebuild --vectors` / `index:vectors` (Admin → Maintenance) call it in batches: expect
minutes, not seconds, on a large archive (see the `max_execution_time` note above).

## Checks

    sudo -u www-data bin/reporion doctor

Asserts: PHP >= 8.1; required extensions (`pdo_sqlite`, `sqlite3`, `mbstring`, `intl`, `zlib`,
`dom`, `xml`, `iconv`, `gd`, `ffi`), Argon2id and pdo_sqlite FTS5 available; at least one active
**owner** account exists in `data/users/` (D35 superseded the old single
`auth.owner_password_hash` check: create one with `bin/reporion user:create --owner`);
`auth.session_secret` set in `conf/local.php` (with an empty session secret no
cookie is issued or accepted, so nobody can sign in); `conf/local.php` not world-readable
(warning); `data/` and `data/audit/` writable by the user running it (a missing `data/` fails
create it first, INSTALL.md "Create `data/`"); `settings.yaml`/`tags.yaml` parse; **nothing
outside `public/` is served** (`/.git/config` and `/composer.json` fetched from `site.base_url`;
warns when the address is unreachable, fails on a leak); the rewrite reaches the front
controller (`GET /` below 400); and, as a warning, that the most recent `integrity:verify` run is
recent and found everything intact. Exit 1 only on FAIL.

Not asserted: `ffi.enable`/`open_basedir`/extensions **in PHP-FPM**, and the plugin binaries.

## Backup (D22)

    SNAP=$(date +%F)
    rsync -az --delete --link-dest=../latest --exclude='index.sqlite*' \
      --exclude='vectors.sqlite*' --exclude='/reporion.sqlite*' \
      -e ssh /var/www/html/reporion/data/ backup@host:/backups/reporion/$SNAP/
    ssh backup@host "ln -sfn $SNAP /backups/reporion/latest"
    # the session secret and paths (once: ssh backup@host mkdir -p /backups/reporion/conf)
    rsync -a -e ssh /var/www/html/reporion/conf/local.php backup@host:/backups/reporion/conf/local.php.$SNAP

### Built-in rsync snapshots (D49)

`bin/reporion backup:rsync` does the above itself, and prunes: a dated snapshot per run
(`snapshots/2026-10-17T040000/`), unchanged files hard-linked to the last one, snapshots older than
**a week or a month** removed on the target. Set it up once:

1. On the target, an account with an ordinary shell and a writable directory (an encrypted volume).
2. On this machine, as the web user, a key with no passphrase, and the host's key accepted once:

       sudo -u www-data ssh-keygen -t ed25519 -N '' -f /var/www/.ssh/reporion_backup
       sudo -u www-data ssh-copy-id -i /var/www/.ssh/reporion_backup.pub backup@host

   (`/var/www` is `www-data`'s home on Debian/Ubuntu; check with `getent passwd www-data`.)
3. Admin → Settings → Backup: host, user, directory, the key file's path, how long to keep
   snapshots, how often. Admin → Maintenance → *Rsync backup* → **Check** asks the target without
   storing anything.
4. `sudo -u www-data bin/reporion backup:rsync` once by hand (the first copy is everything), then
   in `sudo crontab -u www-data -e`: `7 * * * *  cd /var/www/html/reporion && bin/reporion backup:rsync --if-due`.
   Tick *Back up on a schedule*. The cron line never changes; the interval and the switch are in
   the settings. `bin/reporion doctor` warns when no snapshot is recent.

**An rsync daemon instead of ssh** (a network you trust: the daemon protocol is not encrypted, so
patient data crosses it as it is). On the backup machine, in `/etc/rsyncd.conf`, with the
daemon running (`systemctl enable --now rsync`):

    [reporion]
        path = /srv/backups/reporion
        read only = no
        uid = backup
        gid = backup
        hosts allow = 192.168.1.10          # this machine only
        auth users = reporion
        secrets file = /etc/rsyncd.secrets  # reporion:thepassword, mode 600

then, here, as the web user, the same password alone in a file of mode 600
(`/var/www/.rsync_backup_pass`) and in Admin → Settings → Backup: *Reach the machine through* the
rsync daemon, host, port 873, module `reporion`, user `reporion`, the password file's path.
The module must be writable and must not list `delete` under `refuse options`. A snapshot is
written under its final name and a `.reporion-complete` file is sent into it last; there is no
`latest` link, so the newest complete snapshot is the one to restore.

Restoring from a snapshot is `rsync -a backup@host:/srv/backups/reporion/snapshots/latest/ data/`
(stopped, as the owner of `data/`; put `snapshot/reporion.sqlite` back as `reporion.sqlite`), then `bin/reporion journal:replay`, `index:rebuild` and `integrity:verify` (`db:recover` if the state database is lost or behind);
`integrity:verify --backup=<a snapshot>` checks it first.

Run it as the user that can read `data/` (`www-data`, or root). Dated snapshots with `--link-dest`
cost almost nothing and mean a mistaken purge is recoverable. Keep the backup target on an
encrypted volume. `index.sqlite*` is left out because it is rebuilt from disk
(`bin/reporion index:rebuild`). `data/reporion.sqlite` (D54, 2.0) is **not** a cache and must be in every copy, but a live WAL database is never copied as files: `backup:rsync` and `backup:create` take a `VACUUM INTO` copy first (`data/snapshot/reporion.sqlite`) and leave the live `reporion.sqlite*` out, so a hand-written `rsync` like the one above must do the same (`bin/reporion backup:create` is the safe form); `integrity:verify --backup=<dir>` checks that every signed
revision exists byte for byte in a snapshot.

## MCP clients (D56)

`POST /mcp` is the MCP server; it takes a bearer token only. Admin → Settings → *MCP clients* turns it off, limits it to the local network (loopback and private addresses, so Claude Code over the VPN still works and claude.ai cannot connect), or opens it everywhere. Behind a reverse proxy PHP sees the proxy's address, not the client's: sign-in, bad-token and OAuth limits would then count all clients as one, and *local network only* could not tell them apart. List the proxy in `data/settings.yaml` (`site: {trusted_proxies: [10.0.0.5, 192.168.0.0/16]}`, addresses or CIDR ranges, IPv4 or IPv6) and the proxy must send `X-Forwarded-For`: it is then read from the right, skipping the listed proxies, and only when the connection itself comes from one; a header from any other connection is ignored. With no list (the default, lighttpd exposed directly) the connection's address is used as it is, and a proxy that does not send `X-Forwarded-For` makes every request look local. More than 20 invalid bearer tokens from one address in 15 minutes get 429 `too_many_attempts`; each is audited `auth.token_failed`. The default is *Local network only*. For claude.ai prefer *Everywhere, token only*: make a read token on `/profile` (namespaces, expiry) and put `Authorization: Bearer rpn_…` in the custom connector's request headers; nothing of OAuth is served then. *Everywhere, with OAuth sign-in* is for a client that cannot send headers.

The token always travels as `Authorization: Bearer rpn_…`. To refuse scanners before PHP runs, give
the web server a gate of its own: Admin → Settings → *MCP clients* → *X-Access-Token
header value* (any 16 to 128 letters, digits, `-` or `_`; the box suggests a new random one at every
page load). Once saved, the page shows the rule for lighttpd, nginx and Apache. For lighttpd
(`mod_access`, 1.4.46 or newer for `$REQUEST_HEADER`):

    $HTTP["url"] == "/reporion/mcp" {
      $REQUEST_HEADER["X-Access-Token"] != "the-value-you-chose" {
        url.access-deny = ( "" )
      }
    }

Add `X-Access-Token: the-value-you-chose` beside the bearer token in the connector's request headers
(`--header "X-Access-Token: …"` for Claude Code). **Reporion never checks this header**: it keeps the
value only to show the rule, so the gate is exactly as strong as the server rule you add, and a
server without the rule is open to a request with or without the header (the token still decides who
gets in). OAuth clients (claude.ai's sign-in connector) cannot send a custom header, so leave the
gate empty and the rule out when using OAuth. Sign-in, OAuth registration and token requests are
limited per client address (5 failed sign-ins in 15 minutes; see `site.trusted_proxies` above for a
reverse proxy). **Claude Code** over the VPN needs
nothing more: make a token on `/profile` (Namespaces to limit it) and
`claude mcp add --transport http reporion https://<host>/mcp --header "Authorization: Bearer rpn_…"`.

**claude.ai and Claude Desktop** connect from Anthropic's servers, so `/mcp`, `/oauth/*`,
`/.well-known/*`, `/login` and `/assets/` must be reachable from the internet (expose only these at
a TLS proxy if the rest stays on a VPN; the proxy sends `X-Forwarded-Proto: https`).
`site.base_url` (Admin → Settings) must be the public `https://` address, with the subfolder if
there is one (`https://example.org/reporion`, no trailing slash): it is the OAuth issuer, and with
no base URL no OAuth is offered. A 401 from `/mcp` names its metadata
(`{base}/.well-known/oauth-protected-resource`), and the front controller answers every
`{base}/.well-known/oauth-*` document.

**In a subfolder**, clients also look the documents up at the host's root with the path appended
(RFC 8414, RFC 9728), which the subfolder's rewrite does not reach. Rewrite those two into it
(the example for `/reporion`; if another file already sets `url.rewrite-once` under the same
condition, use `+=`):

    $HTTP["url"] =~ "^/\.well-known/oauth-" {
      url.rewrite-once = (
        "^/\.well-known/oauth-authorization-server/reporion/?$" => "/reporion/index.php/.well-known/oauth-authorization-server",
        "^/\.well-known/oauth-protected-resource/reporion/mcp/?$" => "/reporion/index.php/.well-known/oauth-protected-resource"
      )
    }

Check: `curl https://<host>/.well-known/oauth-authorization-server/reporion` and
`…/oauth-protected-resource/reporion/mcp` answer JSON naming `https://<host>/reporion`, and
`curl -X POST https://<host>/reporion/mcp` is a 401 with `resource_metadata="…"`.

In the client, add a custom connector with the URL `{base}/mcp`. Each connection made is an API
token named `Claude: …` on the account's `/profile`, read only; revoking it disconnects the
client. Reports reach the client only with Admin → Settings → Publishing *Let Claude … read
reports, de-identified* on.
