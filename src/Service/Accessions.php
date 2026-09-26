<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Index\IndexInterface;
use Reporion\Storage\AtomicWriter;
use Reporion\Support\AccessionFormat;
use RuntimeException;

/**
 * Accession numbers for reports created here (D20): data/counters.json
 * holds the last number issued per site + modality + year. A key used for
 * the first time is seeded from the highest number already on disk — the
 * importer's rule (Support\AccessionFormat) — and every allocation also
 * stays above what the index knows, so a batch imported since cannot be
 * collided with.
 *
 * A number is taken just before the page is created, under a lock: a crash
 * in between leaves a gap in the sequence, never a duplicate.
 */
final class Accessions
{
    public function __construct(
        private readonly string $dataRoot,
        private readonly IndexInterface $index,
        private readonly string $pattern = AccessionFormat::DEFAULT_PATTERN,
        private readonly int $pad = AccessionFormat::DEFAULT_PAD,
    ) {
    }

    /** The number the next allocate() would issue — for the form, allocates nothing */
    public function peek(string $siteCode, string $modality, string $yy): string
    {
        $key = AccessionFormat::key($siteCode, $modality, $yy);
        $counters = $this->load();
        $last = $counters[$key] ?? $this->seed()[$key] ?? 0;

        return AccessionFormat::format($this->pattern, $this->pad, $siteCode, $modality, $yy, max($last, $this->indexed($siteCode, $modality, $yy)) + 1);
    }

    public function allocate(string $siteCode, string $modality, string $yy): string
    {
        $key = AccessionFormat::key($siteCode, $modality, $yy);
        $lock = fopen($this->dataRoot . '/counters.json.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock the accession counters');
        }
        try {
            $counters = $this->load();
            if (!isset($counters[$key])) {
                // First use of this key: start above whatever is on disk
                $counters[$key] = $this->seed()[$key] ?? 0;
            }
            $counters[$key] = max($counters[$key], $this->indexed($siteCode, $modality, $yy)) + 1;
            AtomicWriter::put($this->dataRoot . '/counters.json', (string) json_encode($counters, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            return AccessionFormat::format($this->pattern, $this->pad, $siteCode, $modality, $yy, $counters[$key]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, int> */
    private function load(): array
    {
        $file = $this->dataRoot . '/counters.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return \is_array($data) ? array_map('intval', $data) : [];
    }

    /** @return array<string, int> */
    private function seed(): array
    {
        return AccessionFormat::issuedOnDisk($this->dataRoot . '/pages', $this->pattern);
    }

    /** The highest sequence the index holds for this key */
    private function indexed(string $siteCode, string $modality, string $yy): int
    {
        // Everything before {seq}, e.g. "SCUC-MR-26-", is what the index is searched for
        $marked = strtr($this->pattern, ['{SITE}' => mb_strtoupper($siteCode), '{MOD}' => $modality, '{yy}' => $yy, '{seq}' => "\x00"]);
        $prefix = strstr($marked, "\x00", true);
        $regex = AccessionFormat::regex($this->pattern);
        $key = AccessionFormat::key($siteCode, $modality, $yy);
        $highest = 0;
        foreach ($this->index->accessionsStartingWith($prefix === false ? $marked : $prefix) as $accession) {
            if (preg_match($regex, $accession, $m) === 1 && AccessionFormat::key($m['site'], $m['mod'], $m['yy']) === $key) {
                $highest = max($highest, (int) $m['seq']);
            }
        }

        return $highest;
    }
}
