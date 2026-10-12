<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Groff;

use Closure;

/**
 * `groff -t -K utf-8 -f<FONT> -ms -T pdf IN.ms > OUT.pdf`, run as a
 * program with an argument list — no shell. The source and the PDF live in
 * a private 0700 directory removed afterwards; stdout goes straight to the
 * output file (a PDF is too big for the bounded pipe other tools use), stderr
 * is kept, bounded, for the owner. groff's safer mode (its default) stays
 * on: never `-U`. `-t` runs `tbl`, which the patient block and the report's
 * tables need.
 */
final class Groff
{
    private const STDERR_MAX = 65536;

    /** @var Closure(list<string> $argv, string $outFile, int $timeout): array{0: int, 1: string} exit code (-1 not runnable, -2 killed), stderr */
    private readonly Closure $runner;

    /** @param ?Closure(list<string>, string, int): array{0: int, 1: string} $runner for tests */
    public function __construct(
        private readonly string $binary,
        private readonly string $font,
        private readonly int $timeout,
        ?Closure $runner = null,
    ) {
        $this->runner = $runner ?? self::run(...);
    }

    /**
     * @throws GroffException
     */
    public function pdf(string $ms): string
    {
        $dir = sys_get_temp_dir() . '/reporion-groff-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) {
            throw new GroffException('failed');
        }
        $in = $dir . '/report.ms';
        $out = $dir . '/report.pdf';
        try {
            if (file_put_contents($in, $ms) === false) {
                throw new GroffException('failed');
            }
            chmod($in, 0600);
            [$exit, $log] = ($this->runner)([$this->binary, '-t', '-K', 'utf-8', '-f' . $this->font, '-ms', '-T', 'pdf', $in], $out, $this->timeout);
            $log = trim($log);
            if ($exit === -1) {
                throw new GroffException('no-tool', 'cannot run ' . $this->binary);
            }
            if ($exit === -2) {
                throw new GroffException('timeout', $log . ($log !== '' ? "\n" : '') . 'killed after ' . $this->timeout . ' s');
            }
            if ($exit !== 0) {
                throw new GroffException('failed', $log);
            }
            $pdf = is_file($out) ? (string) file_get_contents($out) : '';
            if (!str_starts_with($pdf, '%PDF')) {
                throw new GroffException('empty', $log);
            }

            return $pdf;
        } finally {
            @unlink($in);
            @unlink($out);
            @rmdir($dir);
        }
    }

    /**
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string}
     */
    private static function run(array $argv, string $outFile, int $timeout): array
    {
        if (!is_file($argv[0]) || !is_executable($argv[0])) {
            return [-1, ''];
        }
        $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            return [-1, ''];
        }
        stream_set_blocking($pipes[2], false);
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $chunk = fread($pipes[2], 8192);
            if (\is_string($chunk) && \strlen($stderr) < self::STDERR_MAX) {
                $stderr .= $chunk;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stderr .= (string) stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                proc_close($process);

                return [(int) $status['exitcode'], substr($stderr, 0, self::STDERR_MAX)];
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[2]);
                proc_close($process);

                return [-2, substr($stderr, 0, self::STDERR_MAX)];
            }
            usleep(20000);
        }
    }
}
