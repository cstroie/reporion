<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\CitationKey;

final class CitationKeyTest extends TestCase
{
    private static function none(): callable
    {
        return static fn (string $key): bool => false;
    }

    public function testFirstAuthorsFamilyNameAndYearFolded(): void
    {
        $csl = ['author' => [['family' => 'van Nobody-Ștefănescu', 'given' => 'Ann']], 'issued' => ['date-parts' => [[2024, 5]]]];

        self::assertSame('vannobodystefanescu2024', CitationKey::make($csl, self::none()));
    }

    public function testATakenKeyGetsTheNextLetter(): void
    {
        $taken = ['nobody2024', 'nobody2024b'];
        $csl = ['author' => [['family' => 'Nobody']], 'issued' => ['date-parts' => [[2024]]]];

        self::assertSame('nobody2024c', CitationKey::make($csl, static fn (string $k): bool => \in_array($k, $taken, true)));
    }

    public function testNoAuthorTakesTheTitlesFirstWordAndNoYearIsNd(): void
    {
        self::assertSame('gradingnd', CitationKey::make(['title' => 'The grading of made-up lesions'], self::none()));
        self::assertSame('sourcend', CitationKey::make([], self::none()));
        self::assertSame('source2024', CitationKey::make(['issued' => ['date-parts' => [[2024]]]], self::none()));
    }

    public function testEveryKeyMadeIsAValidKey(): void
    {
        foreach ([[], ['author' => [['family' => 'Ö']]], ['title' => '!!!'], ['author' => [['literal' => 'WHO Working Group']]]] as $csl) {
            self::assertTrue(CitationKey::isValid(CitationKey::make($csl, self::none())), json_encode($csl, JSON_UNESCAPED_UNICODE));
        }
        self::assertFalse(CitationKey::isValid('2024nobody'));
        self::assertFalse(CitationKey::isValid('No Body'));
    }
}
