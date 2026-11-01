<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\SourceAbstract;

final class SourceAbstractTest extends TestCase
{
    /** $n words of made-up prose */
    private static function words(int $n, string $word = 'nodule'): string
    {
        return rtrim(str_repeat($word . ' ', $n)) . '.';
    }

    /** A long article after the opening given */
    private static function article(string $opening): string
    {
        return $opening . "\n\n## Introduction\n\n" . self::words(400, 'intro') . "\n\n## Methods\n\n" . self::words(400, 'method')
            . "\n\n## Results\n\n" . self::words(400, 'result') . "\n\n## References\n\n```text\n1. Cited paper.\n```\n";
    }

    public function testAnAbstractMarkedByItsHeadingIsTakenUpToTheNextHeading(): void
    {
        $abstract = SourceAbstract::fromText(self::article("# A study\n\n## Abstract\n\n" . self::words(60) . "\n\n" . self::words(60, 'mass')));

        self::assertSame(self::words(60) . "\n\n" . self::words(60, 'mass'), $abstract);
    }

    public function testPageMarkersAreLeftOutAndRezumatOrSummaryIsAnAbstractToo(): void
    {
        $abstract = SourceAbstract::fromText(self::article("## Rezumat\n\n" . self::words(50) . "\n\n*— p. 2 —*\n\n" . self::words(50, 'masa')));
        self::assertSame(self::words(50) . "\n\n" . self::words(50, 'masa'), $abstract);

        self::assertNotNull(SourceAbstract::fromText(self::article("## Summary\n\n" . self::words(120))));
    }

    public function testAStructuredAbstractIsFollowedPartByPart(): void
    {
        $abstract = SourceAbstract::fromText(self::article("## Abstract\n\n## Purpose\n\n" . self::words(30, 'aim') . "\n\n## Methods\n\n" . self::words(40, 'how')
            . "\n\n## Results\n\n" . self::words(40, 'found') . "\n\n## Conclusion\n\n" . self::words(20, 'means')));

        self::assertSame('Purpose: ' . self::words(30, 'aim') . "\n\nMethods: " . self::words(40, 'how') . "\n\nResults: " . self::words(40, 'found') . "\n\nConclusion: " . self::words(20, 'means'), $abstract);
    }

    public function testAnAbstractLongEnoughOnItsOwnDoesNotTakeTheHeadingAfterIt(): void
    {
        $abstract = SourceAbstract::fromText("## Abstract\n\n" . self::words(100) . "\n\n## Introduction\n\n" . self::words(90, 'intro') . "\n\n## Methods\n\n" . self::words(800, 'method'));

        self::assertSame(self::words(100), $abstract);
    }

    public function testNoHeadingTooShortTooLongOrFoundLateIsNoAbstract(): void
    {
        self::assertNull(SourceAbstract::fromText(self::article(self::words(150))), 'no heading');
        self::assertNull(SourceAbstract::fromText(self::article("## Abstract\n\n" . self::words(40))), 'too short');
        self::assertNull(SourceAbstract::fromText(self::article("## Abstract\n\n" . self::words(700))), 'too long');
        self::assertNull(SourceAbstract::fromText(self::words(2000, 'intro') . "\n\n## Summary\n\n" . self::words(150)), 'a summary at the end is no abstract');
        self::assertNull(SourceAbstract::fromText(''));
    }
}
