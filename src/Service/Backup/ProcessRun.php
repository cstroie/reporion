<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Backup;

/**
 * Runs a program as an argument list (no shell), for the rsync backup (D49).
 * Its stderr is discarded: rsync and ssh name files there, and a page
 * directory is `{yymmdd}-{name}` (invariant 8). Stdout is returned, capped.
 */
final class ProcessRun
{
    private const MAX_OUTPUT = 262144;

    /**
     * @param list<string>          $argv
     * @param array<string, string> $env  added to (or replacing) the inherited environment
     *
     * @return array{0: int, 1: string} exit status (-1: did not start, -2: killed at the timeout) and stdout
     */
    public static function run(array $argv, int $timeout, array $env = []): array
    {
        $env = array_merge(getenv() ?: [], ['LC_ALL' => 'C'], $env);
        $process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        if (!\is_resource($process)) {
            return [-1, ''];
        }
        stream_set_blocking($pipes[1], false);
        $out = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $chunk = fread($pipes[1], 8192);
            if (\is_string($chunk) && $chunk !== '' && \strlen($out) < self::MAX_OUTPUT) {
                $out .= $chunk;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $exit = proc_close($process);

                // proc_close() answers -1 once proc_get_status() has already reaped the child
                return [$status['exitcode'] >= 0 ? $status['exitcode'] : $exit, substr($out, 0, self::MAX_OUTPUT)];
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                proc_close($process);

                return [-2, ''];
            }
            if ($chunk === '' || $chunk === false) {
                usleep(20000);
            }
        }
    }
}
