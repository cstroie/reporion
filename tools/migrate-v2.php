<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/*
 * The one-off conversion of a Reporion 1.x data directory to the 2.0 layout
 * (D54, docs/architecture-v2.md §11): copy and verify, --from is only read.
 *
 *   php tools/migrate-v2.php --from=data --to=data.v2 [--dry-run] [--json]
 *
 * Run it as the owner of data/ (sudo -u www-data) with the instance stopped,
 * on a copy first. Then, by hand: data -> data.v1, data.v2 -> data,
 * bin/reporion index:rebuild, bin/reporion integrity:verify. Rolling back is
 * renaming back. Counts only on stdout; a failed page is named by pid
 * (invariant 8). Exit 0 when every page converted, 1 when one did not,
 * 2 when the run could not start.
 */

use Reporion\Schema\Loader;
use Reporion\Storage\V1Converter;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['from:', 'to:', 'dry-run', 'json', 'help']);
if ($options === false || isset($options['help']) || !is_string($options['from'] ?? null) || !is_string($options['to'] ?? null)) {
    fwrite(STDERR, "usage: php tools/migrate-v2.php --from=<1.x data dir> --to=<new dir> [--dry-run] [--json]\n");
    fwrite(STDERR, "  converts a 1.x data directory to the 2.0 layout (D54) into a new directory; --from is only read\n");
    exit(isset($options['help']) ? 0 : 2);
}
$from = rtrim($options['from'], '/');
$to = rtrim($options['to'], '/');
$dryRun = isset($options['dry-run']);
$json = isset($options['json']);

if (!$json) {
    echo ($dryRun ? 'checking' : 'converting') . ' a 1.x data directory to the 2.0 layout' . ($dryRun ? ' (dry run: nothing is written)' : '') . " …\n";
}
try {
    $result = (new V1Converter($from, $to, new Loader(dirname(__DIR__) . '/resources/schema')))->run($dryRun);
} catch (RuntimeException $e) {
    $json ? print(json_encode(['ok' => false, 'error' => $e->getMessage()]) . "\n") : fwrite(STDERR, 'refused: ' . $e->getMessage() . "\n");
    exit(2);
}

$ok = $result['failures'] === [];
if ($json) {
    echo json_encode(['ok' => $ok, 'dry_run' => $dryRun] + $result, JSON_UNESCAPED_SLASHES) . "\n";
} else {
    foreach ($result['counts'] as $key => $n) {
        printf("  %-24s %d\n", str_replace('_', ' ', $key), $n);
    }
    foreach ($result['failures'] as $failure) {
        printf("  FAILED %s: %s\n", $failure['pid'] ?? $failure['path_hash'], $failure['problem']);
    }
    echo $ok
        ? ($dryRun ? "every page would convert\n" : "done: now move data aside, put the new directory in its place, then bin/reporion index:rebuild and integrity:verify\n")
        : count($result['failures']) . " page(s) did not convert" . ($dryRun ? '' : '; the new directory is left for a look, the old one untouched') . "\n";
}
exit($ok ? 0 : 1);
