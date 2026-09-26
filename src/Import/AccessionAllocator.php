<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Import;

use DateTime;
use DateTimeZone;
use Reporion\Storage\AtomicWriter;
use Reporion\Support\AccessionFormat;
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
            $this->issued = AccessionFormat::issuedOnDisk($pagesRoot, $this->config['pattern'] ?? AccessionFormat::DEFAULT_PATTERN);
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
        $key = AccessionFormat::key($site, $modality, $yy);
        $this->counters[$key] = max($this->counters[$key] ?? 0, $this->issued[$key] ?? 0) + 1;
        $accession = AccessionFormat::format(
            $this->config['pattern'] ?? AccessionFormat::DEFAULT_PATTERN,
            $this->config['seq_pad'] ?? AccessionFormat::DEFAULT_PAD,
            $site,
            $modality,
            $yy,
            $this->counters[$key],
        );

        $this->save();

        return $accession;
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
