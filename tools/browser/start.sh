#!/usr/bin/env bash
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Test-only: a throwaway Reporion instance for browser checks.
#   tools/browser/start.sh <fixture dir> [app port 8791] [fake AI port 8792]
# Seeds <fixture dir> (synthetic data), writes a router and a signed owner
# cookie there, and starts `php -S` for the app (with ffi.enable=1, as FPM
# needs) and for the fake OpenAI-compatible server. PIDs go in <dir>/pids.
# Never point it at data/: that is the live install.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DIR="${1:?usage: start.sh <fixture dir> [app port] [ai port]}"
APP_PORT="${2:-8791}"
AI_PORT="${3:-8792}"
case "$(cd "$(dirname "$DIR")" 2>/dev/null && pwd)/$(basename "$DIR")" in
  "$ROOT"/data*) echo "refusing: $DIR is the live data directory" >&2; exit 1 ;;
esac
rm -rf "$DIR"; mkdir -p "$DIR/data"
php "$ROOT/tools/browser/seed.php" "$DIR/data" >/dev/null
cat > "$DIR/router.php" <<PHP
<?php
declare(strict_types=1);
\$root = '$ROOT';
\$uri = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (\$uri !== '/' && is_file(\$root . '/public' . \$uri)) { return false; }
\$_SERVER['SCRIPT_NAME'] = '/index.php';
\$_SERVER['SCRIPT_FILENAME'] = \$root . '/public/index.php';
require \$root . '/vendor/autoload.php';
\$config = [
  'paths' => ['data' => '$DIR/data', 'index' => '$DIR/data/index.sqlite', 'audit' => '$DIR/data/audit', 'media' => '$DIR/data/media'],
  'auth' => ['session_secret' => 'browser-check-secret', 'session_name' => 'reporion', 'session_lifetime' => 3600],
  'site' => ['home_page' => 'site:home', 'base_url' => 'http://127.0.0.1:$APP_PORT'],
  'pages' => ['trash_purge_days' => 30],
  'sites' => ['mioveni' => ['name' => 'Spital Test', 'accession_code' => 'MV']],
  'ai' => ['enabled' => true, 'endpoint' => 'http://127.0.0.1:$AI_PORT/v1', 'model' => 'test-model', 'profiles' => ['reports' => 'reports']],
];
Reporion\Kernel::boot(\$config)->handle(Reporion\Http\Request::fromGlobals())->send();
PHP
php -r 'require $argv[1] . "/vendor/autoload.php"; echo (new Reporion\Http\Session("browser-check-secret","reporion",3600,new Reporion\Auth\FlatFileUserStore($argv[2])))->issue("owner");' "$ROOT" "$DIR/data" > "$DIR/cookie"
cd "$ROOT"
php -d ffi.enable=1 -S "127.0.0.1:$APP_PORT" -t public "$DIR/router.php" > "$DIR/app.log" 2>&1 &
echo $! > "$DIR/pids"
FAKE_AI_LOG="$DIR/ai-last-request.json" php -S "127.0.0.1:$AI_PORT" tests/fixtures/ai/fake-openai.php > "$DIR/ai.log" 2>&1 &
echo $! >> "$DIR/pids"
sleep 1
echo "app: http://127.0.0.1:$APP_PORT  (cookie in $DIR/cookie, valid 1 h)"
