<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use DateTimeImmutable;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Ai\Actions;
use Reporion\Service\PatientStudies;
use Reporion\Service\Render;
use Reporion\Storage\StorageInterface;
use Reporion\Support\CompareSections;
use Reporion\Support\MetaText;
use Reporion\Support\ReportName;
use Reporion\Support\ReportPath;

/**
 * GET /{path}/compare[?with={pid}] — a report beside another study of the
 * same patient (roadmap phase 17a, design/mockup/WikiCompare.dc.html's
 * report-vs-prior half). Without `with`, the study before this one on the
 * timeline. Medical, not editorial: two revisions of one page are
 * /{path}/revisions.
 *
 * The other study must be one of PatientStudies::forRow() for this page and
 * this caller — the timeline's own predicate, so visibility and grants are
 * applied in the query (invariant 6) and another patient's report, an
 * invisible one and a missing one are the same 404. Both are rendered whole
 * through Render::toHtml() (invariant 4), newer on the left, by sections
 * (Support\CompareSections) unless `sync=0`. No diff: word or line diffs
 * between two different reports say nothing useful.
 *
 * With `paths[]` (the timeline's ticked studies) it redirects to the
 * newer one's compare with the older one's pid, or back to the timeline
 * when the pick is not two of this patient's studies.
 */
final class CompareController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly PatientStudies $studies,
        private readonly Render $render,
        // The Evolution panel, narrowed to these two studies
        private readonly ?Actions $aiActions = null,
    ) {
    }

    public function compare(Request $request, string $path, ?User $principal): Response
    {
        $indexed = $this->index->findByPath($path, $principal);
        if ($indexed === null || !ReportPath::isReport($path)) {
            throw new PageNotFoundException();
        }
        $studies = $this->studies->forRow($indexed, $principal);

        if (\array_key_exists('paths', $request->queryLists) || \array_key_exists('paths', $request->query)) {
            return $this->picked($request, $path, $studies);
        }

        $pid = (string) $indexed['pid'];
        $with = \is_string($request->query['with'] ?? null) ? $request->query['with'] : null;
        $other = null;
        foreach ($studies as $i => $study) {
            if ($with !== null ? (string) $study['pid'] === $with && $with !== $pid : (string) $study['pid'] === $pid) {
                // No `with`: the next older study, newest first being the index's order
                $other = $with !== null ? $study : ($studies[$i + 1] ?? null);
                break;
            }
        }
        if ($other === null) {
            throw new PageNotFoundException();
        }

        $thisSide = $this->side($path, $indexed, $request->basePath);
        $otherSide = $this->side((string) $other['path'], $other, $request->basePath);
        [$newer, $older] = $thisSide['date'] >= $otherSide['date'] ? [$thisSide, $otherSide] : [$otherSide, $thisSide];
        $sync = ($request->query['sync'] ?? '1') !== '0';

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/compare.php',
            [
                'path' => $path,
                'withPid' => (string) $other['pid'],
                'newer' => $newer,
                'older' => $older,
                'interval' => self::interval($older['date'], $newer['date']),
                'sync' => $sync,
                'aligned' => $sync ? CompareSections::align($newer['html'], $older['html']) : ['rows' => [], 'reordered' => false],
                'aiEvolution' => $principal !== null && $principal->canWrite($path)
                    && $this->aiActions?->special($path, 'evolution') !== null,
                'basePath' => $request->basePath,
                'pageFunction' => t('compare.title'),
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($path))
              + ChromeVars::pageHeaderFromRow($indexed, $principal, 'timeline'),
            t('compare.title') . ' · ' . (string) $indexed['title'],
        ));
    }

    /**
     * @param list<array<string, mixed>> $studies
     */
    private function picked(Request $request, string $path, array $studies): Response
    {
        $paths = array_values(array_unique($request->queryLists['paths'] ?? []));
        $chosen = array_values(array_filter($studies, static fn (array $s): bool => \in_array((string) $s['path'], $paths, true)));
        if (\count($paths) !== 2 || \count($chosen) !== 2) {
            return Response::redirect($request->basePath . '/' . $path . '/timeline?compare=pick');
        }
        // Newest first already (findByPatientKey()): the first is the newer
        return Response::redirect($request->basePath . '/' . $chosen[0]['path'] . '/compare?with=' . rawurlencode((string) $chosen[1]['pid']));
    }

    /**
     * @param array<string, mixed> $row the study's index row
     *
     * @return array{path: string, pid: string, title: string, date: string, status: string, rev: int, html: string}
     */
    private function side(string $path, array $row, string $basePath): array
    {
        $record = $this->storage->read($path);

        return [
            'path' => $path,
            'pid' => $record->pid,
            'title' => ReportName::examTitle($record->frontmatter, (string) ($row['title'] ?? '')),
            'date' => MetaText::date($record->frontmatter['study_date'] ?? null, MetaText::DATE),
            'status' => $record->status,
            'rev' => $record->rev,
            // The name heading goes, as on the page view: the header already names the patient
            'html' => $this->render->body(ReportName::withoutNameHeading($record->body, $record->frontmatter), $record->frontmatter, $basePath)->html,
        ];
    }

    /** "6 months 18 days" between two Y-m-d dates; '' when either is unknown */
    private static function interval(string $from, string $to): string
    {
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $b = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($a === false || $b === false) {
            return '';
        }
        $diff = $a->diff($b);
        $months = $diff->y * 12 + $diff->m;
        $parts = [];
        if ($months > 0) {
            $parts[] = t($months === 1 ? 'compare.month' : 'compare.months', [$months]);
        }
        if ($diff->d > 0 || $months === 0) {
            $parts[] = t($diff->d === 1 ? 'compare.day' : 'compare.days', [$diff->d]);
        }

        return implode(' ', $parts);
    }
}
