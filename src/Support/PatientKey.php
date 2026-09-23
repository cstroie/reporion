<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use Normalizer;

/**
 * D11: patient_key = sha256(cnp) when known, else a weak fallback hash of
 * the diacritic-folded name plus birth year plus sex. Never the name itself
 * — only these hashes are allowed into the index or the audit log.
 */
final class PatientKey
{
    public static function strong(?string $cnp): ?string
    {
        $cnp = trim((string) $cnp);

        return $cnp === '' ? null : hash('sha256', $cnp);
    }

    public static function weak(string $name, ?int $born, ?string $sex): string
    {
        $bornPart = $born !== null ? (string) $born : '';
        $sexPart = $sex !== null ? mb_strtolower($sex) : '';

        return hash('sha256', self::normalizeName($name) . '|' . $bornPart . '|' . $sexPart);
    }

    private static function normalizeName(string $name): string
    {
        $folded = Normalizer::normalize($name, Normalizer::FORM_D) ?: $name;
        $stripped = preg_replace('/\p{Mn}+/u', '', $folded) ?? $folded;
        $lower = mb_strtolower($stripped);

        return preg_replace('/\s+/', ' ', trim($lower)) ?? $lower;
    }
}
