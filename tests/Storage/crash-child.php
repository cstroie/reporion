<?php

// SPDX-License-Identifier: GPL-3.0-or-later
//
// Test-only (tests/Storage/RealCrashTest): one Storage write in a process of
// its own that SIGKILLs itself just before its Nth rename()/link() — the
// steps AtomicWriter makes a write durable with. The functions below shadow
// the global ones for unqualified calls in Reporion\Storage, so the write
// runs the real code, unchanged, up to the kill.
//
// Usage: php crash-child.php <dataRoot> <create|save> <path> <killBefore>

declare(strict_types=1);

namespace Reporion\Storage {
    function crashPoint(): void
    {
        $GLOBALS['crashCalls'] = ($GLOBALS['crashCalls'] ?? 0) + 1;
        if ($GLOBALS['crashCalls'] === $GLOBALS['crashKillBefore']) {
            posix_kill(getmypid(), SIGKILL);
        }
    }

    function rename(string $from, string $to): bool
    {
        crashPoint();

        return \rename($from, $to);
    }

    function link(string $target, string $link): bool
    {
        crashPoint();

        return \link($target, $link);
    }
}

namespace {
    use Reporion\Index\Sqlite;
    use Reporion\Storage\FlatFile;

    require \dirname(__DIR__, 2) . '/vendor/autoload.php';

    [, $dataRoot, $op, $path, $killBefore] = $argv;
    $GLOBALS['crashKillBefore'] = (int) $killBefore;

    $storage = new FlatFile($dataRoot, new Sqlite($dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'));
    if ($op === 'create') {
        $storage->create($path, ['title' => 'Crash', 'visibility' => 'private'], "first body\n", 'owner');
    } else {
        $storage->save($path, ['title' => 'Crash', 'visibility' => 'private'], "second body\n", 1, 'owner');
    }
    // Only reached when killBefore is past the last step
    echo "completed\n";
}
