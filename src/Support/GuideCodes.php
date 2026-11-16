<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The imaging guide's legends (D62; GIRI README): the exam types (`Tip`), the indications and
 * the evidence grades, and how a report's modality names a type. Pure data.
 */
final class GuideCodes
{
    /** `Tip` → what it stands for (the guide's own words, Romanian content, D26) */
    public const TYPES = [
        'G' => 'Radiografie',
        'E' => 'Ecografie',
        'M' => 'IRM',
        'T' => 'CT',
        'N' => 'Medicină nucleară',
        'X' => 'Radiologie cu contrast',
        'D' => 'Diagnostic invaziv',
        'I' => 'Intervențional',
        'A' => 'Angiografie invazivă',
        'Z' => 'Fără examen',
    ];

    /** The indications, strongest first; the key is a short code for filters and CSS */
    public const INDICATIONS = [
        'indicated' => 'Indicat',
        'particular' => 'Doar în cazuri particulare',
        'specialist' => 'Doar cu aviz specializat',
        'justify' => 'Este necesară justificare detaliată',
        'not-first' => 'Neindicat în primă intenție',
        'not' => 'Neindicat',
        'see' => 'Vezi Detalii Ghid!',
    ];

    /** The indications a Check warns about (step 4): an exam the guide does not back for the situation */
    public const DISCOURAGED = ['justify', 'not-first', 'not'];

    public const GRADES = ['A', 'B', 'C', '?'];

    /** A report's modality (DICOM-like codes, D29) → the guide's `Tip` */
    private const MODALITY = [
        'CT' => 'T', 'MR' => 'M', 'MRI' => 'M', 'US' => 'E', 'CR' => 'G', 'DX' => 'G', 'MG' => 'G', 'RX' => 'G',
        'NM' => 'N', 'PT' => 'N', 'PET' => 'N', 'RF' => 'X', 'XA' => 'A',
    ];

    /** The short code of an indication as the guide writes it, or '' for one it does not list */
    public static function indicationCode(string $text): string
    {
        $code = array_search(trim($text), self::INDICATIONS, true);

        return \is_string($code) ? $code : '';
    }

    /** The guide's `Tip` for a report's modality, or null */
    public static function typeOfModality(string $modality): ?string
    {
        return self::MODALITY[strtoupper(trim($modality))] ?? null;
    }
}
