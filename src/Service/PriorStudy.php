<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use RuntimeException;

/**
 * A report's prior study: the first of its `priors` the caller can read,
 * else (phase 34c) the patient's latest earlier report of the same
 * modality and, when the report names regions, region, among those the
 * caller can list (PatientStudies: invariant 6 in the query). The
 * assistant's {previous} (Service\Ai\Context) and the editor's Prior
 * section (D51) ask the same.
 */
final class PriorStudy
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
    ) {
    }

    /**
     * @param ?array<string, mixed> $exam the exam in front (Support\Exams::of()): its region is matched
     *
     * @return ?array{record: PageRecord, auto: bool}
     */
    public function for(PageRecord $page, ?User $principal, ?array $exam = null): ?array
    {
        $named = $this->named($page, $principal);
        if ($named !== null) {
            return ['record' => $named, 'auto' => false];
        }
        $auto = $this->auto($page, $this->studies($page, $principal), $exam);

        return $auto !== null ? ['record' => $auto, 'auto' => true] : null;
    }

    /** The first of the report's `priors` the caller can read */
    public function named(PageRecord $page, ?User $principal): ?PageRecord
    {
        foreach ((array) ($page->frontmatter['priors'] ?? []) as $path) {
            if (\is_string($path) && $path !== '' && $this->index->findByPath($path, $principal) !== null) {
                $prior = $this->read($path);
                if ($prior !== null) {
                    return $prior;
                }
            }
        }

        return null;
    }

    /**
     * The patient's reports the caller can list, latest study first; none
     * when the index does not know the page
     *
     * @return list<array<string, mixed>>
     */
    public function studies(PageRecord $page, ?User $principal): array
    {
        $row = $this->index->findByPath($page->path, $principal);

        return $row !== null ? (new PatientStudies($this->index))->forRow($row, $principal) : [];
    }

    /**
     * Among $studies, the latest one before this report's study date that
     * shares a modality with it and, when this report (or the exam in
     * front) names regions, a region; null when none does
     *
     * @param list<array<string, mixed>> $studies studies()
     * @param ?array<string, mixed>      $exam    the exam in front on a multi-exam report: its region is matched, not the report's
     */
    public function auto(PageRecord $page, array $studies, ?array $exam = null): ?PageRecord
    {
        $list = static fn (mixed $v): array => array_values(array_filter(array_map(
            static fn (string $s): string => mb_strtolower(trim($s)),
            \is_array($v) ? array_map('strval', $v) : explode(',', (string) $v),
        ), static fn (string $s): bool => $s !== ''));
        $own = null;
        foreach ($studies as $study) {
            if ((string) $study['path'] === $page->path) {
                $own = $study;
            }
        }
        $date = MetaText::date($page->frontmatter['study_date'] ?? ($own['study_date'] ?? null), 'Y-m-d');
        $modalities = $list(Exams::report($page->frontmatter)['modality'] ?: ($own['modality'] ?? []));
        $regions = $list($exam['region'] ?? $own['region'] ?? Exams::regions($page->frontmatter));
        if ($date === '' || $modalities === []) {
            return null;
        }
        // Latest first (as the index gives them, sorted again to be sure)
        usort($studies, static fn (array $a, array $b): int => strcmp((string) ($b['study_date'] ?? ''), (string) ($a['study_date'] ?? '')));
        foreach ($studies as $study) {
            $when = MetaText::date($study['study_date'] ?? null, 'Y-m-d');
            if ((string) $study['path'] === $page->path || $when === '' || $when >= $date) {
                continue;
            }
            if (array_intersect($modalities, $list($study['modality'] ?? '')) === []) {
                continue;
            }
            if ($regions !== [] && array_intersect($regions, $list($study['region'] ?? '')) === []) {
                continue;
            }
            // A row of the listing: it already applied the caller's visibility and grants
            $prior = $this->read((string) $study['path']);
            if ($prior !== null) {
                return $prior;
            }
        }

        return null;
    }

    private function read(string $path): ?PageRecord
    {
        if ($path === '') {
            return null;
        }
        try {
            return $this->storage->read($path);
        } catch (RuntimeException) {
            return null;
        }
    }
}
