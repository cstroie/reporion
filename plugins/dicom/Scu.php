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
 * and never retrieve images. Each server carries the calling AE title we
 * present to it (`calling`): each site's PACS identifies us by its own.
 *
 * findscu writes each answer as an XML file (`--extract-xml`, dcmtk ≥ 3.6.4)
 * in a private temporary directory; the file declares its character set
 * (from SpecificCharacterSet), so DOM hands back UTF-8 either way. The
 * directory is removed after every query.
 *
 * A patient's name or ID (`$private` keys of findStudies) never goes on the
 * command line, where `ps` would show it: it travels in a query file, a raw
 * DICOM dataset in the same private directory, handed to findscu as its
 * query-file argument and removed with the directory. The file is the only
 * place a patient identifier leaves this process besides the PACS itself.
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
        'InstitutionName', 'StationName', 'Manufacturer', 'ManufacturerModelName',
    ];

    private readonly Closure $runner;

    public function __construct(
        private readonly string $findscu,
        private readonly int $timeout,
        ?Closure $runner = null,
    ) {
        $this->runner = $runner ?? self::run(...);
    }

    /** The keys that may travel in the query file: keyword → [tag group, tag element, VR] */
    private const PRIVATE_KEYS = ['PatientName' => [0x0010, 0x0010, 'PN'], 'PatientID' => [0x0010, 0x0020, 'LO']];

    /**
     * A study-level C-FIND: $match are the matching keys (StudyDate,
     * ModalitiesInStudy, StudyInstanceUID …), RETURN_KEYS come back.
     * $private are patient keys (PatientName, PatientID; wildcards allowed):
     * they go in a query file, never on the command line.
     *
     * @param array{host: string, port: int, aet: string, calling: string} $server
     * @param array<string, string>                                        $match
     * @param array<string, string>                                        $private
     *
     * @return list<array<string, string>> one row per study, keyword → value
     *
     * @throws DicomException
     */
    public function findStudies(array $server, array $match, array $private = []): array
    {
        $dir = sys_get_temp_dir() . '/reporion-dicom-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) {
            throw new DicomException('failed');
        }
        try {
            $argv = [
                $this->findscu, '-S', '-aet', $server['calling'], '-aec', $server['aet'],
                '-to', (string) $this->timeout, '-ta', (string) $this->timeout, '-td', (string) $this->timeout,
                '-Xx', '-od', $dir,
                '-k', 'QueryRetrieveLevel=STUDY',
            ];
            foreach (self::RETURN_KEYS as $key) {
                if (isset($private[$key])) {
                    continue; // -k would override the value in the query file
                }
                $argv[] = '-k';
                $argv[] = isset($match[$key]) ? $key . '=' . $match[$key] : $key;
            }
            $argv[] = $server['host'];
            $argv[] = (string) $server['port'];
            if ($private !== []) {
                $query = $dir . '/query.dcm';
                if (file_put_contents($query, self::dataset($private)) === false) {
                    throw new DicomException('failed');
                }
                chmod($query, 0600);
                $argv[] = $query;
            }
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
     * A raw DICOM dataset (explicit VR, little endian, no file meta header —
     * findscu detects it) holding the patient keys, values as plain ASCII.
     *
     * @param array<string, string> $keys
     */
    public static function dataset(array $keys): string
    {
        $tags = [];
        foreach ($keys as $keyword => $value) {
            if (!isset(self::PRIVATE_KEYS[$keyword])) {
                throw new DicomException('failed');
            }
            [$group, $element, $vr] = self::PRIVATE_KEYS[$keyword];
            $value = preg_replace('/[^A-Za-z0-9._*?^ -]/', '', $value) ?? '';
            if ($value === '') {
                continue;
            }
            $value = substr($value, 0, 64);
            if (\strlen($value) % 2 === 1) {
                $value .= ' ';
            }
            $tags[($group << 16) | $element] = pack('vv', $group, $element) . $vr . pack('v', \strlen($value)) . $value;
        }
        ksort($tags);

        return implode('', $tags);
    }

    /**
     * C-ECHO: returns when the PACS accepts us; on failure the exception
     * carries echoscu's verbose output (`-v`, stdout and stderr) as its log
     * — an echo carries no patient data, only AE titles, host and port.
     *
     * @param array{host: string, port: int, aet: string, calling: string} $server
     *
     * @throws DicomException
     */
    public function echo(array $server): void
    {
        $echoscu = \dirname($this->findscu) . '/echoscu';
        [$exit, $output] = ($this->runner)([
            $echoscu, '-v', '-aet', $server['calling'], '-aec', $server['aet'],
            '-to', (string) $this->timeout, '-ta', (string) $this->timeout, '-td', (string) $this->timeout,
            $server['host'], (string) $server['port'],
        ], $this->timeout * 3 + 5, true);
        if ($exit !== 0) {
            $log = trim($output);
            if ($exit === -1) {
                $log = 'cannot run ' . $echoscu;
            } elseif ($exit === -2) {
                $log .= ($log !== '' ? "\n" : '') . 'killed after ' . ($this->timeout * 3 + 5) . ' s';
            }
            throw new DicomException(self::reason($exit, $output), $log);
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
     * proc_open() with an argument list (no shell), stderr kept for reason()
     * — and stdout with it when $withStdout (echoscu's log; never for
     * findscu, whose stdout could carry answers); killed after $timeout seconds.
     *
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string} exit code (-1 not runnable, -2 killed), stderr (+ stdout)
     */
    private static function run(array $argv, int $timeout, bool $withStdout = false): array
    {
        if (!is_file($argv[0]) || !is_executable($argv[0])) {
            return [-1, ''];
        }
        $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => $withStdout ? ['redirect', 2] : ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
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
