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

    /** No injected runner: findStudiesPerServer() runs its lanes side by side */
    private readonly bool $parallel;

    public function __construct(
        private readonly string $findscu,
        private readonly int $timeout,
        ?Closure $runner = null,
    ) {
        $this->runner = $runner ?? self::run(...);
        $this->parallel = $runner === null;
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
        [$dir, $argv] = $this->prepare($server, $match, $private);
        try {
            [$exit, $stderr] = ($this->runner)($argv, $this->timeout * 3 + 5);

            return self::collect($dir, $exit, $stderr);
        } finally {
            self::cleanup($dir);
        }
    }

    /**
     * findStudies() for several PACS at once: each lane (a site) is one
     * PACS and its queries, asked in order, one at a time — a PACS may limit
     * our concurrent associations — while the lanes run side by side, so a
     * worklist over every site waits for the slowest site, not their sum. A
     * lane stops at its first failure, as a loop of findStudies() would.
     * An injected runner (tests) runs the lanes one after another.
     *
     * @param array<string, array{server: array{host: string, port: int, aet: string, calling: string}, queries: list<array{match: array<string, string>, private: array<string, string>}>}> $lanes
     *
     * @return array<string, array{rows: list<list<array<string, string>>>, error: ?string}> per lane: each query's rows, in order, up to the failure
     */
    public function findStudiesPerServer(array $lanes): array
    {
        $out = [];
        foreach (array_keys($lanes) as $code) {
            $out[$code] = ['rows' => [], 'error' => null];
        }
        if (!$this->parallel) {
            foreach ($lanes as $code => $lane) {
                foreach ($lane['queries'] as $query) {
                    try {
                        $out[$code]['rows'][] = $this->findStudies($lane['server'], $query['match'], $query['private']);
                    } catch (DicomException $e) {
                        $out[$code]['error'] = $e->getMessage();
                        break;
                    }
                }
            }

            return $out;
        }

        $running = [];
        $next = array_map(static fn (): int => 0, $lanes);
        $start = function (string|int $code) use (&$running, &$next, &$out, $lanes): void {
            if (!isset($lanes[$code]['queries'][$next[$code]])) {
                return;
            }
            $query = $lanes[$code]['queries'][$next[$code]++];
            try {
                [$dir, $argv] = $this->prepare($lanes[$code]['server'], $query['match'], $query['private']);
            } catch (DicomException $e) {
                $out[$code]['error'] = $e->getMessage();

                return;
            }
            $started = self::start($argv);
            if ($started === null) {
                self::cleanup($dir);
                $out[$code]['error'] = self::reason(-1, '');

                return;
            }
            $running[$code] = $started + ['buffer' => '', 'deadline' => microtime(true) + $this->timeout * 3 + 5, 'dir' => $dir];
        };
        foreach (array_keys($lanes) as $code) {
            $start($code);
        }
        while ($running !== []) {
            foreach (array_keys($running) as $code) {
                $job = $running[$code];
                $buffer = $job['buffer'];
                [$done, $exit] = self::poll($job['process'], $job['stderr'], $buffer, $job['deadline']);
                if (!$done) {
                    $running[$code]['buffer'] = $buffer;
                    continue;
                }
                unset($running[$code]);
                try {
                    $out[$code]['rows'][] = self::collect($job['dir'], $exit, $buffer);
                } catch (DicomException $e) {
                    $out[$code]['error'] = $e->getMessage();
                    continue;
                } finally {
                    self::cleanup($job['dir']);
                }
                $start($code);
            }
            if ($running !== []) {
                usleep(20000);
            }
        }

        return $out;
    }

    /**
     * A query's private directory and findscu's argument list
     *
     * @param array{host: string, port: int, aet: string, calling: string} $server
     * @param array<string, string>                                        $match
     * @param array<string, string>                                        $private
     *
     * @return array{0: string, 1: list<string>}
     *
     * @throws DicomException
     */
    private function prepare(array $server, array $match, array $private): array
    {
        $dir = sys_get_temp_dir() . '/reporion-dicom-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) {
            throw new DicomException('failed');
        }
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
            try {
                if (file_put_contents($query, self::dataset($private)) === false) {
                    throw new DicomException('failed');
                }
            } catch (DicomException $e) {
                self::cleanup($dir);
                throw $e;
            }
            chmod($query, 0600);
            $argv[] = $query;
        }

        return [$dir, $argv];
    }

    /**
     * The answers findscu left in $dir, once it has exited
     *
     * @return list<array<string, string>>
     *
     * @throws DicomException
     */
    private static function collect(string $dir, int $exit, string $stderr): array
    {
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
    }

    /** A query's private directory and everything in it, removed */
    private static function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new FilesystemIterator($dir) as $file) {
            @unlink($file->getPathname());
        }
        @rmdir($dir);
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
     * C-STORE of one DICOM file (roadmap phase 22: a signed report's SR) with
     * dcmtk's `storescu` next to findscu, proposing only the file's own SOP
     * class (`--required`). The file is written to a private 0700 directory
     * and removed after; its bytes carry the patient, never the command line.
     * On failure the exception carries storescu's log (its warnings and
     * errors: AE titles, host, port, the temporary file name, association
     * and status lines — no patient data at this level), shown to the owner
     * only.
     *
     * @param array{host: string, port: int, aet: string, calling: string} $server
     *
     * @throws DicomException
     */
    public function store(array $server, string $bytes): void
    {
        $storescu = \dirname($this->findscu) . '/storescu';
        $dir = sys_get_temp_dir() . '/reporion-dicom-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) {
            throw new DicomException('failed');
        }
        $file = $dir . '/report.dcm';
        try {
            if (file_put_contents($file, $bytes) === false) {
                throw new DicomException('failed');
            }
            chmod($file, 0600);
            [$exit, $output] = ($this->runner)([
                $storescu, '--required', '-aet', $server['calling'], '-aec', $server['aet'],
                '-to', (string) $this->timeout, '-ta', (string) $this->timeout, '-td', (string) $this->timeout,
                $server['host'], (string) $server['port'], $file,
            ], $this->timeout * 3 + 5, true);
            // storescu may exit 0 when the PACS refuses the instance: the verdict is in its log —
            // an error line, or a store response that is not Success
            $refused = preg_match('/^E: /m', $output) === 1
                || (preg_match('/Received Store Response \(([^)]*)\)/', $output, $m) === 1 && !str_starts_with($m[1], 'Success'));
            if ($exit !== 0 || $refused) {
                $log = trim($output);
                if ($exit === -1) {
                    $log = 'cannot run ' . $storescu;
                } elseif ($exit === -2) {
                    $log .= ($log !== '' ? "\n" : '') . 'killed after ' . ($this->timeout * 3 + 5) . ' s';
                }
                throw new DicomException($exit === 0 ? 'refused' : self::reason($exit, $output), $log);
            }
        } finally {
            @unlink($file);
            @rmdir($dir);
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
        $started = self::start($argv, $withStdout);
        if ($started === null) {
            return [-1, ''];
        }
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            [$done, $exit] = self::poll($started['process'], $started['stderr'], $stderr, $deadline);
            if ($done) {
                return [$exit, $stderr];
            }
            usleep(20000);
        }
    }

    /**
     * A tool started (no shell), its stderr read without blocking — null
     * when it cannot be run
     *
     * @param list<string> $argv
     *
     * @return ?array{process: resource, stderr: resource}
     */
    private static function start(array $argv, bool $withStdout = false): ?array
    {
        if (!is_file($argv[0]) || !is_executable($argv[0])) {
            return null;
        }
        $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => $withStdout ? ['redirect', 2] : ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            return null;
        }
        stream_set_blocking($pipes[2], false);

        return ['process' => $process, 'stderr' => $pipes[2]];
    }

    /**
     * One look at a started tool: what it wrote to stderr so far goes into
     * $buffer; done once it has exited, or killed past $deadline (exit -2)
     *
     * @param resource $process
     * @param resource $stderr
     *
     * @return array{0: bool, 1: int} done, exit code
     */
    private static function poll($process, $stderr, string &$buffer, float $deadline): array
    {
        $chunk = fread($stderr, 8192);
        if (\is_string($chunk) && \strlen($buffer) < 65536) {
            $buffer .= $chunk;
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            $buffer .= (string) stream_get_contents($stderr);
            fclose($stderr);
            proc_close($process);

            return [true, (int) $status['exitcode']];
        }
        if (microtime(true) > $deadline) {
            proc_terminate($process, 9);
            fclose($stderr);
            proc_close($process);

            return [true, -2];
        }

        return [false, 0];
    }
}
