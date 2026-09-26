<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use DateTimeImmutable;

/**
 * The Romanian personal numeric code (CNP): S YY MM DD JJ NNN C.
 *
 * - S — sex and century: 1/2 born 1900–1999, 3/4 1800–1899, 5/6 2000–2099
 *   (odd = male, even = female); 7/8 foreign residents and 9 foreigners,
 *   whose century is not encoded (taken as the most recent one that is not
 *   in the future); 9 carries no sex.
 * - YYMMDD — birth date; JJ — county; NNN — serial; C — checksum over the
 *   first twelve digits with the weights 279146358279 (mod 11, 10 → 1).
 *
 * Pure: validates and derives, never stores. A CNP is the strong patient
 * key (D11) and sensitive; nothing here logs it.
 */
final class Cnp
{
    private const WEIGHTS = [2, 7, 9, 1, 4, 6, 3, 5, 8, 2, 7, 9];

    public static function isValid(string $cnp): bool
    {
        if (preg_match('/^[1-9]\d{12}$/', $cnp) !== 1) {
            return false;
        }

        return self::checksum($cnp) === (int) $cnp[12] && self::birthDate($cnp) !== null;
    }

    /** The check digit the first twelve digits call for */
    public static function checksum(string $first12): int
    {
        $sum = 0;
        foreach (self::WEIGHTS as $i => $weight) {
            $sum += (int) $first12[$i] * $weight;
        }
        $check = $sum % 11;

        return $check === 10 ? 1 : $check;
    }

    /** 'M', 'F', or null (S = 9, or not a CNP) */
    public static function sex(string $cnp): ?string
    {
        $s = (int) ($cnp[0] ?? 0);

        return match (true) {
            $s >= 1 && $s <= 8 => $s % 2 === 1 ? 'M' : 'F',
            default => null,
        };
    }

    /**
     * The birth date, or null when the digits are not a real date.
     *
     * @param ?DateTimeImmutable $today for the century of S = 7/8/9 (default: now)
     */
    public static function birthDate(string $cnp, ?DateTimeImmutable $today = null): ?DateTimeImmutable
    {
        if (preg_match('/^([1-9])(\d{2})(\d{2})(\d{2})/', $cnp, $m) !== 1) {
            return null;
        }
        $yy = (int) $m[2];
        $century = match ((int) $m[1]) {
            1, 2 => 1900,
            3, 4 => 1800,
            5, 6 => 2000,
            default => 2000 + $yy > (int) ($today ?? new DateTimeImmutable('now'))->format('Y') ? 1900 : 2000,
        };
        $year = $century + $yy;
        if (!checkdate((int) $m[3], (int) $m[4], $year)) {
            return null;
        }

        return new DateTimeImmutable(\sprintf('%04d-%s-%s', $year, $m[3], $m[4]));
    }

    /** Whole years between $born and $at (the exam date), or null if $at is before $born */
    public static function age(DateTimeImmutable $born, DateTimeImmutable $at): ?int
    {
        return $at < $born ? null : $born->diff($at)->y;
    }
}
