<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\ReportPath;

/**
 * A multi-exam report's accessions on save (phase 12, D20): an exam added
 * in the editor gets its number when the report is saved — the same
 * allocator the new-report form uses, called only for exams without one.
 * A report that has just become multi-exam carries its old top-level
 * number over to its first exam, so no number is lost or issued twice.
 */
final class ExamAccessions
{
    /**
     * @param array<string, array<string, mixed>> $sites config `sites` (Admin → Settings)
     */
    public function __construct(
        private readonly Accessions $accessions,
        private readonly array $sites,
    ) {
    }

    /**
     * @param array<string, mixed> $frontmatter
     *
     * @return array<string, mixed> the frontmatter with every exam numbered
     */
    public function fill(string $path, array $frontmatter): array
    {
        if (!ReportPath::isReport($path) || !Exams::isMulti($frontmatter) || !array_is_list($frontmatter['exams'])) {
            return $frontmatter;
        }
        $exams = $frontmatter['exams'];
        $top = MetaText::text($frontmatter['accession'] ?? null);
        if ($top !== '') {
            // The page's number goes to its first exam only when no exam holds it already: since
            // phase 27 the top-level one is a copy, and exams may have been reordered (D20: never twice)
            $held = array_map(static fn (mixed $e): string => \is_array($e) ? MetaText::text($e['accession'] ?? null) : '', $exams);
            if (!\in_array($top, $held, true) && \is_array($exams[0]) && $held[0] === '') {
                $exams[0]['accession'] = $top;
            }
            unset($frontmatter['accession']);
        }

        $site = MetaText::text($frontmatter['site'] ?? null);
        $modalities = \is_array($frontmatter['modality'] ?? null) ? $frontmatter['modality'] : [$frontmatter['modality'] ?? null];
        $modality = \is_string($modalities[0] ?? null) ? $modalities[0] : '';
        // An unquoted YAML date arrives as a timestamp: read it as a date, never as text
        $yy = MetaText::date($frontmatter['study_date'] ?? null, 'y');
        $siteCode = MetaText::text($this->sites[$site]['accession_code'] ?? null) ?: $site;
        foreach ($exams as $i => $exam) {
            if (!\is_array($exam) || MetaText::text($exam['accession'] ?? null) !== '') {
                continue;
            }
            // Each exam numbered by its own modality and year when it has them (phase 27)
            $own = Exams::listOf($exam['modality'] ?? null)[0] ?? $modality;
            $year = isset($exam['study_date']) ? MetaText::date($exam['study_date'], 'y') : $yy;
            $year = preg_match('/^\d{2}$/', $year) === 1 ? $year : $yy;
            if ($siteCode !== '' && $own !== '' && preg_match('/^\d{2}$/', $year) === 1) {
                $exams[$i]['accession'] = $this->accessions->allocate($siteCode, $own, $year);
            }
        }
        $frontmatter['exams'] = $exams;

        return $frontmatter;
    }
}
