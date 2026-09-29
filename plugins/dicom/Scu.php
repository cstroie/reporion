<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Dicom;

use Closure;
use DOMDocument;
use DOMElement;
use FilesystemIterator;

/**
 * A DICOM query client over dcmtk: `findscu` for C-FIND (study level, Study
 * Root) and `echoscu` for C-ECHO. We only ever call out — nothing listens —
 * and never retrieve images.
 *
 * findscu writes each answer as an XML file (`--extract-xml`, dcmtk ≥ 3.6.4)
 * in a private temporary directory; the file declares its character set
 * (from SpecificCharacterSet), so DOM hands back UTF-8 either way. The
 * directory is removed after every query.
 *
 * The tools run through proc_open() with an argument list — no shell. No
 * argument, answer or tool output ever reaches a log or an exception
 * message (invariant 8): failures are DicomException codes only.
 *
 * The runner is injectable for tests: fn (list<string> $argv, int $timeout):
 * array{0: int, 1: string} — exit code and stderr.
 */
final class Scu
{
    /** The attributes asked back for every study */
    public const RETURN_KEYS = [
        'PatientName', 'PatientID', 'PatientBirthDate', 'PatientSex',
        'StudyInstanceUID', 'StudyDate', 'StudyTime', 'AccessionNumber',
        'StudyDescription', 'ReferringPhysicianName', 'ModalitiesInStudy',
    ];

    private readonly Closure $runner;

    public function __construct(
        private readonly string $findscu,
        private readonly string $callingAet,
        private readonly int $timeout,
        ?Closure $runner = null,
    ) {
        $this->runner = $runner ?? self::run(...);
    }

    /**
     * A study-level C-FIND: $match are the matching keys (StudyDate,
     * ModalitiesInStudy, StudyInstanceUID …), RETURN_KEYS come back.
     *
     * @param array{host: string, port: int, aet: string} $server
     * @param array<string, string>                        $match
     *
     * @return list<array<string, string>> one row per study, keyword → value
     *
     * @throws DicomException
     */
    public function findStudies(array $server, array $match): array
    {
        $dir = sys_get_temp_dir() . '/reporion-dicom-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) {
            throw new DicomException('failed');
        }
        try {
            $argv = [
                $this->findscu, '-S', '-aet', $this->callingAet, '-aec', $server['aet'],
                '-to', (string) $this->timeout, '-ta', (string) $this->timeout, '-td', (string) $this->timeout,
                '-Xx', '-od', $dir,
                '-k', 'QueryRetrieveLevel=STUDY',
            ];
            foreach (self::RETURN_KEYS as $key) {
                $argv[] = '-k';
                $argv[] = isset($match[$key]) ? $key . '=' . $match[$key] : $key;
            }
            $argv[] = $server['host'];
            $argv[] = (string) $server['port'];
            [$exit, $stderr] = ($this->runner)($argv, $this->timeout * 3 + 5);
            if ($exit !== 0) {
                throw new DicomException(self::reason($exit, $stderr));
            }
            $rows = [];
            $files = glob($dir . '/rsp*.xml') ?: [];
            sort($files);
            foreach ($files as $file) {
                $row = self::parse((string) file_get_contents($file));
                if ($row !== []) {
                    $rows[] = $row;
                }
            }

            return $rows;
        } finally {
            foreach (new FilesystemIterator($dir) as $file) {
                @unlink($file->getPathname());
            }
            @rmdir($dir);
        }
    }

    /**
     * C-ECHO: true when the PACS accepts us.
     *
     * @param array{host: string, port: int, aet: string} $server
     *
     * @throws DicomException
     */
    public function echo(array $server): void
    {
        $echoscu = \dirname($this->findscu) . '/echoscu';
        [$exit, $stderr] = ($this->runner)([
            $echoscu, '-aet', $this->callingAet, '-aec', $server['aet'],
            '-to', (string) $this->timeout, '-ta', (string) $this->timeout, '-td', (string) $this->timeout,
            $server['host'], (string) $server['port'],
        ], $this->timeout * 3 + 5);
        if ($exit !== 0) {
            throw new DicomException(self::reason($exit, $stderr));
        }
    }

    /**
     * One answer file (dcmtk's native XML) → keyword → value. Top-level
     * elements only; a multi-valued one keeps DICOM's backslash.
     *
     * @return array<string, string>
     */
    public static function parse(string $xml): array
    {
        $doc = new DOMDocument();
        if ($xml === '' || !@$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            return [];
        }
        $set = $doc->getElementsByTagName('data-set')->item(0) ?? $doc->documentElement;
        $row = [];
        foreach ($set?->childNodes ?? [] as $node) {
            if ($node instanceof DOMElement && $node->nodeName === 'element') {
                $name = $node->getAttribute('name');
                if ($name !== '') {
                    $row[$name] = trim($node->textContent);
                }
            }
        }

        return $row;
    }

    /** What went wrong, from findscu/echoscu's exit code and stderr — a fixed code, never their text */
    private static function reason(int $exit, string $stderr): string
    {
        return match (true) {
            $exit === 127 || $exit === -1 => 'no-tool',
            $exit === -2 => 'timeout',
            str_contains($stderr, 'Rejected') || str_contains($stderr, 'Not Recognized') => 'rejected',
            str_contains($stderr, 'TCP Initialization Error') || str_contains($stderr, 'Connection refused') || str_contains($stderr, 'No route') => 'unreachable',
            str_contains($stderr, 'Timeout') || str_contains($stderr, 'timed out') => 'timeout',
            default => 'failed',
        };
    }

    /**
     * proc_open() with an argument list (no shell), stdout discarded, stderr
     * kept for reason(); killed after $timeout seconds.
     *
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string} exit code (-1 not runnable, -2 killed), stderr
     */
    private static function run(array $argv, int $timeout): array
    {
        if (!is_file($argv[0]) || !is_executable($argv[0])) {
            return [-1, ''];
        }
        $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            return [-1, ''];
        }
        stream_set_blocking($pipes[2], false);
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $chunk = fread($pipes[2], 8192);
            if (\is_string($chunk) && \strlen($stderr) < 65536) {
                $stderr .= $chunk;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stderr .= (string) stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                proc_close($process);

                return [(int) $status['exitcode'], $stderr];
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[2]);
                proc_close($process);

                return [-2, $stderr];
            }
            usleep(20000);
        }
    }
}
