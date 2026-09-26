<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Reporion\Support\Cnp;

/**
 * Every CNP here is generated from its parts by the checksum rule — never a
 * real person's (CLAUDE.md invariant 10).
 */
final class CnpTest extends TestCase
{
    /** A CNP made from its parts, with the check digit computed */
    public static function make(int $s, string $yymmdd, string $county = '40', string $serial = '123'): string
    {
        $first12 = $s . $yymmdd . $county . $serial;

        return $first12 . Cnp::checksum($first12);
    }

    public function testAGeneratedCnpIsValidAndAnyAlteredDigitIsNot(): void
    {
        $cnp = self::make(2, '800115');

        self::assertTrue(Cnp::isValid($cnp));
        for ($i = 0; $i < 13; ++$i) {
            $altered = $cnp;
            $altered[$i] = (string) (((int) $cnp[$i] + 1) % 10);
            if ($i === 0 && $altered[0] === '0') {
                continue;
            }
            self::assertFalse(Cnp::isValid($altered), "digit {$i} changed");
        }
    }

    public function testShapeAndCalendarAreChecked(): void
    {
        self::assertFalse(Cnp::isValid(''));
        self::assertFalse(Cnp::isValid('123'));
        self::assertFalse(Cnp::isValid(self::make(1, '800115') . '0'), 'fourteen digits');
        self::assertFalse(Cnp::isValid('0' . substr(self::make(1, '800115'), 1)), 'S = 0');
        self::assertFalse(Cnp::isValid(self::make(1, '800230')), '30 February');
        self::assertTrue(Cnp::isValid(self::make(5, '000229')), '29 February 2000');
        self::assertFalse(Cnp::isValid(self::make(1, '000229')), '29 February 1900');
    }

    /**
     * @return iterable<string, array{int, string, ?string, string}>
     */
    public static function centuries(): iterable
    {
        yield '1 — male, 1900s' => [1, '800115', 'M', '1980-01-15'];
        yield '2 — female, 1900s' => [2, '800115', 'F', '1980-01-15'];
        yield '3 — male, 1800s' => [3, '991231', 'M', '1899-12-31'];
        yield '4 — female, 1800s' => [4, '991231', 'F', '1899-12-31'];
        yield '5 — male, 2000s' => [5, '100305', 'M', '2010-03-05'];
        yield '6 — female, 2000s' => [6, '100305', 'F', '2010-03-05'];
        yield '7 — resident male, recent century' => [7, '100305', 'M', '2010-03-05'];
        yield '8 — resident female, 1900s when 20yy is ahead' => [8, '750305', 'F', '1975-03-05'];
        yield '9 — foreigner, no sex' => [9, '900101', null, '1990-01-01'];
    }

    /**
     * @dataProvider centuries
     */
    public function testSexAndBirthDate(int $s, string $yymmdd, ?string $sex, string $born): void
    {
        $cnp = self::make($s, $yymmdd);
        $today = new DateTimeImmutable('2026-09-26');

        self::assertTrue(Cnp::isValid($cnp));
        self::assertSame($sex, Cnp::sex($cnp));
        self::assertSame($born, Cnp::birthDate($cnp, $today)?->format('Y-m-d'));
    }

    public function testAgeAtTheExamDate(): void
    {
        $born = new DateTimeImmutable('1980-09-27');

        self::assertSame(45, Cnp::age($born, new DateTimeImmutable('2026-09-26')), 'the day before the birthday');
        self::assertSame(46, Cnp::age($born, new DateTimeImmutable('2026-09-27')));
        self::assertNull(Cnp::age($born, new DateTimeImmutable('1970-01-01')));
    }
}
