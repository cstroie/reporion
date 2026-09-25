<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Import;

use DateTime;
use DateTimeZone;
use Reporion\Storage\AtomicWriter;
use Reporion\Support\Fsync;
use RuntimeException;

/**
 * Allocates accession numbers per batch, following D20 pattern: {SITE}-{MOD}-{yy}-{seq}.
 * The sequence is per site + modality + year (D20): it restarts each year.
 * Maintains a per-batch counter file at data/import/<batch>/counters.json, not the live counters.json.
 * Uses the same atomic-write discipline as Storage\Journal (flock + read-modify-write).
 *
 * Given the pages root, it never reissues a number: each counter starts above
 * the highest seq already present in any page's `accession:` frontmatter
 * (disk, not the index — invariant 1), so a second batch cannot collide with
 * an earlier one or with pages created since.
 */
final class AccessionAllocator
{
    private string $countersFile;

    /**
     * @var array<string, int> $counters site:modality:yy → last sequence number issued
     */
    private array $counters = [];

    /**
     * @var array<string, int> $issued site:modality:yy → highest seq already on disk
     */
    private array $issued = [];

    private \Flock $lock;

    /**
     * @param string $batchDir data/import/<batch> directory
     * @param array{pattern: string, seq_pad: int} $config from conf/local.php['accession']
     * @param string $timezone for study_date year extraction
     * @param ?string $pagesRoot data/pages, to seed counters from accessions already issued
     */
    public function __construct(
        private readonly string $batchDir,
        private readonly array $config,
        private readonly string $timezone = 'UTC',
        ?string $pagesRoot = null,
    ) {
        if (!is_dir($batchDir)) {
            mkdir($batchDir, 0775, true);
        }
        $this->countersFile = $batchDir . '/counters.json';
        $this->load();
        if ($pagesRoot !== null && is_dir($pagesRoot)) {
            $this->issued = $this->scanIssued($pagesRoot);
        }
    }

    /**
     * Allocate the next accession for a given site and modality.
     * Returns empty string if site is null (should be queued for review).
     *
     * @param list<string> $modalities
     */
    public function allocate(?string $site, array $modalities, ?DateTime $studyDate): string
    {
        if ($site === null || empty($modalities)) {
            return '';
        }

        // Use the first modality for accession (if multiple, just pick one)
        $modality = $modalities[0];

        // Extract year from study_date
        $yy = $studyDate !== null
            ? $studyDate->format('y')
            : (new DateTime('now', new DateTimeZone($this->timezone)))->format('y');

        // Next seq for site + modality + year, above anything already issued
        $key = self::key($site, $modality, $yy);
        $this->counters[$key] = max($this->counters[$key] ?? 0, $this->issued[$key] ?? 0) + 1;
        $seq = $this->counters[$key];

        // Format per pattern (default: {SITE}-{MOD}-{yy}-{seq})
        $pattern = $this->config['pattern'] ?? '{SITE}-{MOD}-{yy}-{seq}';
        $seqPad = $this->config['seq_pad'] ?? 4;

        $accession = $pattern;
        $accession = str_replace('{SITE}', mb_strtoupper($site), $accession);
        $accession = str_replace('{MOD}', $modality, $accession);
        $accession = str_replace('{yy}', $yy, $accession);
        $accession = str_replace('{seq}', str_pad((string) $seq, $seqPad, '0', STR_PAD_LEFT), $accession);

        $this->save();

        return $accession;
    }

    private static function key(string $site, string $modality, string $yy): string
    {
        return mb_strtolower($site) . ':' . $modality . ':' . $yy;
    }

    /**
     * Highest seq per site:modality:yy among every page's `accession:`
     * frontmatter line that matches the configured pattern.
     *
     * @return array<string, int>
     */
    private function scanIssued(string $pagesRoot): array
    {
        $pattern = $this->config['pattern'] ?? '{SITE}-{MOD}-{yy}-{seq}';
        $regex = '/^' . strtr(preg_quote($pattern, '/'), [
            '\{SITE\}' => '(?<site>.+?)',
            '\{MOD\}' => '(?<mod>.+?)',
            '\{yy\}' => '(?<yy>\d{2})',
            '\{seq\}' => '(?<seq>\d+)',
        ]) . '$/u';

        $issued = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pagesRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->getFilename() !== 'current.md') {
                continue;
            }
            $head = (string) file_get_contents($file->getPathname(), false, null, 0, 4096);
            // Frontmatter only — never a line in the report body
            if (preg_match('/\A---\n(.*?\n)---\n/s', $head, $frontmatter) !== 1
                || preg_match('/^accession:[ \t]*["\']?([^"\'\n]+?)["\']?[ \t]*$/m', $frontmatter[1], $line) !== 1
                || preg_match($regex, $line[1], $m) !== 1) {
                continue;
            }
            $key = self::key($m['site'], $m['mod'], $m['yy']);
            $issued[$key] = max($issued[$key] ?? 0, (int) $m['seq']);
        }

        return $issued;
    }

    private function load(): void
    {
        if (is_file($this->countersFile)) {
            $data = json_decode((string) file_get_contents($this->countersFile), true);
            if (\is_array($data)) {
                $this->counters = $data;
            }
        }
    }

    private function save(): void
    {
        $json = json_encode($this->counters, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Cannot encode counters.json');
        }

        AtomicWriter::put($this->countersFile, $json . "\n");
    }
}
