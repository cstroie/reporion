<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Closure;
use DateTimeImmutable;
use Exception;
use Reporion\Auth\UserStoreInterface;
use Reporion\Exception\PageNotFoundException;
use Reporion\Storage\FlatFile;
use Reporion\Storage\PageRecord;
use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\PageTitle;
use Reporion\Support\ReportName;

/**
 * The view-model behind templates/print/report.php — the ONE template for
 * /{path}/print and /export/{path}.pdf (D34: the PDF is the print preview,
 * rendered by dompdf). The body goes through Render::toHtml(), the same call
 * the page view uses (invariant 4).
 *
 * The page path is never part of what this produces (invariant 8): the
 * verification line cites /r/{pid}/{rev}.
 */
final class PrintView
{
    private const MIME = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    /**
     * @param array<string, array<string, mixed>> $sites conf['sites'] — letterhead + device names per site code
     */
    public function __construct(
        private readonly Render $render,
        private readonly FlatFile $storage,
        private readonly UserStoreInterface $users,
        private readonly array $sites,
        private readonly string $baseUrl,
        private readonly string $printCssFile,
    ) {
    }

    /**
     * @param bool $pseudonymise leave the patient block out (anonymous exports of public pages)
     *
     * @return array<string, mixed>
     */
    public function vars(PageRecord $record, bool $pseudonymise): array
    {
        // A single-exam report's exam seen at the top level (phase 27: protocol lives on the exam)
        $fm = Exams::flat($record->frontmatter);
        $siteCode = MetaText::text($fm['site'] ?? null);
        $site = $this->sites[$siteCode] ?? [];
        $device = MetaText::text($fm['device'] ?? null);
        $patient = \is_array($fm['patient'] ?? null) && !$pseudonymise ? $fm['patient'] : null;
        // The exam, never the patient's name, titles a printed report (Support\ReportName, D30)
        $title = ReportName::examTitle($fm);

        return [
            'css' => (string) @file_get_contents($this->printCssFile),
            'title' => $title !== '' ? $title : t('print.untitled'),
            'site' => [
                'name' => MetaText::text($site['name'] ?? null) !== '' ? MetaText::text($site['name']) : t('app.name'),
                'dept' => MetaText::text($site['dept'] ?? null),
                'address' => MetaText::text($site['address'] ?? null),
                'phone' => MetaText::text($site['phone'] ?? null),
            ],
            // The PACS's accession number of each exam's study (2026-10-12): what the imaging center knows
            // the report by. Reporion's own number (D20) is never printed; a copy that leaves the
            // patient out carries no number that leads back to the study either
            'accessions' => $pseudonymise ? [] : Exams::pacsAccessions($fm),
            'studyDate' => MetaText::date($fm['study_date'] ?? null, MetaText::DATE),
            'studyDateTime' => MetaText::dateTime($fm['study_date'] ?? null, MetaText::DATE, ' H:i'),
            'patient' => $patient === null ? null : [
                'name' => MetaText::text($patient['name'] ?? null),
                'age' => self::age($patient['born'] ?? null, $fm['study_date'] ?? null),
                'sex' => MetaText::text($patient['sex'] ?? null),
            ],
            'referrer' => MetaText::text($fm['referrer'] ?? null),
            'indication' => MetaText::text($fm['indication'] ?? null),
            'device' => (\Reporion\Support\Devices::names(\is_array($site) ? $site : [])[$device] ?? '') ?: $device,
            'protocol' => MetaText::text($fm['protocol'] ?? null),
            'region' => MetaText::text($fm['region'] ?? null),
            // Paper and export files: links to other pages keep their text only (invariant 8)
            'bodyHtml' => $this->render->body(ReportName::forExport($record->body, $fm), $fm, unlinkPages: true, mediaSrc: $this->embedder($record), examIds: Exams::isMulti($fm))->html,
            'isDraft' => $record->status === 'draft',
            'rev' => $record->rev,
            'signer' => $this->signer($record),
            'verifyUrl' => $this->baseUrl . '/r/' . $record->pid . '/' . $record->rev,
        ];
    }

    /**
     * The variables of templates/print/page.php — a page that is not a report
     * (Support\ReportPath): title, text, revision and date, verification link.
     *
     * @return array<string, mixed>
     */
    public function pageVars(PageRecord $record): array
    {
        $title = PageTitle::of($record->frontmatter, $record->body);
        $last = $record->revlog[array_key_last($record->revlog)] ?? [];

        return [
            'css' => (string) @file_get_contents($this->printCssFile),
            'title' => $title !== '' ? $title : t('print.untitled'),
            'siteName' => t('app.name'),
            'rev' => $record->rev,
            'updated' => MetaText::date($last['ts'] ?? null, MetaText::DATE),
            // Paper and export files: links to other pages keep their text only (invariant 8)
            'bodyHtml' => $this->render->body($record->body, $record->frontmatter, unlinkPages: true, mediaSrc: $this->embedder($record))->html,
            'verifyUrl' => $this->baseUrl . '/r/' . $record->pid . '/' . $record->rev,
        ];
    }

    /**
     * Images are embedded as data: URIs — dompdf fetches nothing, and the
     * printed sheet must not depend on a URL. Only files attached to this
     * page: anything else is left as its alt text, the same rule that
     * decides who may fetch a file (Index::canSeeMedia()).
     *
     * @return Closure(string, string): ?string
     */
    private function embedder(PageRecord $record): Closure
    {
        try {
            $attached = array_map(static fn (array $entry): string => $entry['sha256'] . '.' . $entry['ext'], $this->storage->mediaOf($record->path));
        } catch (PageNotFoundException) {
            $attached = [];
        }

        return function (string $sha256, string $ext) use ($attached): ?string {
            $file = \in_array($sha256 . '.' . $ext, $attached, true) ? $this->storage->mediaFile($sha256, $ext) : null;
            $bytes = $file !== null ? @file_get_contents($file) : false;

            return $bytes === false ? null : 'data:' . self::MIME[$ext] . ';base64,' . base64_encode($bytes);
        };
    }

    /**
     * The export's file name. For a signed-in caller it is the page path
     * with `-` for `:` (`reports-mri-polimed-261008-name.pdf`), so reports sent to the
     * imaging center can never be mixed up — the owner's decision of
     * 2026-10-11, amending invariant 8. An anonymous export (a public page)
     * still names the report, never the patient: the pid plus the revision
     * (D1) — no accession of either kind (2026-10-12).
     */
    public static function fileName(PageRecord $record, string $extension, bool $signedIn): string
    {
        if ($signedIn) {
            return (preg_replace('/[^A-Za-z0-9._-]+/', '-', $record->path) ?? $record->pid) . '.' . $extension;
        }
        return $record->pid . '-rev' . $record->rev . '.' . $extension;
    }

    /**
     * The signer block for the current revision, when it is signed: the
     * account's display name and title (FORMATS.md §10), the parafa
     * recorded at signing.
     *
     * @return array{name: string, title: string, parafa: string, at: string}|null
     */
    private function signer(PageRecord $record): ?array
    {
        $signature = null;
        foreach ((array) ($record->meta['signatures'] ?? []) as $candidate) {
            if (\is_array($candidate) && (int) ($candidate['rev'] ?? 0) === $record->rev) {
                $signature = $candidate;
            }
        }
        if ($signature === null) {
            return null;
        }

        $username = (string) ($signature['by'] ?? '');
        try {
            $account = $username !== '' ? $this->users->find($username) : null;
        } catch (Exception) {
            $account = null;
        }

        return [
            'name' => $account?->signatureName() ?? $username,
            'title' => $account?->title ?? '',
            'parafa' => \is_string($signature['parafa'] ?? null) ? $signature['parafa'] : '',
            'at' => MetaText::date($signature['ts'] ?? null, MetaText::DATE . ' H:i'),
        ];
    }

    /**
     * Age at the study: whole years from a birth date, or study year minus
     * birth year when only the year is known (how imported ages were
     * recorded). Null when either side is unknown.
     */
    private static function age(mixed $born, mixed $studyDate): ?int
    {
        $bornText = MetaText::text($born);
        $studyText = MetaText::text($studyDate);
        if ($bornText === '' || $studyText === '') {
            return null;
        }
        try {
            $study = \is_int($studyDate) ? new DateTimeImmutable('@' . $studyDate) : new DateTimeImmutable($studyText);
            if (preg_match('/^\d{4}$/', $bornText) === 1) {
                $years = (int) $study->format('Y') - (int) $bornText;
            } else {
                $from = new DateTimeImmutable($bornText);
                $years = $from <= $study ? $from->diff($study)->y : -1;
            }
        } catch (Exception) {
            return null;
        }

        return $years >= 0 && $years < 150 ? $years : null;
    }
}
