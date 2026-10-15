<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Install;

/**
 * The web server configuration the installer cannot write itself (D43):
 * it runs as the web user, and server config belongs to root. Two shapes:
 *
 *  - "public": the URL maps to public/ (D23, docs/deploy-lighttpd.md)
 *    nothing else of the directory is ever reachable. The recommended one.
 *  - "root": the archive was unpacked inside an existing web root, so the
 *    URL maps to the directory itself. Only index.php, install.php and
 *    assets/ may then be served; everything else goes to the front
 *    controller, which answers 404 for it.
 *
 * Every shape also tells the server to cache /assets/ (a year for a versioned
 * ?v=… URL, a week otherwise: the fonts the CSS points to): without it the
 * browser re-checks stylesheets and fonts on page loads, and a font that
 * arrives late is painted late (docs/deploy-lighttpd.md).
 *
 * $base is the URL path ('' or '/reporion'), $root the directory on disk.
 */
final class ServerConfig
{
    /** Directories and files at the top of the tree no request may reach in root mode */
    public const PRIVATE_TOP = ['data', 'conf', 'src', 'vendor', 'plugins', 'templates', 'migrations', 'resources', 'lang', 'bin', 'tests', 'tools', 'design', 'docs', 'node_modules', 'public'];

    public static function lighttpd(string $root, string $base, bool $rootMode): string
    {
        return self::lighttpdRouting($root, $base, $rootMode) . "\n\n" . self::lighttpdCache($base);
    }

    /**
     * Only the /assets/ caching for one server (the installer's "next steps"):
     * the part of the full configuration that is not needed to be secure or to route
     *
     * @param 'lighttpd'|'nginx'|'apache' $server
     */
    public static function assetCache(string $server, string $base): string
    {
        return match ($server) {
            'lighttpd' => self::lighttpdCache($base),
            'nginx' => self::nginxCache($base),
            default => self::apacheCache($base),
        };
    }

    /** lighttpd's Cache-Control for /assets/ (mod_setenv) */
    private static function lighttpdCache(string $base): string
    {
        $q = preg_quote($base);

        return <<<CONF
            server.modules += ( "mod_setenv" )
            \$HTTP["url"] =~ "^{$q}/assets/" {
                \$HTTP["querystring"] =~ "^v=" {
                    setenv.add-response-header = ( "Cache-Control" => "public, max-age=31536000, immutable" )
                } else {
                    setenv.add-response-header = ( "Cache-Control" => "public, max-age=604800" )
                }
            }
            CONF;
    }

    private static function lighttpdRouting(string $root, string $base, bool $rootMode): string
    {
        $q = preg_quote($base);
        if (!$rootMode) {
            if ($base === '') {
                return <<<CONF
                    server.modules += ( "mod_rewrite" )
                    server.document-root = "{$root}/public"
                    url.rewrite-if-not-file = ( "^/(.*)$" => "/index.php/$1" )
                    CONF;
            }

            return <<<CONF
                server.modules += ( "mod_alias", "mod_rewrite" )
                alias.url += ( "{$base}/" => "{$root}/public/" )
                \$HTTP["url"] =~ "^{$q}/" {
                    url.rewrite-if-not-file = ( "^{$q}/(.*)$" => "{$base}/index.php/$1" )
                }
                CONF;
        }

        return <<<CONF
            server.modules += ( "mod_rewrite" )
            # {$root} is served as {$base}/: only the front controller, the
            # installer and assets/ are passed through; everything else
            # (data/, conf/, composer.json, .git/ …) goes to index.php, which answers 404
            \$HTTP["url"] =~ "^{$q}/" {
                url.rewrite-once = (
                    "^({$q}/(?:index\\.php|install\\.php|assets/).*)$" => "$1",
                    "^{$q}/([^?]*)(\\?.*)?$" => "{$base}/index.php/$1$2"
                )
            }
            CONF;
    }

    public static function nginx(string $root, string $base, bool $rootMode, string $fpmSocket): string
    {
        return self::nginxRouting($root, $base, $rootMode, $fpmSocket) . "\n" . self::nginxCache($base);
    }

    /** nginx's Cache-Control for /assets/: the versioned URL's header replaces the week's */
    private static function nginxCache(string $base): string
    {
        return <<<CONF
            location ~ ^{$base}/assets/ {
                add_header Cache-Control "public, max-age=604800";
                if (\$args ~ "^v=") { add_header Cache-Control "public, max-age=31536000, immutable"; }
            }
            CONF;
    }

    private static function nginxRouting(string $root, string $base, bool $rootMode, string $fpmSocket): string
    {
        $fastcgi = <<<CONF
                fastcgi_split_path_info ^(.+?\\.php)(/.*)$;
                include fastcgi_params;
                fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
                fastcgi_param PATH_INFO \$fastcgi_path_info;
                fastcgi_pass unix:{$fpmSocket};
            CONF;
        if (!$rootMode && $base === '') {
            return <<<CONF
                root {$root}/public;
                location / { try_files \$uri /index.php\$uri\$is_args\$args; }
                location ~ ^/(index|install)\\.php(/|$) {
                {$fastcgi}
                }
                CONF;
        }
        $private = implode('|', array_merge(self::PRIVATE_TOP, ['\\.']));

        return <<<CONF
            # {$root} served as {$base}/
            location ~ ^{$base}/({$private}) { return 404; }
            location ~ ^{$base}/[^/]+\\.(json|lock|md|neon|xml|dist|example|yaml|log)$ { return 404; }
            location {$base}/ { try_files \$uri {$base}/index.php\$uri\$is_args\$args; }
            location ~ ^{$base}/(index|install)\\.php(/|$) {
            {$fastcgi}
            }
            CONF;
    }

    /** Apache reads the shipped .htaccess files; it only has to be allowed to */
    public static function apache(string $root, string $base, bool $rootMode): string
    {
        $dir = $rootMode ? $root : $root . '/public';
        $alias = !$rootMode && $base !== '' ? "Alias {$base} {$dir}\n" : '';

        return <<<CONF
            {$alias}<Directory {$dir}>
                AllowOverride All
                Require all granted
            </Directory>
            CONF . "\n" . self::apacheCache($base) . "\n# a2enmod rewrite headers && systemctl reload apache2";
    }

    /** Apache's Cache-Control for /assets/ (mod_headers) */
    private static function apacheCache(string $base): string
    {
        return <<<CONF
            <IfModule mod_headers.c>
                <LocationMatch "^{$base}/assets/">
                    Header set Cache-Control "public, max-age=604800"
                    <If "%{QUERY_STRING} =~ /^v=/">
                        Header set Cache-Control "public, max-age=31536000, immutable"
                    </If>
                </LocationMatch>
            </IfModule>
            CONF;
    }

    /**
     * The URL path the installer runs under: the directory of SCRIPT_NAME,
     * the same rule as Http\Request::basePathFromGlobals()
     */
    public static function basePath(string $scriptName): string
    {
        $base = rtrim(str_replace('\\', '/', \dirname($scriptName)), '/');

        return $base === '.' ? '' : $base;
    }
}
