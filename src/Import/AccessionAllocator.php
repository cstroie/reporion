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
 * Maintains a per-batch counter file at data/import/<batch>/counters.json, not the live counters.json.
 * Uses the same atomic-write discipline as Storage\Journal (flock + read-modify-write).
 */
final class AccessionAllocator
{
    private string $countersFile;

    /**
     * @var array<string, int> $counters site:modality → next sequence number
     */
    private array $counters = [];

    private \Flock $lock;

    /**
     * @param string $batchDir data/import/<batch> directory
     * @param array{pattern: string, seq_pad: int} $config from conf/local.php['accession']
     * @param string $timezone for study_date year extraction
     */
    public function __construct(
        private readonly string $batchDir,
        private readonly array $config,
        private readonly string $timezone = 'UTC',
    ) {
        if (!is_dir($batchDir)) {
            mkdir($batchDir, 0775, true);
        }
        $this->countersFile = $batchDir . '/counters.json';
        $this->load();
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
        $key = "{$site}:{$modality}";

        // Increment the counter for this key
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
        $seq = $this->counters[$key];

        // Extract year from study_date
        $yy = $studyDate !== null
            ? $studyDate->format('y')
            : (new DateTime('now', new DateTimeZone($this->timezone)))->format('y');

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
