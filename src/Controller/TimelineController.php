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
use Reporion\Service\NewReport;
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
    ) {
    }

    public function timeline(Request $request, string $path, ?User $principal): Response
    {
        $indexed = $this->index->findByPath($path, $principal);
        if ($indexed === null) {
            throw new PageNotFoundException();
        }

        $patientKey = (string) ($indexed['patient_key'] ?? '');
        $patientKeyWeak = (string) ($indexed['patient_key_weak'] ?? '');

        $pages = $this->studies->forRow($indexed, $principal);

        // "New exam" (phase 9) starts from the newest report here the caller can read
        $newExamPid = null;
        if ($principal !== null && NewReport::canCreateReports($principal)) {
            foreach ($pages as $page) {
                if (ReportPath::isReport((string) $page['path'])) {
                    $newExamPid = (string) $page['pid'];
                    break;
                }
            }
        }

        $current = $this->storage->read($path);
        $patient = \is_array($current->frontmatter['patient'] ?? null) ? $current->frontmatter['patient'] : [];

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
                'newExamPid' => $newExamPid,
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($path))
              + ChromeVars::pageHeaderFromRow($indexed, $principal, 'patient'),
            t('tabs.patient') . ' · ' . (string) $indexed['title'],
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
                $dates[] = MetaText::date($page['study_date'], 'd M Y');
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
