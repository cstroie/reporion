<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Ai\Actions;
use Reporion\Service\PatientStudies;
use Reporion\Storage\StorageInterface;
use Reporion\Support\MetaText;
use Reporion\Support\ReportPath;

/**
 * GET /{path}/timeline (docs/architecture-api.md Table 1:
 * "patient timeline — all reports for the same patient, ordered
 * by study date").
 *
 * Same read entitlement as viewing the page itself. Uses the
 * patient_key from the indexed row (strong key when CNP is
 * present, weak fallback otherwise) to find every report for
 * that patient. D11: entering a CNP on any one report
 * retroactively links the set at next index pass.
 */
final class TimelineController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly PatientStudies $studies,
        // The Evolution panel (the reserved `evolution` prompt, 2026-10-07)
        private readonly ?Actions $aiActions = null,
    ) {
    }

    public function timeline(Request $request, string $path, ?User $principal): Response
    {
        $indexed = $this->index->findByPath($path, $principal);
        // The patient's history is a report feature: an ordinary page has no patient
        if ($indexed === null || !ReportPath::isReport($path)) {
            throw new PageNotFoundException();
        }

        $patientKey = (string) ($indexed['patient_key'] ?? '');
        $patientKeyWeak = (string) ($indexed['patient_key_weak'] ?? '');

        $pages = $this->studies->forRow($indexed, $principal);

        $current = $this->storage->read($path);
        $patient = \is_array($current->frontmatter['patient'] ?? null) ? $current->frontmatter['patient'] : [];

        // Other exams that might belong to this patient but the exact
        // patient_key match cannot find — a CNP on one and not the other,
        // or a small spelling difference (TODO 13). Suggestions only: the
        // caller previews and decides, nothing is allocated here.
        $possibleMatches = $this->index->findPossiblePatientMatches(
            (string) ($indexed['title'] ?? ''),
            [$patientKey, $patientKeyWeak],
            (string) ($indexed['pid'] ?? ''),
            $principal,
        );
        foreach ($possibleMatches as &$match) {
            $match['canAllocate'] = $principal !== null && $principal->canWrite((string) $match['path']);
        }
        unset($match);

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/timeline.php',
            [
                'path' => $path,
                'pages' => $pages,
                'stats' => self::stats($pages),
                'patientLabel' => implode(' · ', array_filter([
                    MetaText::text($patient['name'] ?? null),
                    MetaText::text($patient['born'] ?? null),
                ])),
                'patientKey' => $patientKey,
                'patientKeyWeak' => $patientKeyWeak,
                'possibleMatches' => $possibleMatches,
                // The studies' checkboxes: Compare (phase 17a) for any reader of two of them
                'canPick' => \count($pages) >= 2,
                'comparePick' => ($request->query['compare'] ?? null) === 'pick',
                // Join (phase 29): offered when two of the patient's reports are the caller's to edit
                'canJoin' => \count(array_filter($pages, static fn (array $p): bool => $principal !== null && $principal->canWrite((string) $p['path']))) >= 2,
                'aiEvolution' => $principal !== null && \count($pages) >= 2 && $principal->canWrite($path)
                    && $this->aiActions?->special($path, 'evolution') !== null,
                'mergeStatus' => \in_array($request->query['merge'] ?? null, ['ok', 'nokey', 'conflict'], true) ? $request->query['merge'] : null,
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($path))
              + ChromeVars::pageHeaderFromRow($indexed, $principal, 'timeline'),
            t('tabs.timeline') . ' · ' . (string) $indexed['title'],
        ));
    }

    /**
     * Facts about the patient's visible studies, counted from the rows
     * themselves — never inferred findings (D15, D18).
     *
     * @param list<array<string, mixed>> $pages
     *
     * @return array{studies: int, modalities: int, sites: int, first: string, last: string}
     */
    private static function stats(array $pages): array
    {
        $modalities = [];
        $sites = [];
        $dates = [];
        foreach ($pages as $page) {
            foreach (array_filter(array_map(trim(...), explode(',', (string) ($page['modality'] ?? '')))) as $modality) {
                $modalities[$modality] = true;
            }
            if (($page['site'] ?? '') !== '') {
                $sites[(string) $page['site']] = true;
            }
            if (($page['study_date'] ?? '') !== '') {
                $dates[] = MetaText::date($page['study_date'], MetaText::DATE);
            }
        }

        return [
            'studies' => \count($pages),
            'modalities' => \count($modalities),
            'sites' => \count($sites),
            'first' => $dates !== [] ? (string) end($dates) : '',
            'last' => $dates !== [] ? (string) reset($dates) : '',
        ];
    }
}
