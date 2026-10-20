<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Index\IndexInterface;
use Reporion\Storage\FileTree;
use Reporion\Support\AccessionFormat;

/**
 * Accession numbers for reports created here (D20): the state database's
 * `counter` table (D54; was data/counters.json) holds the last number issued
 * per site + modality + year. A key used for the first time is seeded from
 * the highest number already on disk (Support\AccessionFormat), and every
 * allocation also stays above what the index knows, so a number already in
 * use cannot be collided with.
 *
 * A number is taken just before the page is created, in one transaction: a
 * crash in between leaves a gap in the sequence, never a duplicate.
 */
final class Accessions
{
    public function __construct(
        private readonly FileTree $storage,
        private readonly IndexInterface $index,
        private readonly string $pattern = AccessionFormat::DEFAULT_PATTERN,
        private readonly int $pad = AccessionFormat::DEFAULT_PAD,
    ) {
    }

    /** The number the next allocate() would issue: for the form, allocates nothing */
    public function peek(string $siteCode, string $modality, string $yy): string
    {
        return AccessionFormat::format($this->pattern, $this->pad, $siteCode, $modality, $yy, $this->floor($siteCode, $modality, $yy) + 1);
    }

    public function allocate(string $siteCode, string $modality, string $yy): string
    {
        $next = $this->storage->nextAccession(AccessionFormat::key($siteCode, $modality, $yy), $this->floor($siteCode, $modality, $yy));

        return AccessionFormat::format($this->pattern, $this->pad, $siteCode, $modality, $yy, $next);
    }

    /**
     * The number the sequence must stay above: its last issued, the index's
     * highest and, for a key never used here, the highest on disk
     */
    private function floor(string $siteCode, string $modality, string $yy): int
    {
        $key = AccessionFormat::key($siteCode, $modality, $yy);
        $last = $this->storage->accessionCounters()[$key] ?? null;
        $last ??= AccessionFormat::issuedIn($this->storage->pageFiles(), $this->pattern)[$key] ?? 0;

        return max($last, $this->indexed($siteCode, $modality, $yy));
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
