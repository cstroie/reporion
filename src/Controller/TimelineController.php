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
use Reporion\Storage\StorageInterface;

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

        $pages = [];
        if ($patientKey !== '') {
            $pages = $this->index->findByPatientKey($patientKey, $principal);
        } elseif ($patientKeyWeak !== '') {
            $pages = $this->index->findByPatientKey($patientKeyWeak, $principal);
        }

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/timeline.php',
            [
                'path' => $path,
                'pages' => $pages,
                'patientKey' => $patientKey,
                'patientKeyWeak' => $patientKeyWeak,
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($path))
              + ChromeVars::pageHeaderFromRow($indexed, $principal, 'patient'),
            t('tabs.patient') . ' · ' . (string) $indexed['title'],
        ));
    }
}
