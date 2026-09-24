<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Import;

use InvalidArgumentException;
use RuntimeException;

final class ImportMap
{
    /**
     * @param array<string, mixed> $config decoded conf/import-map.json
     */
    public function __construct(
        private readonly array $config,
    ) {
        $this->validate();
    }

    /**
     * Returns site info for a relative folder path, or null if unmapped (never guesses).
     * E.g. 'reports/ct/mioveni' → {'site': 'mioveni', 'modality': ['CT'], ...}, or null.
     *
     * @return array{site: string, modality?: list<string>}|null
     */
    public function siteFor(string $relativeFolder): ?array
    {
        $mapping = (array) ($this->config['folder_to_site'] ?? []);
        return $mapping[$relativeFolder] ?? null;
    }

    /**
     * Returns device code for a site:modality pair, or null.
     * E.g. 'mioveni:CT' → 'MV-CT-01', or null.
     */
    public function deviceFor(string $site, string $modality): ?string
    {
        $mapping = (array) ($this->config['device_by_site_modality'] ?? []);
        return $mapping["{$site}:{$modality}"] ?? null;
    }

    /**
     * Keyword-to-modality mappings. E.g. 'IRM' → 'MR', 'CT' → 'CT'.
     * Returns all matches found in the given text, deduplicated.
     *
     * @return list<string>
     */
    public function findModalities(string $text): array
    {
        $mapping = (array) ($this->config['title_keywords']['modality'] ?? []);
        $found = [];
        foreach (array_keys($mapping) as $keyword) {
            if (stripos($text, $keyword) !== false) {
                $found[] = $mapping[$keyword];
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Keyword-to-region mappings. E.g. 'cerebral' → 'neuro', 'coloan' → 'spine'.
     * Returns all matches found in the given text, deduplicated.
     *
     * @return list<string>
     */
    public function findRegions(string $text): array
    {
        $mapping = (array) ($this->config['title_keywords']['region'] ?? []);
        $found = [];
        foreach (array_keys($mapping) as $keyword) {
            if (stripos($text, $keyword) !== false) {
                $found[] = $mapping[$keyword];
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * List of relative paths to skip during scan (e.g. 'playground', 'wiki', 'sidebar', 'start').
     *
     * @return list<string>
     */
    public function skipPaths(): array
    {
        return (array) ($this->config['skip_paths'] ?? []);
    }

    /**
     * Date formats to try when parsing body text dates, per docs/architecture-import.md §3.
     * E.g. ['d.m.Y', 'd.m.y', 'Y-m-d'].
     *
     * @return list<string>
     */
    public function dateFormats(): array
    {
        return (array) ($this->config['date_formats'] ?? []);
    }

    /**
     * Timezone for parsing study_date (e.g. 'Europe/Bucharest').
     */
    public function timezone(): string
    {
        return (string) ($this->config['timezone'] ?? 'UTC');
    }

    /**
     * Whether section headings should be treated as sub-exams (D29).
     */
    public function sectionHeadingIsSubexam(): bool
    {
        return (bool) ($this->config['section_heading_is_subexam'] ?? false);
    }

    private function validate(): void
    {
        if (!\is_array($this->config['folder_to_site'] ?? null)) {
            throw new RuntimeException('import-map: missing or invalid folder_to_site');
        }
        if (!\is_array($this->config['device_by_site_modality'] ?? null)) {
            throw new RuntimeException('import-map: missing or invalid device_by_site_modality');
        }
        if (!\is_array($this->config['title_keywords'] ?? null)) {
            throw new RuntimeException('import-map: missing or invalid title_keywords');
        }
        if (!\is_array($this->config['date_formats'] ?? null)) {
            throw new RuntimeException('import-map: missing or invalid date_formats');
        }
    }
}
