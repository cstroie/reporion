<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use DateTimeImmutable;
use Exception;
use Reporion\Auth\UserStoreInterface;
use Reporion\Storage\PageRecord;
use Reporion\Support\MetaText;

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
    /**
     * @param array<string, array<string, mixed>> $sites conf['sites'] — letterhead + device names per site code
     */
    public function __construct(
        private readonly Render $render,
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
        $fm = $record->frontmatter;
        $siteCode = MetaText::text($fm['site'] ?? null);
        $site = $this->sites[$siteCode] ?? [];
        $device = MetaText::text($fm['device'] ?? null);
        $patient = \is_array($fm['patient'] ?? null) && !$pseudonymise ? $fm['patient'] : null;
        $title = MetaText::text($fm['title'] ?? null);

        return [
            'css' => (string) @file_get_contents($this->printCssFile),
            'title' => $title !== '' ? $title : t('print.untitled'),
            'site' => [
                'name' => MetaText::text($site['name'] ?? null) !== '' ? MetaText::text($site['name']) : t('app.name'),
                'dept' => MetaText::text($site['dept'] ?? null),
                'address' => MetaText::text($site['address'] ?? null),
                'phone' => MetaText::text($site['phone'] ?? null),
            ],
            'accession' => MetaText::text($fm['accession'] ?? null),
            'studyDate' => MetaText::date($fm['study_date'] ?? null, 'd.m.Y'),
            'studyDateTime' => MetaText::dateTime($fm['study_date'] ?? null, 'd.m.Y', ' H:i'),
            'patient' => $patient === null ? null : [
                'name' => MetaText::text($patient['name'] ?? null),
                'age' => self::age($patient['born'] ?? null, $fm['study_date'] ?? null),
                'sex' => MetaText::text($patient['sex'] ?? null),
            ],
            'referrer' => MetaText::text($fm['referrer'] ?? null),
            'indication' => MetaText::text($fm['indication'] ?? null),
            'device' => MetaText::text(($site['devices'] ?? [])[$device] ?? null) ?: $device,
            'protocol' => MetaText::text($fm['protocol'] ?? null),
            'region' => MetaText::text($fm['region'] ?? null),
            // Paper and export files: links to other pages keep their text only (invariant 8)
            'bodyHtml' => $this->render->toHtml($record->body, unlinkPages: true)->html,
            'isDraft' => $record->status === 'draft',
            'rev' => $record->rev,
            'signer' => $this->signer($record),
            'verifyUrl' => $this->baseUrl . '/r/' . $record->pid . '/' . $record->rev,
        ];
    }

    /**
     * A file name that names the report, never the patient: the accession,
     * or the pid when there is none, plus the revision.
     */
    public static function fileName(PageRecord $record, string $extension): string
    {
        $accession = preg_replace('/[^A-Za-z0-9._-]+/', '-', MetaText::text($record->frontmatter['accession'] ?? null)) ?? '';

        return ($accession !== '' ? $accession : $record->pid) . '-rev' . $record->rev . '.' . $extension;
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
            'at' => MetaText::date($signature['ts'] ?? null, 'd.m.Y H:i'),
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
