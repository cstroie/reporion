<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Dicom;

use DateTimeImmutable;
use Reporion\Support\Cnp;
use Throwable;

/**
 * One C-FIND answer (Scu::parse(), keyword → value) read the Reporion way
 * pure functions, no I/O.
 */
final class Study
{
    /** DICOM modality → Reporion modality (resources/schema) */
    public const MODALITIES = ['CT' => 'CT', 'MR' => 'MR', 'US' => 'US', 'CR' => 'XR', 'DX' => 'XR', 'MG' => 'MG', 'PT' => 'PET'];

    /** "IONESCU^MARIA^ELENA" → "IONESCU Maria Elena": family name as is, given names title-cased */
    public static function name(string $pn): string
    {
        $alphabetic = explode('=', $pn)[0];
        $parts = array_map('trim', explode('^', $alphabetic));
        $family = mb_strtoupper(array_shift($parts) ?? '');
        $given = [];
        foreach (\array_slice($parts, 0, 2) as $part) {
            foreach (preg_split('/\s+/u', $part) ?: [] as $word) {
                if ($word !== '') {
                    $given[] = mb_convert_case(mb_strtolower($word), MB_CASE_TITLE);
                }
            }
        }

        return trim($family . ' ' . implode(' ', $given));
    }

    /** PatientID when it is a valid CNP (the sites' PACS use it as the patient id), else '' */
    public static function cnp(array $row): string
    {
        $id = preg_replace('/\s+/', '', (string) ($row['PatientID'] ?? '')) ?? '';

        return Cnp::isValid($id) ? $id : '';
    }

    public static function sex(array $row): ?string
    {
        $cnp = self::cnp($row);
        if ($cnp !== '') {
            return Cnp::sex($cnp);
        }
        $sex = strtoupper(trim((string) ($row['PatientSex'] ?? '')));

        return \in_array($sex, ['M', 'F'], true) ? $sex : null;
    }

    public static function born(array $row): ?int
    {
        $cnp = self::cnp($row);
        if ($cnp !== '' && ($birth = Cnp::birthDate($cnp)) !== null) {
            return (int) $birth->format('Y');
        }

        return preg_match('/^(\d{4})\d{4}$/', (string) ($row['PatientBirthDate'] ?? ''), $m) === 1 ? (int) $m[1] : null;
    }

    /** StudyDate + StudyTime, or null; `hasTime` false when there is no time */
    public static function when(array $row): ?DateTimeImmutable
    {
        $date = (string) ($row['StudyDate'] ?? '');
        if (preg_match('/^\d{8}$/', $date) !== 1) {
            return null;
        }
        $time = preg_match('/^(\d{2})(\d{2})?/', (string) ($row['StudyTime'] ?? ''), $m) === 1 ? $m[1] . ':' . ($m[2] ?? '00') : '00:00';
        try {
            $when = DateTimeImmutable::createFromFormat('!Ymd H:i', $date . ' ' . $time);
        } catch (Throwable) {
            return null;
        }

        return $when !== false ? $when : null;
    }

    public static function hasTime(array $row): bool
    {
        return preg_match('/^\d{2}/', (string) ($row['StudyTime'] ?? '')) === 1;
    }

    /** The Reporion modalities of a study: ModalitiesInStudy, else the one it was queried for */
    public static function modalities(array $row, string $queried = ''): array
    {
        $codes = array_filter(array_map('trim', explode('\\', (string) ($row['ModalitiesInStudy'] ?? ''))));
        if ($codes === [] && $queried !== '') {
            $codes = [$queried];
        }
        $out = [];
        foreach ($codes as $code) {
            if (isset(self::MODALITIES[$code])) {
                $out[self::MODALITIES[$code]] = true;
            }
        }

        return array_keys($out);
    }

    /** BodyPartExamined (DICOM's own terms) → the schema's region */
    private const REGIONS = [
        'HEAD' => 'neuro', 'BRAIN' => 'neuro', 'SKULL' => 'neuro', 'NECK' => 'head-neck', 'ORBIT' => 'orbits',
        'SPINE' => 'spine', 'CSPINE' => 'spine', 'TSPINE' => 'spine', 'LSPINE' => 'spine', 'SSPINE' => 'spine',
        'CHEST' => 'chest', 'LUNG' => 'chest', 'ABDOMEN' => 'abdomen', 'LIVER' => 'abdomen', 'KIDNEY' => 'abdomen', 'PELVIS' => 'pelvis',
        'HEART' => 'cardiac', 'BREAST' => 'breast', 'WHOLEBODY' => 'whole-body',
        'KNEE' => 'msk', 'SHOULDER' => 'msk', 'ELBOW' => 'msk', 'WRIST' => 'msk', 'HAND' => 'msk', 'HIP' => 'msk', 'ANKLE' => 'msk',
        'FOOT' => 'msk', 'LEG' => 'msk', 'ARM' => 'msk', 'EXTREMITY' => 'msk',
    ];

    /**
     * The form's regions the BodyPartExamined stands for: none when it is empty or not one we know
     *
     * @param array<string, string> $row
     *
     * @return list<string>
     */
    public static function regions(array $row): array
    {
        $region = self::REGIONS[strtoupper(trim((string) ($row['BodyPartExamined'] ?? '')))] ?? null;

        return $region !== null ? [$region] : [];
    }

    /**
     * "CT TORACE NATIV" → "Ct Torace Nativ", the form's exam title (as the HIS plugin does):
     * the order's own words (RequestedProcedureDescription, then the scheduled step's) when a file
     * has them, they name the exam; else StudyDescription, which a site often fills with the
     * department or the protocol group
     */
    public static function title(array $row): string
    {
        $description = '';
        foreach (['RequestedProcedureDescription', 'ScheduledProcedureStepDescription', 'StudyDescription'] as $key) {
            $description = trim((string) ($row[$key] ?? ''));
            if ($description !== '') {
                break;
            }
        }
        // Nothing named the exam: the body part, else the series (`AAknee_scout` → "Aaknee Scout"), a title to correct
        if ($description === '') {
            $description = trim((string) ($row['BodyPartExamined'] ?? ''));
        }
        if ($description === '') {
            $description = trim(str_replace('_', ' ', (string) ($row['SeriesDescription'] ?? '')));
        }

        if (str_contains($description, '^')) {
            return self::caretTitle($description);
        }

        return $description !== '' ? mb_convert_case(mb_strtolower($description), MB_CASE_TITLE) : '';
    }

    /**
     * A description written as ^-separated parts, modality first: "IRM^nativ^genunchi^stang" →
     * "IRM Genunchi stâng". The modality keeps its capitals, "nativ" (the default) is left out,
     * the next word starts a capital, the rest is lower case, and the side gets its diacritics
     */
    private static function caretTitle(string $description): string
    {
        $parts = array_values(array_filter(
            array_map(static fn (string $part): string => trim($part), explode('^', $description)),
            static fn (string $part): bool => $part !== '' && mb_strtolower($part) !== 'nativ',
        ));
        foreach ($parts as $i => $part) {
            $part = mb_strtolower($part);
            $part = preg_replace(['/\bstanga\b/u', '/\bstang\b/u'], ['stângă', 'stâng'], $part) ?? $part;
            $parts[$i] = match (true) {
                $i === 0 => mb_strtoupper($part),
                $i === 1 => mb_strtoupper(mb_substr($part, 0, 1)) . mb_substr($part, 1),
                default => $part,
            };
        }

        return implode(' ', $parts);
    }
}
