# Deploying on lighttpd + PHP-FPM

Two invariants (D23). Both are asserted by `bin/reporion doctor`:

1. The document root is **`public/` only**. `data/`, `conf/` and `plugins/` live outside it.
2. Every request that is not a real file rewrites to `public/index.php`. lighttpd has no
   `.htaccess`, so this is server config — there is no in-app fallback.

## Layout

    /srv/reporion/
      public/        <-- document root
      src/ conf/ data/ plugins/ templates/ assets/ bin/ vendor/

## lighttpd.conf

    server.modules += ( "mod_rewrite", "mod_fastcgi", "mod_setenv" )

    $HTTP["host"] == "reports.example.ro" {
        server.document-root = "/srv/reporion/public"

        # front controller
        url.rewrite-if-not-file = ( "^/(.*)$" => "/index.php/$1" )

        # never serve dotfiles or leftovers
        url.access-deny = ( "~", ".inc", ".md", ".sqlite", ".json" )

        fastcgi.server = ( ".php" =>
            (( "socket" => "/run/php/php8.1-fpm.sock",
               "broken-scriptfilename" => "enable" ))
        )

        setenv.add-response-header = (
            "X-Content-Type-Options" => "nosniff",
            "Referrer-Policy"        => "same-origin",
            "X-Frame-Options"        => "DENY"
        )
    }

    # long cache for content-addressed media only
    $HTTP["url"] =~ "^/media/" {
        setenv.add-response-header = ( "Cache-Control" => "public, max-age=31536000, immutable" )
    }

## PHP-FPM pool

    php_admin_value[memory_limit] = 256M          ; dompdf on a long report
    php_admin_value[max_execution_time] = 120     ; index:rebuild runs in CLI, not here
    php_admin_value[upload_max_filesize] = 32M
    php_admin_value[post_max_size] = 32M
    php_admin_value[open_basedir] = /srv/reporion:/tmp
    php_admin_flag[expose_php] = off
    php_admin_value[ffi.enable] = 1               ; real fsync() needs FFI — see below

> **`ffi.enable` is not optional.** `Support\Fsync` calls libc's `fsync()` via FFI on every
> atomic write (CLAUDE.md invariant 7) — without it, every page save throws. Confirmed
> empirically: plain CLI script execution (`php script.php`, `php -r`) trusts FFI regardless of
> this setting, but **any HTTP-serving SAPI does not** — that includes PHP-FPM here *and* PHP's
> built-in dev server (`php -S`), which silently 500s every write until you pass
> `-d ffi.enable=1` at startup (`bin/reporion serve` does this once it exists; until then, pass
> it by hand). `php_admin_value` in an FPM pool can set this even though `ffi.enable` is a
> `PHP_INI_SYSTEM` directive — pool config is applied at worker startup, not per-request.

## Checks

    bin/reporion doctor

Asserts: PHP >= 8.1; pdo_sqlite + FTS5 available; `data/` NOT fetchable over HTTP;
rewrite reaches the front controller; `data/` writable by the FPM user; timezone set;
`conf/local.php` present with an owner password hash.

## Backup (D22)

    rsync -az --delete --link-dest=../latest \\
      -e ssh /srv/reporion/data/ backup@host:/backups/reporion/$(date +%F)/
    ln -sfn $(date +%F) /backups/reporion/latest

Dated snapshots with `--link-dest` cost almost nothing and mean a mistaken purge is
recoverable. Keep the backup target on an encrypted volume.
