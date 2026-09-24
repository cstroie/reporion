<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Import;

use DateTime;
use Reporion\Support\Slug;

/**
 * Extracts metadata from converted markdown per architecture-import.md Table 3.
 * Confidence rule: fields are extracted with confidence or left empty and queued for review.
 * A wrong value is worse than a blank one.
 *
 * @internal
 */
final class MetadataExtractor
{
    /**
     * @var array<string, array{field: string, reason: string}>
     */
    private array $review = [];

    public function __construct(
        private readonly ImportMap $importMap,
        private readonly string $sourceRelativePath,
        private readonly string $sourceFileHash,
        private readonly string $body,
        private readonly ?string $template,
        private readonly array $macrosPrevious,
    ) {
    }

    /**
     * Extract all metadata. Returns frontmatter array + review queue for low-confidence fields.
     *
     * @return array{frontmatter: array<string, mixed>, review: list<array{field: string, reason: string}>}
     */
    public function extract(
        string $sourceDir,
        string $sourceFilePath,
        AccessionAllocator $accessionAllocator,
    ): array {
        $this->review = [];

        $studyDate = $this->extractStudyDate($sourceFilePath);
        $patientName = $this->extractPatientName();
        [$born, $sex] = $this->extractAgeAndSex();
        $title = $this->extractTitle();
        $modalities = $this->extractModalities($title ?? '');
        $regions = $this->extractRegions($title ?? '');
        $site = $this->extractSite();
        $device = $this->extractDevice($site, $modalities);
        $accession = $accessionAllocator->allocate($site, $modalities, $studyDate);
        $summary = $this->extractSummary();

        // Transform priors paths to a placeholder (will be resolved in a second pass)
        $priors = array_map(fn ($p) => ['_prior_source_path' => $p], $this->macrosPrevious);

        $frontmatter = [
            'title' => $title,
            'modality' => $modalities ?: ['other'],
            'region' => $regions ?: ['whole-body'],
            'site' => $site,
            'device' => $device,
            'study_date' => $studyDate?->format('Y-m-d\TH:i:sP') ?? date('Y-m-d\TH:i:sP'),
            'accession' => $accession,
            'accession_generated' => true,
            'patient' => [
                'name' => $patientName,
                'born' => $born,
                'sex' => $sex,
                'cnp' => null,
            ],
            'template' => $this->template,
            'priors' => $priors ?: null,
            'summary' => $summary ?: '',
            'visibility' => 'private',
            'status' => 'archived',
            'imported_from' => $this->sourceRelativePath . ' sha256:' . $this->sourceFileHash,
            'import_batch' => 'real',
        ];

        // Remove null values for cleaner output
        $frontmatter = array_filter($frontmatter, fn ($v) => $v !== null);

        // Queue review for low-confidence fields
        if ($born !== null) {
            $this->review[] = ['field' => 'patient.born', 'reason' => 'derived from age in Indicatie text — flagged approximate'];
        }
        if (count($regions) > 1) {
            $this->review[] = ['field' => 'region', 'reason' => 'multiple regions detected (combined study) — check title synthesis'];
        }
        if ($site === null) {
            $this->review[] = ['field' => 'site', 'reason' => 'unmapped source folder — must provide site before commit'];
        }
        if ($summary === '') {
            $this->review[] = ['field' => 'summary', 'reason' => 'no Concluzii section found — needs manual summary'];
        }

        return [
            'frontmatter' => $frontmatter,
            'review' => $this->review,
        ];
    }

    /**
     * Extract study_date from filename {yymmdd} prefix, corroborated by body date.
     * Returns DateTime or null if unresolvable.
     */
    private function extractStudyDate(string $sourceFilePath): ?DateTime
    {
        // Extract {yymmdd} from filename if present
        if (preg_match('/(\d{6})-/', basename($sourceFilePath), $m)) {
            $yymmdd = $m[1];
            $yy = (int) substr($yymmdd, 0, 2);
            $mm = (int) substr($yymmdd, 2, 2);
            $dd = (int) substr($yymmdd, 4, 2);

            // Assume 20xx for yy < 50, else 19xx (this corpus is 2014–2026)
            $yyyy = ($yy < 50) ? 2000 + $yy : 1900 + $yy;

            try {
                $filenameDate = new DateTime(sprintf('%04d-%02d-%02d', $yyyy, $mm, $dd));
                $filenameDate->setTimezone(new \DateTimeZone($this->importMap->timezone()));

                // Try to corroborate with a body date
                $bodyDate = $this->extractBodyDate();
                if ($bodyDate !== null && $bodyDate->format('Y-m-d') !== $filenameDate->format('Y-m-d')) {
                    // Dates disagree — flag for review, filename wins
                    $this->review[] = [
                        'field' => 'study_date',
                        'reason' => sprintf(
                            'filename date %s vs body date %s — filename used',
                            $filenameDate->format('Y-m-d'),
                            $bodyDate->format('Y-m-d')
                        ),
                    ];
                }

                return $filenameDate;
            } catch (\Exception) {
                // Invalid date in filename, try body
            }
        }

        // Fall back to body date
        return $this->extractBodyDate();
    }

    /**
     * Extract date from body text (dd.mm.yyyy or other formats in import-map).
     * Handles 2-digit years (post-2000 corpus, so 00-99 → 2000-2099).
     */
    private function extractBodyDate(): ?DateTime
    {
        $formats = $this->importMap->dateFormats();
        foreach ($formats as $format) {
            // Try to find a date in this format in the body
            if (preg_match('/(\d{1,2}[.\/\-]\d{1,2}[.\/\-]\d{2,4})/', $this->body, $m)) {
                try {
                    $date = DateTime::createFromFormat($format, $m[1]);
                    if ($date !== false) {
                        // Handle 2-digit year: if year is 0-99, add 2000 (post-2000 corpus)
                        $year = (int) $date->format('Y');
                        if ($year < 100) {
                            $date = $date->modify('+' . (2000 - $year) . ' years');
                        }
                        $date->setTimezone(new \DateTimeZone($this->importMap->timezone()));

                        return $date;
                    }
                } catch (\Exception) {
                    // Invalid format, try next
                }
            }
        }

        return null;
    }

    /**
     * Extract patient name from H2 heading (DokuWiki H1, converted as ##).
     * Falls back to META block, then any heading, then null.
     */
    private function extractPatientName(): ?string
    {
        // Try META block first (higher confidence)
        if (preg_match('/&name\s*=\s*(.+?)$/m', $this->body, $m)) {
            $name = trim($m[1]);
            if ($name !== '') {
                return mb_strtoupper($name);
            }
        }

        // Prefer H2 heading (patient name from DokuWiki H1)
        if (preg_match('/^##\s+(.+)$/m', $this->body, $m)) {
            return mb_strtoupper(trim($m[1]));
        }

        // Fallback: first heading at any level
        if (preg_match('/^#+\s+(.+)$/m', $this->body, $m)) {
            return mb_strtoupper(trim($m[1]));
        }

        return null;
    }

    /**
     * Extract age and sex from either a "Date pacient" block or from "Indicație" line(s).
     * Returns [born_year, sex] where born_year is the derived year (nullable) and sex is M/F/null.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function extractAgeAndSex(): array
    {
        $born = null;
        $sex = null;

        // Try "Date pacient" block first (higher confidence when present)
        if (preg_match('/Vârstă:\s*(\d+)\s*ani/i', $this->body, $m)) {
            $age = (int) $m[1];
            $born = date('Y') - $age;
        }

        if (preg_match('/Sex:\s*([MFmf])/i', $this->body, $m)) {
            $sex = mb_strtoupper($m[1]);
        }

        // If not found in "Date pacient", try "Indicație"
        if ($born === null && preg_match('/Indicație.*?vârstă\s+(?:de\s+)?(\d+)\s*ani/i', $this->body, $m)) {
            $age = (int) $m[1];
            // Derive year from study_date; if no study_date, use current year (approximate)
            $born = date('Y') - $age;
        }

        if ($sex === null && preg_match('/Indicație.*?sex\s+([MFmf])/i', $this->body, $m)) {
            $sex = mb_strtoupper($m[1]);
        }

        return [$born, $sex];
    }

    /**
     * Extract title (page name) from first heading found (prefer H2, but fallback to any heading).
     * Title = patient name or exam type when patient name is missing.
     */
    private function extractTitle(): ?string
    {
        // Prefer H2 heading (patient name from DokuWiki H1, 6 equals)
        if (preg_match('/^##\s+(.+)$/m', $this->body, $m)) {
            return trim($m[1]);
        }

        // Fallback: use the first heading found at any level (H3, H4, etc.)
        if (preg_match('/^#+\s+(.+)$/m', $this->body, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Extract modality from title keywords.
     *
     * @return list<string>
     */
    private function extractModalities(string $title): array
    {
        $modalities = $this->importMap->findModalities($title);

        // Also search in H3 exam type headings
        if (preg_match_all('/^###\s+(.+)$/m', $this->body, $m)) {
            foreach ($m[1] as $heading) {
                $modalities = array_merge($modalities, $this->importMap->findModalities($heading));
            }
        }

        $modalities = array_unique($modalities);
        if (empty($modalities)) {
            // Default fallback if nothing detected
            return ['other'];
        }

        return array_values($modalities);
    }

    /**
     * Extract region from title and H3 exam type headings.
     *
     * @return list<string>
     */
    private function extractRegions(string $title): array
    {
        $regions = $this->importMap->findRegions($title);

        // Also search in H3 exam type headings
        if (preg_match_all('/^###\s+(.+)$/m', $this->body, $m)) {
            foreach ($m[1] as $heading) {
                $regions = array_merge($regions, $this->importMap->findRegions($heading));
            }
        }

        $regions = array_unique($regions);
        if (empty($regions)) {
            // Default fallback
            return ['whole-body'];
        }

        return array_values($regions);
    }

    /**
     * Extract site from the source folder mapping.
     */
    private function extractSite(): ?string
    {
        // Source path is like "mri/scuc/260921-name.txt" (relative to source_root which is /tmp/dokuwiki/data/pages/reports)
        // We need the "mri/scuc" part to match against folder_to_site
        $parts = explode('/', $this->sourceRelativePath);
        if (count($parts) >= 2) {
            $folderKey = implode('/', array_slice($parts, 0, 2)); // e.g. "mri/scuc"
            $siteInfo = $this->importMap->siteFor($folderKey);
            if ($siteInfo !== null) {
                return $siteInfo['site'] ?? null;
            }
        }

        return null;
    }

    /**
     * Extract device for a given site and modality.
     *
     * @param list<string> $modalities
     */
    private function extractDevice(?string $site, array $modalities): ?string
    {
        if ($site === null || empty($modalities)) {
            return null;
        }

        // Try each modality to find a device
        foreach ($modalities as $mod) {
            $device = $this->importMap->deviceFor($site, $mod);
            if ($device !== null) {
                return $device;
            }
        }

        return null;
    }

    /**
     * Extract summary from Concluzii section (H3 or higher), or leave empty + queue for review.
     */
    private function extractSummary(): string
    {
        // Look for Concluzii/Concluzie section at any heading level (###, ####, etc.)
        if (preg_match('/^#+\s+Conclu[zs]ii?\s*\n((?:(?!^#+\s).)*)/im', $this->body, $m)) {
            $summary = trim($m[1]);
            // Strip leading/trailing whitespace and truncate
            $lines = array_filter(array_map('trim', explode("\n", $summary)));
            $summary = implode("\n", $lines);
            $summary = substr($summary, 0, 500); // Truncate to 500 chars

            return $summary;
        }

        return '';
    }
}
