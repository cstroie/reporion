<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Service\NewReport;
use Reporion\Support\ReportPath;

/**
 * What the top bar's palette can do besides search (2026-11-25): commands typed
 * after Ctrl+K, `new medima mri`, completed with Tab. The words are the report
 * namespaces' own segments (`reports:{modality}:{site}`), read through the
 * visibility predicate (invariant 6), so a caller is offered only the places
 * they could see; whether they may create there is NewReport::canCreateReports().
 */
final class PaletteCommands
{
    /**
     * The modality/site pairs `new` completes to.
     *
     * @return list<array{path: string, modality: string, site: string}>
     */
    public static function newTargets(?User $principal, IndexInterface $index): array
    {
        if ($principal === null || !NewReport::canCreateReports($principal)) {
            return [];
        }
        $targets = [];
        foreach ($index->listSubnamespaces(ReportPath::ROOT, $principal) as $modality) {
            $mod = (string) $modality['name'];
            $targets[] = ['path' => ReportPath::ROOT . ':' . $mod, 'modality' => $mod, 'site' => ''];
            foreach ($index->listSubnamespaces(ReportPath::ROOT . ':' . $mod, $principal) as $site) {
                $targets[] = ['path' => ReportPath::ROOT . ':' . $mod . ':' . $site['name'], 'modality' => $mod, 'site' => (string) $site['name']];
            }
        }

        return $targets;
    }
}
